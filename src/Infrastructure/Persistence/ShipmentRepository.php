<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Db;
use DbQuery;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentDates;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentLine;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use Throwable;

/**
 * Reads and writes the parcels and their lines.
 */
class ShipmentRepository
{
    public const TABLE = 'drsoft_fr_hubasyou_shipment';
    public const TABLE_LINE = 'drsoft_fr_hubasyou_shipment_line';

    /** Format of the DATETIME columns of the module */
    public const DATE_FORMAT = 'Y-m-d H:i:s';

    private const MYSQL_DUPLICATE_KEY = 1062;

    public function find(int $shipmentId): ?Shipment
    {
        $query = (new DbQuery())
            ->select('s.*')
            ->from(self::TABLE, 's')
            ->where('s.id_shipment = ' . $shipmentId);
        $row = DatabaseReader::row($query);

        if ($row === null) {
            return null;
        }

        return $this->hydrate([$row])[0];
    }

    public function findByExternalReference(string $externalReference): ?Shipment
    {
        $query = (new DbQuery())
            ->select('s.*')
            ->from(self::TABLE, 's')
            ->where("s.external_reference = '" . pSQL($externalReference, true) . "'");
        $row = DatabaseReader::row($query);

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /**
     * Parcels of an order, oldest first.
     *
     * @return Shipment[]
     */
    public function findByOrder(int $orderId): array
    {
        $query = (new DbQuery())
            ->select('s.*')
            ->from(self::TABLE, 's')
            ->where('s.id_order = ' . $orderId)
            ->orderBy('s.id_shipment ASC');

        return $this->hydrate(DatabaseReader::rows($query));
    }

    /**
     * Paginated list, newest first.
     *
     * @return array{0: Shipment[], 1: int} [parcels, total]
     */
    public function findPaginated(?int $orderId, ?string $externalReference, int $offset, int $limit): array
    {
        $where = ['1'];

        if ($orderId) {
            $where[] = 's.id_order = ' . $orderId;
        }

        if ($externalReference !== null && $externalReference !== '') {
            $where[] = "s.external_reference = '" . pSQL($externalReference, true) . "'";
        }

        $count = (int) DatabaseReader::value(
            (new DbQuery())->select('COUNT(*)')->from(self::TABLE, 's')->where(implode(' AND ', $where))
        );
        $query = (new DbQuery())
            ->select('s.*')
            ->from(self::TABLE, 's')
            ->where(implode(' AND ', $where))
            ->orderBy('s.id_shipment DESC')
            ->limit(max(1, $limit), max(0, $offset));

        return [$this->hydrate(DatabaseReader::rows($query)), $count];
    }

    /**
     * Inserts a new parcel and its lines, together (transaction).
     *
     * @return int The new id_shipment
     *
     * @throws ShipmentConstraintException External reference already used (unique key)
     * @throws ShipmentException Database error
     */
    public function add(Shipment $shipment): int
    {
        $db = Db::getInstance();
        $now = date(self::DATE_FORMAT);
        $db->execute('START TRANSACTION', false);

        try {
            $columns = [
                'external_reference' => $shipment->externalReference,
                'id_order' => $shipment->orderId,
            ] + $this->changeableColumns($shipment);

            if (!$db->insert(self::TABLE, $this->escape($columns) + ['date_add' => $now, 'date_upd' => $now], true)) {
                throw new ShipmentException('The parcel could not be saved.');
            }

            $shipmentId = (int) $db->Insert_ID();
            $products = $this->findProducts($shipment->lines);
            $rows = [];

            foreach ($shipment->lines as $line) {
                $rows[] = [
                    'id_shipment' => $shipmentId,
                    'id_order_detail' => $line->orderDetailId,
                    'quantity' => $line->quantity,
                    'product_name' => pSQL((string) ($products[$line->orderDetailId]['product_name'] ?? ''), true),
                    'product_reference' => pSQL((string) ($products[$line->orderDetailId]['product_reference'] ?? ''), true),
                ];
            }

            if (!$db->insert(self::TABLE_LINE, $rows)) {
                throw new ShipmentException('The lines of the parcel could not be saved.');
            }

            $db->execute('COMMIT', false);

            return $shipmentId;
        } catch (Throwable $t) {
            // In debug mode PrestaShop throws on a database error, otherwise insert() returns false
            $duplicate = (int) $db->getNumberError() === self::MYSQL_DUPLICATE_KEY;
            $db->execute('ROLLBACK', false);

            if ($duplicate) {
                throw ShipmentConstraintException::duplicateReference($shipment->externalReference);
            }

            throw $t instanceof ShipmentException ? $t : new ShipmentException('The parcel could not be saved.', 0, $t);
        }
    }

    /**
     * Saves the tracking, state and dates of a parcel.
     */
    public function update(Shipment $shipment): bool
    {
        return Db::getInstance()->update(
            self::TABLE,
            $this->escape($this->changeableColumns($shipment)) + ['date_upd' => date(self::DATE_FORMAT)],
            'id_shipment = ' . $shipment->id,
            0,
            true
        );
    }

    /**
     * Deletes a parcel and its lines, together (transaction).
     *
     * @throws ShipmentException Database error
     */
    public function delete(int $shipmentId): void
    {
        $db = Db::getInstance();
        $db->execute('START TRANSACTION', false);

        try {
            if (!$db->delete(self::TABLE_LINE, 'id_shipment = ' . $shipmentId)
                || !$db->delete(self::TABLE, 'id_shipment = ' . $shipmentId)
            ) {
                throw new ShipmentException('The parcel could not be deleted.');
            }

            $db->execute('COMMIT', false);
        } catch (Throwable $t) {
            $db->execute('ROLLBACK', false);

            throw $t instanceof ShipmentException ? $t : new ShipmentException('The parcel could not be deleted.', 0, $t);
        }
    }

    /**
     * Reserves the "parcel on its way" e-mail of a parcel: true for the one request
     * that sets the date, false if it was already set (sent or being sent).
     */
    public function claimMail(int $shipmentId): bool
    {
        $db = Db::getInstance();

        return $db->update(self::TABLE, ['mail_sent_at' => date(self::DATE_FORMAT)], 'id_shipment = ' . $shipmentId . ' AND mail_sent_at IS NULL')
            && (int) $db->Affected_Rows() === 1;
    }

    /**
     * Gives the reservation back when the e-mail could not be sent: tried again at the next save.
     */
    public function releaseMail(int $shipmentId): void
    {
        Db::getInstance()->update(self::TABLE, ['mail_sent_at' => null], 'id_shipment = ' . $shipmentId, 0, true);
    }

    /**
     * Date of the "parcel on its way" e-mail sent at the merchant's request.
     */
    public function saveMailDate(int $shipmentId): void
    {
        Db::getInstance()->update(self::TABLE, ['mail_sent_at' => date(self::DATE_FORMAT)], 'id_shipment = ' . $shipmentId);
    }

    /**
     * Quantity of each order line put in parcels.
     *
     * @param bool $deliveredOnly Count only the delivered parcels
     *
     * @return array<int, int> id_order_detail => quantity
     */
    public function getShippedQuantities(int $orderId, bool $deliveredOnly = false): array
    {
        $query = (new DbQuery())
            ->select('l.id_order_detail, SUM(l.quantity) AS quantity')
            ->from(self::TABLE_LINE, 'l')
            ->innerJoin(self::TABLE, 's', 's.id_shipment = l.id_shipment')
            ->where('s.id_order = ' . $orderId)
            ->groupBy('l.id_order_detail');

        if ($deliveredOnly) {
            $query->where("s.status = '" . ShipmentStatus::Delivered->value . "'");
        }

        $quantities = [];

        foreach (DatabaseReader::rows($query) as $row) {
            $quantities[(int) $row['id_order_detail']] = (int) $row['quantity'];
        }

        return $quantities;
    }

    /**
     * Product of each order line of a parcel, as PrestaShop recorded it on the order.
     *
     * @param ShipmentLine[] $lines
     *
     * @return array<int, array{product_name: string, product_reference: string}> by id_order_detail
     */
    private function findProducts(array $lines): array
    {
        $ids = array_map(static fn (ShipmentLine $line): int => $line->orderDetailId, $lines);
        $query = (new DbQuery())
            ->select('id_order_detail, product_name, product_reference')
            ->from('order_detail')
            ->where('id_order_detail IN (' . implode(',', $ids) . ')');
        $products = [];

        foreach (DatabaseReader::rows($query) as $row) {
            $products[(int) $row['id_order_detail']] = $row;
        }

        return $products;
    }

    /**
     * Parcels of the rows, with their lines.
     *
     * @return Shipment[]
     */
    private function hydrate(array $rows): array
    {
        if (!$rows) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id_shipment'], $rows);
        $query = (new DbQuery())
            ->select('l.id_shipment, l.id_order_detail, l.quantity, l.product_name, l.product_reference')
            ->from(self::TABLE_LINE, 'l')
            ->where('l.id_shipment IN (' . implode(',', $ids) . ')')
            ->orderBy('l.id_shipment_line ASC');
        $lines = [];

        foreach (DatabaseReader::rows($query) as $line) {
            $lines[(int) $line['id_shipment']][] = new ShipmentLine(
                (int) $line['id_order_detail'],
                (int) $line['quantity'],
                (string) $line['product_name'],
                (string) $line['product_reference'],
            );
        }

        return array_map(static fn (array $row): Shipment => new Shipment(
            (int) $row['id_shipment'],
            (string) $row['external_reference'],
            (int) $row['id_order'],
            (string) $row['carrier_name'],
            (string) $row['tracking_number'],
            $row['tracking_url'] !== null && $row['tracking_url'] !== '' ? (string) $row['tracking_url'] : null,
            ShipmentStatus::from((string) $row['status']),
            ShipmentDates::fromSaved($row['shipped_at']),
            ShipmentDates::fromSaved($row['delivered_at']),
            ShipmentDates::fromSaved($row['mail_sent_at']),
            $lines[(int) $row['id_shipment']] ?? [],
        ), $rows);
    }

    /**
     * @return array<string, mixed> Column => value
     */
    private function changeableColumns(Shipment $shipment): array
    {
        return [
            'carrier_name' => $shipment->carrierName,
            'tracking_number' => $shipment->trackingNumber,
            'tracking_url' => $shipment->trackingUrl,
            'status' => $shipment->status->value,
            'shipped_at' => $shipment->shippedAt,
            'delivered_at' => $shipment->deliveredAt,
        ];
    }

    /**
     * DATETIME columns hold the shop's local time (as PrestaShop's own tables): a date
     * received with another offset ("…Z", "…-05:00") is converted first. PrestaShop sets
     * PHP's default time zone to the shop's (PS_TIMEZONE) when it starts.
     */
    private function toShopTime(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format(self::DATE_FORMAT);
    }

    private function escape(array $data): array
    {
        $escaped = [];

        foreach ($data as $column => $value) {
            if ($value instanceof DateTimeInterface) {
                $value = $this->toShopTime($value);
            }

            // Saved as received (SQL-escaped only): the templates escape it for HTML
            $escaped[$column] = is_string($value) ? pSQL($value, true) : $value;
        }

        return $escaped;
    }
}
