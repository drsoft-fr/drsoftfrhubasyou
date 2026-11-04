<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Application\Command\EditShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentEffects;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentNotFoundException;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentDates;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\OrderLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;

#[AsCommandHandler]
final class EditShipmentHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly ShipmentEffects $effects,
        private readonly OrderLock $lock,
    ) {
    }

    /**
     * Saves and runs the effects with the order of the parcel locked (see AddShipmentHandler).
     */
    public function handle(EditShipmentCommand $command): void
    {
        $shipmentId = $command->shipmentId->getValue();
        $orderId = (int) ($this->shipments->find($shipmentId) ?? throw ShipmentNotFoundException::withId($shipmentId))['id_order'];

        $this->lock->synchronized($orderId, function () use ($command, $shipmentId): void {
            // Read again under the lock: the parcel may have changed or been deleted meanwhile
            $current = $this->shipments->find($shipmentId) ?? throw ShipmentNotFoundException::withId($shipmentId);

            $data = array_filter([
                'carrier_name' => $command->carrierName,
                'tracking_number' => $command->trackingNumber,
                'tracking_url' => $command->trackingUrl,
                'status' => $command->status?->value,
            ], static fn ($value): bool => $value !== null);

            // An empty URL removes the tracking page
            if ($command->trackingUrl === '') {
                $data['tracking_url'] = null;
            }

            // Dates sent, otherwise the saved ones, completed for the state of the parcel
            $date = static fn (?string $value): ?DateTimeImmutable => $value ? new DateTimeImmutable($value) : null;

            [$data['shipped_at'], $data['delivered_at']] = ShipmentDates::complete(
                $command->status ?? ShipmentStatus::from((string) $current['status']),
                $command->shippedAt ?? $date($current['shipped_at']),
                $command->deliveredAt ?? $date($current['delivered_at']),
                new DateTimeImmutable()
            );

            if (!$this->shipments->update($shipmentId, $data)) {
                throw new ShipmentException('The parcel could not be saved.');
            }

            $this->effects->onShipmentSaved($shipmentId);
        });
    }
}
