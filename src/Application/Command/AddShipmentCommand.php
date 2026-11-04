<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Command;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
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
        $this->status = ShipmentStatus::tryFrom($status)
            ?? throw new ShipmentConstraintException(sprintf('Unknown status "%s".', $status), ShipmentConstraintException::INVALID_STATUS);

        $quantities = [];

        foreach ($lines as $line) {
            $orderDetailId = (int) ($line['orderDetailId'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($orderDetailId <= 0 || $quantity <= 0) {
                throw new ShipmentConstraintException('Each line needs a positive orderDetailId and quantity.');
            }

            // The same order line sent twice in a parcel is added up
            $quantities[$orderDetailId] = ($quantities[$orderDetailId] ?? 0) + $quantity;
        }

        if (!$quantities) {
            throw new ShipmentConstraintException('A parcel needs at least one line.');
        }

        $this->lines = $quantities;
    }
}
