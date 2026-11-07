<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Command;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentRules;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;

/**
 * Declares a parcel of an order.
 */
final class AddShipmentCommand
{
    public readonly string $externalReference;

    public readonly string $carrierName;

    public readonly string $trackingNumber;

    public readonly ?string $trackingUrl;

    public readonly ShipmentStatus $status;

    /**
     * @var array<int, int> id_order_detail => quantity
     */
    public readonly array $lines;

    /**
     * @param array<int, array{orderDetailId?: int, quantity?: int}> $lines
     */
    public function __construct(
        string $externalReference,
        public readonly int $orderId,
        string $carrierName,
        string $trackingNumber,
        string $status,
        array $lines,
        ?string $trackingUrl = null,
        public readonly ?DateTimeImmutable $shippedAt = null,
        public readonly ?DateTimeImmutable $deliveredAt = null,
    ) {
        $this->externalReference = ShipmentRules::text('externalReference', $externalReference);
        $this->carrierName = ShipmentRules::text('carrierName', $carrierName);
        $this->trackingNumber = ShipmentRules::text('trackingNumber', $trackingNumber);
        $this->trackingUrl = ShipmentRules::url($trackingUrl);
        $this->status = ShipmentStatus::fromInput($status);
        $this->lines = ShipmentRules::lines($lines);
    }
}
