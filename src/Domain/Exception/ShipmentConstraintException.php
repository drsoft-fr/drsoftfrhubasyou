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
}
