<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DrSoftFr\Module\HubAsYou\Application\Command\DeleteShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentEffects;
use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\ShipmentLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ShipmentJournal;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;

#[AsCommandHandler]
final class DeleteShipmentHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly ShipmentEffects $effects,
        private readonly ShipmentLock $lock,
        private readonly ShipmentJournal $journal,
    ) {
    }

    /**
     * Deletes with the order of the parcel locked: the units it frees are counted
     * right by a parcel of the same order declared at the same time. The order is then
     * worked out again: without this parcel, it may be entirely delivered (a lost parcel,
     * refunded, was the last one it was waiting for).
     */
    public function handle(DeleteShipmentCommand $command): void
    {
        $this->lock->synchronized($command->shipmentId->getValue(), function (Shipment $shipment): void {
            $this->shipments->delete($shipment, $this->journal);
            $this->effects->onOrderChanged($shipment->orderId);
        });
    }
}
