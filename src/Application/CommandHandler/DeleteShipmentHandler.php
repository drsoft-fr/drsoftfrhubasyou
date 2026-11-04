<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DrSoftFr\Module\HubAsYou\Application\Command\DeleteShipmentCommand;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentNotFoundException;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\OrderLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;

#[AsCommandHandler]
final class DeleteShipmentHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly OrderLock $lock,
    ) {
    }

    /**
     * Deletes with the order of the parcel locked: the units it frees are counted
     * right by a parcel of the same order declared at the same time.
     */
    public function handle(DeleteShipmentCommand $command): void
    {
        $shipmentId = $command->shipmentId->getValue();
        $orderId = (int) ($this->shipments->find($shipmentId) ?? throw ShipmentNotFoundException::withId($shipmentId))['id_order'];

        $this->lock->synchronized($orderId, function () use ($shipmentId): void {
            if (!$this->shipments->find($shipmentId)) {
                throw ShipmentNotFoundException::withId($shipmentId);
            }

            $this->shipments->delete($shipmentId);
        });
    }
}
