<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Application\Command\EditShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentEffects;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConflictException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentDates;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentRules;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
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

        $this->lock->synchronized($shipmentId, function (array $current) use ($command, $shipmentId): void {
            ShipmentRules::assertContentUnchanged(
                $command->externalReference,
                $command->orderId,
                $command->lines,
                (string) $current['external_reference'],
                (int) $current['id_order'],
                array_column($current['lines'], 'quantity', 'id_order_detail')
            );

            // Optimistic check, under the lock so nothing can change between it and the save
            if ($command->expectedVersion !== null && $command->expectedVersion !== ShipmentRepository::version($current)) {
                throw ShipmentConflictException::forShipment($shipmentId);
            }

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

            // Dates sent, otherwise the saved ones, made consistent with the state of the parcel
            [$data['shipped_at'], $data['delivered_at']] = ShipmentDates::resolve(
                $command->status ?? ShipmentStatus::from((string) $current['status']),
                $command->shippedAt,
                $command->deliveredAt,
                ShipmentDates::fromSaved($current['shipped_at']),
                ShipmentDates::fromSaved($current['delivered_at']),
                new DateTimeImmutable()
            );

            if (!$this->shipments->update($shipmentId, $data)) {
                throw new ShipmentException('The parcel could not be saved.');
            }

            if ($saved = $this->shipments->find($shipmentId)) {
                $this->journal->edited($current, $saved);
            }

            $this->effects->onShipmentSaved($shipmentId);
        });
    }
}
