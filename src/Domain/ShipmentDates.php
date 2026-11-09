<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Dates of a parcel and their rules:
 *   - a delivery date exists only for a delivered parcel (it goes when the parcel leaves
 *     the delivered state; sending one for a parcel that is not delivered is refused);
 *   - the delivery cannot come before the shipping;
 *   - a date sent cannot be before 2000, nor more than a day in the future;
 *   - missing dates that the state implies default to now (shipping: to the delivery
 *     date when that is earlier, for a parcel declared delivered in the past).
 * The state itself can change freely: HubAsYou must be able to correct it.
 */
final class ShipmentDates
{
    private const INPUT_FORMAT = '/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/D';

    /** Earliest date accepted: an earlier one is a mistake, and may not fit the database */
    private const EARLIEST = '2000-01-01T00:00:00+00:00';

    /** How far in the future a date is accepted: the clocks of two servers can differ */
    private const LATEST_MARGIN = '+1 day';

    /**
     * Date received from outside the module, null when it was not sent. Only ISO 8601 with
     * its time offset: PHP alone reads "" and "tomorrow" as dates, February 31 as March 3,
     * and a date without an offset in the time zone of the shop, whatever the sender meant.
     *
     * @param 'shippedAt'|'deliveredAt' $field
     *
     * @throws ShipmentConstraintException
     */
    public static function fromInput(string $field, ?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            if (!preg_match(self::INPUT_FORMAT, $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                throw new \Exception('Not an ISO 8601 date with an offset.');
            }

            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new ShipmentConstraintException(sprintf('%s must be an ISO 8601 date with its time offset.', $field), ShipmentConstraintException::INVALID_DATE, $e, ['fields' => [$field]]);
        }
    }

    /**
     * Date saved on a parcel, null when it has none.
     */
    public static function fromSaved(?string $value): ?\DateTimeImmutable
    {
        return $value ? new \DateTimeImmutable($value) : null;
    }

    /**
     * @param ShipmentStatus $status State of the parcel after the change
     * @param ?\DateTimeImmutable $sentShippedAt Date sent with the change
     * @param ?\DateTimeImmutable $sentDeliveredAt Date sent with the change
     * @param ?\DateTimeImmutable $savedShippedAt Date already saved (null for a new parcel)
     * @param ?\DateTimeImmutable $savedDeliveredAt Date already saved (null for a new parcel)
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} [shippedAt, deliveredAt]
     *
     * @throws ShipmentConstraintException
     */
    public static function resolve(
        ShipmentStatus $status,
        ?\DateTimeImmutable $sentShippedAt,
        ?\DateTimeImmutable $sentDeliveredAt,
        ?\DateTimeImmutable $savedShippedAt,
        ?\DateTimeImmutable $savedDeliveredAt,
        \DateTimeImmutable $now,
    ): array {
        self::assertInRange('shippedAt', $sentShippedAt, $now);
        self::assertInRange('deliveredAt', $sentDeliveredAt, $now);

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

    /**
     * Only for a date being sent: a parcel with an old saved date stays editable.
     *
     * @param 'shippedAt'|'deliveredAt' $field
     *
     * @throws ShipmentConstraintException
     */
    private static function assertInRange(string $field, ?\DateTimeImmutable $date, \DateTimeImmutable $now): void
    {
        if ($date && ($date < new \DateTimeImmutable(self::EARLIEST) || $date > $now->modify(self::LATEST_MARGIN))) {
            throw new ShipmentConstraintException(sprintf('%s is too old or too far in the future.', $field), ShipmentConstraintException::DATE_OUT_OF_RANGE, parameters: ['fields' => [$field]]);
        }
    }
}
