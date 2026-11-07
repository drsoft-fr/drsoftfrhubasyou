<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use Exception;

/**
 * Dates of a parcel and their rules:
 *   - a delivery date exists only for a delivered parcel (it goes when the parcel leaves
 *     the delivered state; sending one for a parcel that is not delivered is refused);
 *   - the delivery cannot come before the shipping;
 *   - missing dates that the state implies default to now (shipping: to the delivery
 *     date when that is earlier, for a parcel declared delivered in the past).
 * The state itself can change freely: HubAsYou must be able to correct it.
 */
final class ShipmentDates
{
    /**
     * Date received from outside the module, null when it was not sent. An empty value is
     * refused: PHP would read it as "now".
     *
     * @param 'shippedAt'|'deliveredAt' $field
     *
     * @throws ShipmentConstraintException
     */
    public static function fromInput(string $field, ?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            if (trim($value) === '') {
                throw new Exception('Empty date.');
            }

            return new DateTimeImmutable($value);
        } catch (Exception $e) {
            throw new ShipmentConstraintException(sprintf('%s is not a valid date.', $field), ShipmentConstraintException::INVALID_DATE, $e, ['fields' => [$field]]);
        }
    }

    /**
     * Date saved on a parcel, null when it has none.
     */
    public static function fromSaved(?string $value): ?DateTimeImmutable
    {
        return $value ? new DateTimeImmutable($value) : null;
    }

    /**
     * @param ShipmentStatus $status State of the parcel after the change
     * @param ?DateTimeImmutable $sentShippedAt Date sent with the change
     * @param ?DateTimeImmutable $sentDeliveredAt Date sent with the change
     * @param ?DateTimeImmutable $savedShippedAt Date already saved (null for a new parcel)
     * @param ?DateTimeImmutable $savedDeliveredAt Date already saved (null for a new parcel)
     *
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable} [shippedAt, deliveredAt]
     *
     * @throws ShipmentConstraintException
     */
    public static function resolve(
        ShipmentStatus $status,
        ?DateTimeImmutable $sentShippedAt,
        ?DateTimeImmutable $sentDeliveredAt,
        ?DateTimeImmutable $savedShippedAt,
        ?DateTimeImmutable $savedDeliveredAt,
        DateTimeImmutable $now,
    ): array {
        $delivered = $status === ShipmentStatus::Delivered;

        if ($sentDeliveredAt && !$delivered) {
            throw new ShipmentConstraintException(sprintf('deliveredAt can only be sent for a delivered parcel (status "%s").', ShipmentStatus::Delivered->value), ShipmentConstraintException::INVALID_DATES);
        }

        $deliveredAt = $delivered ? ($sentDeliveredAt ?? $savedDeliveredAt ?? $now) : null;
        $shippedAt = $sentShippedAt ?? $savedShippedAt;

        if (!$shippedAt && $status->isOnItsWay()) {
            $shippedAt = $deliveredAt && $deliveredAt < $now ? $deliveredAt : $now;
        }

        if ($shippedAt && $deliveredAt && $deliveredAt < $shippedAt) {
            throw new ShipmentConstraintException('deliveredAt cannot be earlier than shippedAt.', ShipmentConstraintException::INVALID_DATES);
        }

        return [$shippedAt, $deliveredAt];
    }
}
