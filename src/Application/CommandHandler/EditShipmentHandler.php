<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Application\Command\EditShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentNotifier;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentNotFoundException;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;

#[AsCommandHandler]
final class EditShipmentHandler
{
    public function __construct(
        private readonly ShipmentRepository $repository,
        private readonly ShipmentNotifier $notifier,
    ) {
    }

    public function handle(EditShipmentCommand $command): void
    {
        $shipmentId = $command->shipmentId->getValue();
        $current = $this->repository->find($shipmentId);

        if (!$current) {
            throw new ShipmentNotFoundException(sprintf('Parcel %d not found.', $shipmentId));
        }

        $data = array_filter([
            'carrier_name' => $command->carrierName,
            'tracking_number' => $command->trackingNumber,
            'tracking_url' => $command->trackingUrl,
            'status' => $command->status?->value,
            'shipped_at' => $command->shippedAt,
            'delivered_at' => $command->deliveredAt,
        ], static fn ($value): bool => $value !== null);

        // An empty URL removes the tracking page
        if ($command->trackingUrl === '') {
            $data['tracking_url'] = null;
        }

        // Default dates when the parcel reaches a state without a date sent
        $now = new DateTimeImmutable();

        if ($command->status?->isOnItsWay() && empty($current['shipped_at']) && !isset($data['shipped_at'])) {
            $data['shipped_at'] = $now;
        }

        if ($command->status === ShipmentStatus::Delivered && empty($current['delivered_at']) && !isset($data['delivered_at'])) {
            $data['delivered_at'] = $now;
        }

        if (!$this->repository->update($shipmentId, $data)) {
            throw new ShipmentException('The parcel could not be saved.');
        }

        $this->notifier->onShipmentSaved($shipmentId);
    }
}
