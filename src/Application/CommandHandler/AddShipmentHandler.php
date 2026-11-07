<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Application\Command\AddShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentEffects;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentQuantities;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentId;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\OrderLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ShipmentJournal;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;

#[AsCommandHandler]
final class AddShipmentHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly OrderRepository $orders,
        private readonly ShipmentEffects $effects,
        private readonly OrderLock $lock,
        private readonly ShipmentJournal $journal,
    ) {
    }

    /**
     * Checks, saves and runs the effects with the order locked: two parcels of the same
     * order are handled one after the other.
     */
    public function handle(AddShipmentCommand $command): ShipmentId
    {
        return $this->lock->synchronized($command->orderId, function () use ($command): ShipmentId {
            // Fast refusal; the unique key still decides between two orders saved at the same time
            if ($this->shipments->existsByExternalReference($command->externalReference)) {
                throw ShipmentConstraintException::duplicateReference($command->externalReference);
            }

            $orderLines = $this->orders->findLines($command->orderId)
                ?? throw new ShipmentConstraintException(sprintf('Order %d does not exist.', $command->orderId), ShipmentConstraintException::UNKNOWN_ORDER, parameters: ['order' => $command->orderId]);

            ShipmentQuantities::assertFit(
                $command->orderId,
                $orderLines,
                $this->shipments->getShippedQuantities($command->orderId),
                $command->lines
            );

            $shipmentId = $this->shipments->add(Shipment::declare(
                $command->externalReference,
                $command->orderId,
                $command->carrierName,
                $command->trackingNumber,
                $command->trackingUrl,
                $command->status,
                $command->shippedAt,
                $command->deliveredAt,
                $command->lines,
                new DateTimeImmutable()
            ));

            if ($shipment = $this->shipments->find($shipmentId)) {
                $this->journal->added($shipment);
            }

            $this->effects->onShipmentSaved($shipmentId);

            return new ShipmentId($shipmentId);
        });
    }
}
