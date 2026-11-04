<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Persistence;

use Db;
use DbQuery;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use Throwable;

/**
 * Reads and writes the parcels and their lines.
 *
 * Rows are returned with the table column names (id_shipment, id_order…) and the
 * lines as [['id_order_detail' => int, 'quantity' => int, …], …].
 */
class ShipmentRepository
{
    public const TABLE = 'drsoft_fr_hubasyou_shipment';
    public const TABLE_LINE = 'drsoft_fr_hubasyou_shipment_line';

    private const DATE_FORMAT = 'Y-m-d H:i:s';
    private const MYSQL_DUPLICATE_KEY = 1062;

    public function find(int $shipmentId): ?array
    {
        $query = (new DbQuery())
            ->select('s.*')
            ->from(self::TABLE, 's')
            ->where('s.id_shipment = ' . $shipmentId);
        $row = Db::getInstance()->getRow($query);

        if (!$row) {
            return null;
        }

        return $this->withLines([$row])[0];
    }

    /**
     * Version of a parcel: fingerprint of the values a change can overwrite (tracking,
     * state, dates). Unlike date_upd (precise to the second), it changes with any
     * change of these values.
     *
     * @param array $row Row of find()
     */
    public static function version(array $row): string
    {
        return sha1(json_encode([
            $row['carrier_name'],
            $row['tracking_number'],
            $row['tracking_url'],
            $row['status'],
            $row['shipped_at'],
            $row['delivered_at'],
        ]));
    }

    public function existsByExternalReference(string $externalReference): bool
    {
        $query = (new DbQuery())
            ->select('1')
            ->from(self::TABLE)
            ->where("external_reference = '" . pSQL($externalReference) . "'");

        return (bool) Db::getInstance()->getValue($query);
    }

    /**
     * Parcels of an order, oldest first.
     */
    public function findByOrder(int $orderId): array
    {
        $query = (new DbQuery())
            ->select('s.*')
            ->from(self::TABLE, 's')
            ->where('s.id_order = ' . $orderId)
            ->orderBy('s.id_shipment ASC');

        return $this->withLines(Db::getInstance()->executeS($query) ?: []);
    }

    /**
     * Paginated list, newest first.
     *
     * @return array{0: array, 1: int} [rows, total]
     */
    public function findPaginated(?int $orderId, ?string $externalReference, int $offset, int $limit): array
    {
        $where = ['1'];

        if ($orderId) {
            $where[] = 's.id_order = ' . $orderId;
        }

        if ($externalReference !== null && $externalReference !== '') {
            $where[] = "s.external_reference = '" . pSQL($externalReference) . "'";
        }

        $db = Db::getInstance();
        $count = (int) $db->getValue(
            (new DbQuery())->select('COUNT(*)')->from(self::TABLE, 's')->where(implode(' AND ', $where))
        );
        $query = (new DbQuery())
            ->select('s.*')
            ->from(self::TABLE, 's')
            ->where(implode(' AND ', $where))
            ->orderBy('s.id_shipment DESC')
            ->limit(max(1, $limit), max(0, $offset));

        return [$this->withLines($db->executeS($query) ?: []), $count];
    }

    /**
     * Inserts a parcel and its lines, together (transaction).
     *
     * @param array $data Column => value (dates as DateTimeInterface or null)
     * @param array<int, int> $lines id_order_detail => quantity
     *
     * @return int The new id_shipment
     *
     * @throws ShipmentConstraintException External reference already used (unique key)
     * @throws ShipmentException Database error
     */
    public function add(array $data, array $lines): int
    {
        $db = Db::getInstance();
        $now = date(self::DATE_FORMAT);
        $db->execute('START TRANSACTION', false);

        try {
            if (!$db->insert(self::TABLE, $this->escape($data) + ['date_add' => $now, 'date_upd' => $now], true)) {
                throw new ShipmentException('The parcel could not be saved.');
            }

            $shipmentId = (int) $db->Insert_ID();
            $rows = [];

            foreach ($lines as $orderDetailId => $quantity) {
                $rows[] = [
                    'id_shipment' => $shipmentId,
                    'id_order_detail' => (int) $orderDetailId,
                    'quantity' => (int) $quantity,
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
                throw ShipmentConstraintException::duplicateReference((string) $data['external_reference']);
            }

            throw $t instanceof ShipmentException ? $t : new ShipmentException('The parcel could not be saved.', 0, $t);
        }
    }

    /**
     * @param array $data Column => value (dates as DateTimeInterface or null)
     */
    public function update(int $shipmentId, array $data): bool
    {
        if (!$data) {
            return true;
        }

        return Db::getInstance()->update(
            self::TABLE,
            $this->escape($data) + ['date_upd' => date(self::DATE_FORMAT)],
            'id_shipment = ' . $shipmentId,
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

        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $quantities[(int) $row['id_order_detail']] = (int) $row['quantity'];
        }

        return $quantities;
    }

    /**
     * Adds the lines (with the product name of the order line) to each parcel row.
     */
    private function withLines(array $rows): array
    {
        if (!$rows) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id_shipment'], $rows);
        $query = (new DbQuery())
            ->select('l.id_shipment, l.id_order_detail, l.quantity, od.product_name, od.product_reference')
            ->from(self::TABLE_LINE, 'l')
            ->leftJoin('order_detail', 'od', 'od.id_order_detail = l.id_order_detail')
            ->where('l.id_shipment IN (' . implode(',', $ids) . ')')
            ->orderBy('l.id_shipment_line ASC');
        $lines = [];

        foreach (Db::getInstance()->executeS($query) ?: [] as $line) {
            $lines[(int) $line['id_shipment']][] = [
                'id_order_detail' => (int) $line['id_order_detail'],
                'quantity' => (int) $line['quantity'],
                'product_name' => (string) $line['product_name'],
                'product_reference' => (string) $line['product_reference'],
            ];
        }

        foreach ($rows as &$row) {
            $row['lines'] = $lines[(int) $row['id_shipment']] ?? [];
        }

        return $rows;
    }

    private function escape(array $data): array
    {
        $escaped = [];

        foreach ($data as $column => $value) {
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(self::DATE_FORMAT);
            }

            $escaped[$column] = is_string($value) ? pSQL($value) : $value;
        }

        return $escaped;
    }
}
