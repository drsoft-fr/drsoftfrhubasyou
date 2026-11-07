<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DrSoftFr\Module\HubAsYou\Application\Command\SendShipmentMailCommand;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentEffects;
use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\ShipmentLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ShipmentJournal;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;

#[AsCommandHandler]
final class SendShipmentMailHandler
{
    public function __construct(
        private readonly ShipmentEffects $effects,
        private readonly ShipmentLock $lock,
        private readonly ShipmentJournal $journal,
    ) {
    }

    /**
     * With the order locked, as for a parcel: the state checked is the one when the e-mail leaves.
     */
    public function handle(SendShipmentMailCommand $command): void
    {
        $this->lock->synchronized($command->shipmentId->getValue(), function (Shipment $shipment): void {
            $this->effects->sendShipmentMailNow($shipment);
            $this->journal->shipmentMailSent($shipment);
        });
    }
}
