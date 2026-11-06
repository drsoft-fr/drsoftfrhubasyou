<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain\Exception;

/**
 * Data refused (unknown order, line of another order, quantity exceeded, duplicate reference…): HTTP 422.
 *
 * The message (English) is the one of the Admin API; the code lets the back
 * office show its own translated message for the fields it edits.
 */
class ShipmentConstraintException extends ShipmentException
{
    public const EMPTY_FIELD = 1;
    public const TOO_LONG = 2;
    public const INVALID_URL = 3;
    public const INVALID_STATUS = 4;
    public const DUPLICATE_REFERENCE = 5;
    public const INVALID_DATES = 6;
    public const DELIVERED_MAIL_NOT_ALLOWED = 7;
    public const DELIVERED_MAIL_FAILED = 8;
    public const SHIPMENT_MAIL_NOT_ALLOWED = 9;
    public const SHIPMENT_MAIL_FAILED = 10;

    public static function duplicateReference(string $externalReference): self
    {
        return new self(sprintf('The externalReference "%s" is already used by another parcel.', $externalReference), self::DUPLICATE_REFERENCE);
    }
}
