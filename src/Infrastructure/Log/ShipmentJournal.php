<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Log;

use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\Context\ApiClientContext;
use PrestaShop\PrestaShop\Core\Context\EmployeeContext;
use PrestaShopLogger;
use Throwable;

/**
 * Who did what on a parcel, in the PrestaShop logs (Advanced parameters › Logs), attached
 * to the order. A failure here never refuses the action.
 *
 * Rows are those of ShipmentRepository::find(), with their lines.
 */
class ShipmentJournal
{
    private const MODULE_NAME = 'drsoftfrhubasyou';
    private const SEVERITY_INFORMATIVE = 1;

    public function __construct(
        private readonly ApiClientContext $apiClientContext,
        private readonly EmployeeContext $employeeContext,
    ) {
    }

    public function added(array $shipment): void
    {
        $this->write($shipment, 'added', $this->describe($shipment));
    }

    /**
     * Only the values that changed; nothing is written when none did.
     */
    public function edited(array $before, array $after): void
    {
        $changes = [];

        foreach (ShipmentRepository::VERSIONED_COLUMNS as $column) {
            if ((string) $before[$column] !== (string) $after[$column]) {
                $changes[] = sprintf('%s: "%s" -> "%s"', $column, $before[$column], $after[$column]);
            }
        }

        if ($changes) {
            $this->write($after, 'edited', implode(', ', $changes));
        }
    }

    public function deleted(array $shipment): void
    {
        $this->write($shipment, 'deleted', $this->describe($shipment));
    }

    /**
     * "Order delivered" e-mail sent at the merchant's request.
     */
    public function deliveredMailSent(int $orderId): void
    {
        $this->log($orderId, sprintf('%s - order #%d: "order delivered" e-mail sent by %s', self::MODULE_NAME, $orderId, $this->getActor()));
    }

    private function write(array $shipment, string $action, string $details): void
    {
        $orderId = (int) $shipment['id_order'];

        $this->log($orderId, sprintf(
            '%s - parcel #%d (%s) of order #%d %s by %s: %s',
            self::MODULE_NAME,
            (int) $shipment['id_shipment'],
            $shipment['external_reference'],
            $orderId,
            $action,
            $this->getActor(),
            $details
        ));
    }

    private function log(int $orderId, string $message): void
    {
        try {
            PrestaShopLogger::addLog(
                $message,
                self::SEVERITY_INFORMATIVE,
                null,
                'Order',
                $orderId,
                true,
                $this->employeeContext->getEmployee()?->getId()
            );
        } catch (Throwable) {
            // The parcel is saved: a missing log line must not turn it into an error
        }
    }

    private function getActor(): string
    {
        $apiClient = $this->apiClientContext->getApiClient();

        if ($apiClient) {
            return sprintf('API client "%s"', $apiClient->getClientId());
        }

        $employee = $this->employeeContext->getEmployee();

        return $employee ? sprintf('employee #%d', $employee->getId()) : 'unknown';
    }

    private function describe(array $shipment): string
    {
        $lines = array_map(
            static fn (array $line): string => sprintf('%d x line %d', (int) $line['quantity'], (int) $line['id_order_detail']),
            $shipment['lines']
        );

        return sprintf(
            'status "%s", carrier "%s", tracking "%s", %s',
            $shipment['status'],
            $shipment['carrier_name'],
            $shipment['tracking_number'],
            implode(', ', $lines)
        );
    }
}
