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
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ShipmentJournal;
use RuntimeException;

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
     * Inserts a new parcel, its lines and its journal line, together (transaction).
     *
     * @return int The new id_shipment
     *
     * @throws ShipmentConstraintException External reference already used (unique key)
     * @throws ShipmentException Database error
     */
    public function add(Shipment $shipment, ShipmentJournal $journal): int
    {
        return DatabaseError::transaction('The parcel could not be saved.', function (Db $db) use ($shipment, $journal): int {
            $now = date(self::DATE_FORMAT);
            $columns = [
                'external_reference' => $shipment->externalReference,
                'id_order' => $shipment->orderId,
            ] + $this->changeableColumns($shipment);

            if (!$db->insert(self::TABLE, $this->escape($columns) + ['date_add' => $now, 'date_upd' => $now], true)) {
                throw new RuntimeException('The parcel row was not inserted.');
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
                throw new RuntimeException('The lines of the parcel were not inserted.');
            }

            // As saved: same connection, the transaction sees its own rows
            $journal->added($this->find($shipmentId) ?? throw new RuntimeException('The parcel saved could not be read again.'));

            return $shipmentId;
        }, static fn (Db $db): ?ShipmentException => (int) $db->getNumberError() === self::MYSQL_DUPLICATE_KEY
            ? ShipmentConstraintException::duplicateReference($shipment->externalReference)
            : null);
    }

    /**
     * Saves the tracking, state and dates of a parcel, and the journal line of what changed,
     * together (transaction).
     *
     * @param Shipment $before The parcel as it was read before the change
     *
     * @throws ShipmentException Database error
     */
    public function update(Shipment $before, Shipment $shipment, ShipmentJournal $journal): void
    {
        DatabaseError::transaction('The parcel could not be saved.', function (Db $db) use ($before, $shipment, $journal): void {
            if (!$db->update(
                self::TABLE,
                $this->escape($this->changeableColumns($shipment)) + ['date_upd' => date(self::DATE_FORMAT)],
                'id_shipment = ' . $shipment->id,
                0,
                true
            )) {
                throw new RuntimeException('The parcel row was not updated.');
            }

            // As saved (dates in the shop's time): only what really changed is written
            $journal->edited($before, $this->find($shipment->id) ?? throw new RuntimeException('The parcel saved could not be read again.'));
        });
    }

    /**
     * Deletes a parcel and its lines, and writes its journal line with its content,
     * together (transaction).
     *
     * @throws ShipmentException Database error
     */
    public function delete(Shipment $shipment, ShipmentJournal $journal): void
    {
        DatabaseError::transaction('The parcel could not be deleted.', static function (Db $db) use ($shipment, $journal): void {
            if (!$db->delete(self::TABLE_LINE, 'id_shipment = ' . $shipment->id)
                || !$db->delete(self::TABLE, 'id_shipment = ' . $shipment->id)
            ) {
                throw new RuntimeException('The parcel rows were not deleted.');
            }

            $journal->deleted($shipment);
        });
    }

    /**
     * Reserves the "parcel on its way" e-mail of a parcel: true for the one request
     * that sets the date, false if it was already set (sent or being sent).
     *
     * @throws ShipmentException Database error: not mistaken for "already sent"
     */
    public function claimMail(int $shipmentId): bool
    {
        DatabaseError::write('The "parcel on its way" e-mail could not be reserved.', static fn (Db $db): bool => $db->update(
            self::TABLE,
            ['mail_sent_at' => date(self::DATE_FORMAT)],
            'id_shipment = ' . $shipmentId . ' AND mail_sent_at IS NULL'
        ));

        return (int) Db::getInstance()->Affected_Rows() === 1;
    }

    /**
     * Gives the reservation back when the e-mail could not be sent: tried again at the next save.
     *
     * @throws ShipmentException Database error
     */
    public function releaseMail(int $shipmentId): void
    {
        DatabaseError::write('The reservation of the "parcel on its way" e-mail could not be given back.', static fn (Db $db): bool => $db->update(
            self::TABLE,
            ['mail_sent_at' => null],
            'id_shipment = ' . $shipmentId,
            0,
            true
        ));
    }

    /**
     * Date of the "parcel on its way" e-mail sent at the merchant's request.
     *
     * @throws ShipmentException Database error
     */
    public function saveMailDate(int $shipmentId): void
    {
        DatabaseError::write('The date of the "parcel on its way" e-mail could not be saved.', static fn (Db $db): bool => $db->update(
            self::TABLE,
            ['mail_sent_at' => date(self::DATE_FORMAT)],
            'id_shipment = ' . $shipmentId
        ));
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
