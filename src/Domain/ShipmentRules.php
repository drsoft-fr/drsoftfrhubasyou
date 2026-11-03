<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;

/**
 * Rules on the fields of a parcel, checked by the commands: the same for the
 * Admin API (which also validates its payload) and for the back office.
 */
final class ShipmentRules
{
    public const TEXT_MAX_LENGTH = 64;
    public const URL_MAX_LENGTH = 255;

    /**
     * @throws ShipmentConstraintException
     */
    public static function text(string $field, string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new ShipmentConstraintException(sprintf('%s cannot be empty.', $field), ShipmentConstraintException::EMPTY_FIELD);
        }

        if (mb_strlen($value) > self::TEXT_MAX_LENGTH) {
            throw new ShipmentConstraintException(sprintf('%s cannot exceed %d characters.', $field, self::TEXT_MAX_LENGTH), ShipmentConstraintException::TOO_LONG);
        }

        return $value;
    }

    /**
     * Empty value: no tracking page (null).
     *
     * @throws ShipmentConstraintException
     */
    public static function url(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if (mb_strlen($value) > self::URL_MAX_LENGTH
            || !in_array($scheme, ['http', 'https'], true)
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            throw new ShipmentConstraintException('trackingUrl must be an http(s) URL of 255 characters at most.', ShipmentConstraintException::INVALID_URL);
        }

        return $value;
    }
}
