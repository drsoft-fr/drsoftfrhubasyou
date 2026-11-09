<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Log;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentLine;
use DrSoftFr\Module\HubAsYou\Infrastructure\Configuration\Settings;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentLogRepository;
use PrestaShop\PrestaShop\Core\Context\ApiClientContext;
use PrestaShop\PrestaShop\Core\Context\EmployeeContext;
use PrestaShopLogger;
use Throwable;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Who did what on a parcel: in the module's own journal (shown on the order page, kept
 * whatever the settings of PrestaShop's logs), and in PrestaShop's logs (Advanced
 * parameters › Logs) as before, when their minimum level allows it.
 *
 * added(), edited() and deleted() are called by ShipmentRepository, which requires the journal
 * for every write, inside the transaction of the change: if the journal line cannot be
 * written, the change is not saved either. A
 * parcel never changes without its line. The other lines describe something already done
 * (an e-mail sent): a failure is logged, never turned into an error.
 */
class ShipmentJournal
{
    private const SEVERITY_INFORMATIVE = 1;

    public function __construct(
        private readonly ApiClientContext $apiClientContext,
        private readonly EmployeeContext $employeeContext,
        private readonly ShipmentLogRepository $journal,
    ) {
    }

    /**
     * @throws ShipmentException The line could not be written: the parcel is not saved
     */
    public function added(Shipment $shipment): void
    {
        $this->write($shipment, ShipmentLogRepository::ADDED, $this->snapshot($shipment), $this->describe($shipment));
    }

    /**
     * Only the values that changed; nothing is written when none did.
     *
     * @throws ShipmentException The line could not be written: the change is not saved
     */
    public function edited(Shipment $before, Shipment $after): void
    {
        $changes = [];
        $texts = [];
        $newValues = $after->changeableValues();

        foreach ($before->changeableValues() as $name => $oldValue) {
            if ((string) $oldValue !== (string) $newValues[$name]) {
                $changes[$name] = [$oldValue, $newValues[$name]];
                $texts[] = sprintf('%s: "%s" -> "%s"', $name, $oldValue, $newValues[$name]);
            }
        }

        if ($changes) {
            $this->write($after, ShipmentLogRepository::EDITED, ['changes' => $changes], implode(', ', $texts));
        }
    }

    /**
     * @throws ShipmentException The line could not be written: the parcel is not deleted
     */
    public function deleted(Shipment $shipment): void
    {
        $this->write($shipment, ShipmentLogRepository::DELETED, $this->snapshot($shipment), $this->describe($shipment));
    }

    /**
     * The same parcel received again: nothing changes, the effects are run again.
     */
    public function declaredAgain(Shipment $shipment): void
    {
        $this->writeDone(fn () => $this->write($shipment, ShipmentLogRepository::RECEIVED_AGAIN, [], $this->describe($shipment)));
    }

    /**
     * "Order delivered" e-mail sent at the merchant's request.
     */
    public function deliveredMailSent(int $orderId): void
    {
        $this->writeDone(function () use ($orderId): void {
            $this->journal->add($orderId, null, null, ShipmentLogRepository::DELIVERED_MAIL_SENT, $this->getApiClient(), $this->getEmployeeId(), []);
            $this->log($orderId, sprintf('%s - order #%d: "order delivered" e-mail sent by %s', Settings::MODULE_NAME, $orderId, $this->getActor()));
        });
    }

    /**
     * "Parcel on its way" e-mail sent at the merchant's request.
     */
    public function shipmentMailSent(Shipment $shipment): void
    {
        $this->writeDone(function () use ($shipment): void {
            $this->journal->add($shipment->orderId, $shipment->id, $shipment->externalReference, ShipmentLogRepository::SHIPMENT_MAIL_SENT, $this->getApiClient(), $this->getEmployeeId(), []);
            $this->log($shipment->orderId, sprintf(
                '%s - parcel #%d (%s) of order #%d: "parcel on its way" e-mail sent by %s',
                Settings::MODULE_NAME,
                $shipment->id,
                $shipment->externalReference,
                $shipment->orderId,
                $this->getActor()
            ));
        });
    }

    /**
     * @param array<string, mixed> $details
     *
     * @throws ShipmentException
     */
    private function write(Shipment $shipment, string $action, array $details, string $text): void
    {
        $this->journal->add($shipment->orderId, $shipment->id, $shipment->externalReference, $action, $this->getApiClient(), $this->getEmployeeId(), $details);
        $this->log($shipment->orderId, sprintf(
            '%s - parcel #%d (%s) of order #%d %s by %s: %s',
            Settings::MODULE_NAME,
            $shipment->id,
            $shipment->externalReference,
            $shipment->orderId,
            str_replace('_', ' ', $action),
            $this->getActor(),
            $text
        ));
    }

    /**
     * A line about something already done: its failure is in the logs (DatabaseError), the
     * action stays done.
     */
    private function writeDone(callable $write): void
    {
        try {
            $write();
        } catch (ShipmentException) {
            // Already logged where the database failed
        }
    }

    /**
     * PrestaShop's logs: kept only if their minimum level allows it, a failure is ignored.
     */
    private function log(int $orderId, string $message): void
    {
        try {
            PrestaShopLogger::addLog($message, self::SEVERITY_INFORMATIVE, null, 'Order', $orderId, true, $this->getEmployeeId());
        } catch (Throwable) {
            // The module's own journal has the line
        }
    }

    private function getApiClient(): ?string
    {
        return $this->apiClientContext->getApiClient()?->getClientId();
    }

    private function getEmployeeId(): ?int
    {
        // An API client acts alone, even if PrestaShop knows an employee in the same process
        return $this->getApiClient() === null ? $this->employeeContext->getEmployee()?->getId() : null;
    }

    private function getActor(): string
    {
        $apiClient = $this->getApiClient();

        if ($apiClient !== null) {
            return sprintf('API client "%s"', $apiClient);
        }

        $employeeId = $this->getEmployeeId();

        return $employeeId ? sprintf('employee #%d', $employeeId) : 'unknown';
    }

    /**
     * The whole parcel, as it is (added) or was (deleted).
     *
     * @return array<string, mixed>
     */
    private function snapshot(Shipment $shipment): array
    {
        return $shipment->changeableValues() + [
            'lines' => array_map(static fn (ShipmentLine $line): array => [
                'id_order_detail' => $line->orderDetailId,
                'quantity' => $line->quantity,
                'product_name' => $line->productName,
                'product_reference' => $line->productReference,
            ], $shipment->lines),
        ];
    }

    private function describe(Shipment $shipment): string
    {
        $lines = array_map(
            static fn (ShipmentLine $line): string => sprintf('%d x line %d', $line->quantity, $line->orderDetailId),
            $shipment->lines
        );

        return sprintf(
            'status "%s", carrier "%s", tracking "%s", %s',
            $shipment->status->value,
            $shipment->carrierName,
            $shipment->trackingNumber,
            implode(', ', $lines)
        );
    }
}
