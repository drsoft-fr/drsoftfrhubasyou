<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Persistence;

use Db;
use DbQuery;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;

/**
 * The module's own journal of the parcels: one row per action, kept whatever the settings of
 * PrestaShop's logs, never emptied by the module. A row is never changed once written.
 */
class ShipmentLogRepository
{
    public const TABLE = 'drsoft_fr_hubasyou_shipment_log';

    public const ADDED = 'added';
    public const RECEIVED_AGAIN = 'received_again';
    public const EDITED = 'edited';
    public const DELETED = 'deleted';
    public const SHIPMENT_MAIL_SENT = 'shipment_mail_sent';
    public const DELIVERED_MAIL_SENT = 'delivered_mail_sent';

    /**
     * @param array<string, mixed> $details Saved as JSON
     *
     * @throws ShipmentException Database error
     */
    public function add(
        int $orderId,
        ?int $shipmentId,
        ?string $externalReference,
        string $action,
        ?string $apiClient,
        ?int $employeeId,
        array $details,
    ): void {
        $row = [
            'id_order' => $orderId,
            'id_shipment' => $shipmentId,
            'external_reference' => $externalReference === null ? null : pSQL($externalReference, true),
            'action' => pSQL($action),
            'api_client' => $apiClient === null ? null : pSQL($apiClient, true),
            'id_employee' => $employeeId,
            'details' => pSQL((string) json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), true),
            'date_add' => date(ShipmentRepository::DATE_FORMAT),
        ];

        DatabaseError::write('The journal line of the parcel could not be saved.', static fn (Db $db): bool => $db->insert(self::TABLE, $row, true));
    }

    /**
     * Journal of an order, newest first, deleted parcels included, with the name of the
     * employee when the employee still exists.
     *
     * @return array<int, array<string, mixed>> Rows, details decoded
     */
    public function findByOrder(int $orderId): array
    {
        $query = (new DbQuery())
            ->select('j.*, e.firstname, e.lastname')
            ->from(self::TABLE, 'j')
            ->leftJoin('employee', 'e', 'e.id_employee = j.id_employee')
            ->where('j.id_order = ' . $orderId)
            ->orderBy('j.id_shipment_log DESC');

        return array_map(static function (array $row): array {
            $details = json_decode((string) $row['details'], true);
            $row['details'] = is_array($details) ? $details : [];

            return $row;
        }, DatabaseReader::rows($query));
    }
}
