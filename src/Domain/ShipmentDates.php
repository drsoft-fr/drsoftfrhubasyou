<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;

/**
 * Dates of a parcel.
 */
final class ShipmentDates
{
    /**
     * Keeps the dates known (sent or already saved) and sets the missing ones that
     * the state implies to now: shipping date once on its way, delivery date once delivered.
     *
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable} [shippedAt, deliveredAt]
     */
    public static function complete(
        ShipmentStatus $status,
        ?DateTimeImmutable $shippedAt,
        ?DateTimeImmutable $deliveredAt,
        DateTimeImmutable $now,
    ): array {
        return [
            $shippedAt ?? ($status->isOnItsWay() ? $now : null),
            $deliveredAt ?? ($status === ShipmentStatus::Delivered ? $now : null),
        ];
    }
}
