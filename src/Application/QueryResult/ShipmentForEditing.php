<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\QueryResult;

use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentLine;

if (!defined('_PS_VERSION_')) {
    exit;
}

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
        private readonly ?\DateTimeImmutable $shippedAt,
        private readonly ?\DateTimeImmutable $deliveredAt,
        private readonly array $lines,
    ) {
    }

    public static function fromShipment(Shipment $shipment): self
    {
        return new self(
            $shipment->id,
            $shipment->externalReference,
            $shipment->orderId,
            $shipment->carrierName,
            $shipment->trackingNumber,
            $shipment->trackingUrl,
            $shipment->status->value,
            $shipment->shippedAt,
            $shipment->deliveredAt,
            array_map(
                static fn (ShipmentLine $line): array => ['orderDetailId' => $line->orderDetailId, 'quantity' => $line->quantity],
                $shipment->lines
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

    public function getShippedAt(): ?\DateTimeImmutable
    {
        return $this->shippedAt;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function getLines(): array
    {
        return $this->lines;
    }
}
