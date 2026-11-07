<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\QueryResult;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentDates;

/**
 * A parcel, in the format of the API (normalized through its getters).
 */
final class ShipmentForEditing
{
    /**
     * @param array<int, array{orderDetailId: int, quantity: int}> $lines
     */
    public function __construct(
        private readonly int $shipmentId,
        private readonly string $externalReference,
        private readonly int $orderId,
        private readonly string $carrierName,
        private readonly string $trackingNumber,
        private readonly ?string $trackingUrl,
        private readonly string $status,
        private readonly ?DateTimeImmutable $shippedAt,
        private readonly ?DateTimeImmutable $deliveredAt,
        private readonly array $lines,
    ) {
    }

    /**
     * Builds the result from a repository row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id_shipment'],
            (string) $row['external_reference'],
            (int) $row['id_order'],
            (string) $row['carrier_name'],
            (string) $row['tracking_number'],
            $row['tracking_url'] !== null && $row['tracking_url'] !== '' ? (string) $row['tracking_url'] : null,
            (string) $row['status'],
            ShipmentDates::fromSaved($row['shipped_at'] ?? null),
            ShipmentDates::fromSaved($row['delivered_at'] ?? null),
            array_map(
                static fn (array $line): array => ['orderDetailId' => (int) $line['id_order_detail'], 'quantity' => (int) $line['quantity']],
                $row['lines'] ?? []
            ),
        );
    }

    public function getShipmentId(): int
    {
        return $this->shipmentId;
    }

    public function getExternalReference(): string
    {
        return $this->externalReference;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getCarrierName(): string
    {
        return $this->carrierName;
    }

    public function getTrackingNumber(): string
    {
        return $this->trackingNumber;
    }

    public function getTrackingUrl(): ?string
    {
        return $this->trackingUrl;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getShippedAt(): ?DateTimeImmutable
    {
        return $this->shippedAt;
    }

    public function getDeliveredAt(): ?DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function getLines(): array
    {
        return $this->lines;
    }
}
