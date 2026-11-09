<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Application\Command\EditShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentEffects;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConflictException;
use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\ShipmentLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ShipmentJournal;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Context\RequestOrigin;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;

if (!defined('_PS_VERSION_')) {
    exit;
}

#[AsCommandHandler]
final class EditShipmentHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly ShipmentEffects $effects,
        private readonly ShipmentLock $lock,
        private readonly ShipmentJournal $journal,
        private readonly RequestOrigin $origin,
    ) {
    }

    /**
     * Saves and runs the effects with the order of the parcel locked (see AddShipmentHandler).
     */
    public function handle(EditShipmentCommand $command): void
    {
        $shipmentId = $command->shipmentId->getValue();

        $this->lock->synchronized($shipmentId, function (Shipment $current) use ($command, $shipmentId): void {
            $current->assertContentUnchanged($command->externalReference, $command->orderId, $command->lines);

            // Decided by who calls, never by a field of the request: the back office may correct
            if ($this->origin->isApiClient()) {
                $current->assertSenderMayChangeStatus($command->status);
            }

            // Optimistic check, under the lock so nothing can change between it and the save
            if ($command->expectedVersion !== null && $command->expectedVersion !== $current->version()) {
                throw ShipmentConflictException::forShipment($shipmentId);
            }

            $changed = $current->withChanges(
                $command->carrierName,
                $command->trackingNumber,
                $command->trackingUrl,
                $command->status,
                $command->shippedAt,
                $command->deliveredAt,
                new DateTimeImmutable()
            );

            $this->shipments->update($current, $changed, $this->journal);

            $this->effects->onShipmentSaved($shipmentId);
        });
    }
}
