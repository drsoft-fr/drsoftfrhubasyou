<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DrSoftFr\Module\HubAsYou\Application\Command\SendShipmentMailCommand;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentEffects;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentNotFoundException;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\OrderLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ShipmentJournal;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;

#[AsCommandHandler]
final class SendShipmentMailHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly ShipmentEffects $effects,
        private readonly OrderLock $lock,
        private readonly ShipmentJournal $journal,
    ) {
    }

    /**
     * With the order locked, as for a parcel: the state checked is the one when the e-mail leaves.
     */
    public function handle(SendShipmentMailCommand $command): void
    {
        $shipmentId = $command->shipmentId;
        $orderId = (int) ($this->shipments->find($shipmentId) ?? throw ShipmentNotFoundException::withId($shipmentId))['id_order'];

        $this->lock->synchronized($orderId, function () use ($shipmentId): void {
            // Read again under the lock: the parcel may have changed or been deleted meanwhile
            $shipment = $this->shipments->find($shipmentId) ?? throw ShipmentNotFoundException::withId($shipmentId);

            $this->effects->sendShipmentMailNow($shipment);
            $this->journal->shipmentMailSent($shipment);
        });
    }
}
