<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Application\Command\EditShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentEffects;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConflictException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\ShipmentLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ShipmentJournal;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;

#[AsCommandHandler]
final class EditShipmentHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly ShipmentEffects $effects,
        private readonly ShipmentLock $lock,
        private readonly ShipmentJournal $journal,
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

            if (!$this->shipments->update($changed)) {
                throw new ShipmentException('The parcel could not be saved.');
            }

            if ($saved = $this->shipments->find($shipmentId)) {
                $this->journal->edited($current, $saved);
            }

            $this->effects->onShipmentSaved($shipmentId);
        });
    }
}
