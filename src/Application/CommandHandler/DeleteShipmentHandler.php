<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DrSoftFr\Module\HubAsYou\Application\Command\DeleteShipmentCommand;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\ShipmentLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ShipmentJournal;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;

#[AsCommandHandler]
final class DeleteShipmentHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly ShipmentLock $lock,
        private readonly ShipmentJournal $journal,
    ) {
    }

    /**
     * Deletes with the order of the parcel locked: the units it frees are counted
     * right by a parcel of the same order declared at the same time.
     */
    public function handle(DeleteShipmentCommand $command): void
    {
        $shipmentId = $command->shipmentId->getValue();

        $this->lock->synchronized($shipmentId, function (array $shipment) use ($shipmentId): void {
            $this->shipments->delete($shipmentId);
            $this->journal->deleted($shipment);
        });
    }
}
