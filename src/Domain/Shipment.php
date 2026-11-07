<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;

/**
 * A parcel of an order. Its content (reference, order, lines) never changes; its tracking,
 * state and dates change through withChanges(), which keeps the dates consistent.
 */
final class Shipment
{
    /** Identifier of a parcel not saved yet */
    public const NEW_ID = 0;

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param ShipmentLine[] $lines
     */
    public function __construct(
        public readonly int $id,
        public readonly string $externalReference,
        public readonly int $orderId,
        public readonly string $carrierName,
        public readonly string $trackingNumber,
        public readonly ?string $trackingUrl,
        public readonly ShipmentStatus $status,
        public readonly ?DateTimeImmutable $shippedAt,
        public readonly ?DateTimeImmutable $deliveredAt,
        public readonly ?DateTimeImmutable $mailSentAt,
        public readonly array $lines,
    ) {
    }

    /**
     * A new parcel: missing dates are worked out from its state (ShipmentDates).
     *
     * @param array<int, int> $quantities id_order_detail => quantity
     *
     * @throws ShipmentConstraintException
     */
    public static function declare(
        string $externalReference,
        int $orderId,
        string $carrierName,
        string $trackingNumber,
        ?string $trackingUrl,
        ShipmentStatus $status,
        ?DateTimeImmutable $shippedAt,
        ?DateTimeImmutable $deliveredAt,
        array $quantities,
        DateTimeImmutable $now,
    ): self {
        [$shippedAt, $deliveredAt] = ShipmentDates::resolve($status, $shippedAt, $deliveredAt, null, null, $now);
        $lines = [];

        foreach ($quantities as $orderDetailId => $quantity) {
            $lines[] = new ShipmentLine($orderDetailId, $quantity);
        }

        return new self(self::NEW_ID, $externalReference, $orderId, $carrierName, $trackingNumber, $trackingUrl, $status, $shippedAt, $deliveredAt, null, $lines);
    }

    /**
     * The parcel after a change of its tracking, state or dates. Null: unchanged; an
     * empty tracking URL removes the tracking page.
     *
     * @throws ShipmentConstraintException
     */
    public function withChanges(
        ?string $carrierName,
        ?string $trackingNumber,
        ?string $trackingUrl,
        ?ShipmentStatus $status,
        ?DateTimeImmutable $shippedAt,
        ?DateTimeImmutable $deliveredAt,
        DateTimeImmutable $now,
    ): self {
        $status ??= $this->status;
        [$shippedAt, $deliveredAt] = ShipmentDates::resolve($status, $shippedAt, $deliveredAt, $this->shippedAt, $this->deliveredAt, $now);

        return new self(
            $this->id,
            $this->externalReference,
            $this->orderId,
            $carrierName ?? $this->carrierName,
            $trackingNumber ?? $this->trackingNumber,
            $trackingUrl === '' ? null : ($trackingUrl ?? $this->trackingUrl),
            $status,
            $shippedAt,
            $deliveredAt,
            $this->mailSentAt,
            $this->lines,
        );
    }

    /**
     * Refuses a different content, rather than ignoring it (ShipmentRules). Null: not sent.
     *
     * @param array<int, int>|null $quantities id_order_detail => quantity
     *
     * @throws ShipmentConstraintException
     */
    public function assertContentUnchanged(?string $externalReference, ?int $orderId, ?array $quantities): void
    {
        ShipmentRules::assertContentUnchanged($externalReference, $orderId, $quantities, $this->externalReference, $this->orderId, $this->quantities());
    }

    /**
     * True when a declaration describes this parcel: same order, lines and tracking. Its
     * state and dates are not compared, they may have moved on since the first declaration.
     *
     * @param array<int, int> $quantities id_order_detail => quantity
     */
    public function isSameDeclaration(int $orderId, string $carrierName, string $trackingNumber, ?string $trackingUrl, array $quantities): bool
    {
        return $orderId === $this->orderId
            && $carrierName === $this->carrierName
            && $trackingNumber === $this->trackingNumber
            && $trackingUrl === $this->trackingUrl
            && $quantities == $this->quantities();
    }

    /**
     * @return array<int, int> id_order_detail => quantity
     */
    public function quantities(): array
    {
        $quantities = [];

        foreach ($this->lines as $line) {
            $quantities[$line->orderDetailId] = $line->quantity;
        }

        return $quantities;
    }

    /**
     * The values a change can overwrite, by name.
     *
     * @return array<string, string|null>
     */
    public function changeableValues(): array
    {
        return [
            'carrier_name' => $this->carrierName,
            'tracking_number' => $this->trackingNumber,
            'tracking_url' => $this->trackingUrl,
            'status' => $this->status->value,
            'shipped_at' => $this->shippedAt?->format(self::DATE_FORMAT),
            'delivered_at' => $this->deliveredAt?->format(self::DATE_FORMAT),
        ];
    }

    /**
     * Fingerprint of the values a change can overwrite. Unlike a date of update (precise
     * to the second), it changes with any change of these values.
     */
    public function version(): string
    {
        return sha1((string) json_encode(array_values($this->changeableValues())));
    }
}
