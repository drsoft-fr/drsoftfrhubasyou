<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\ApiPlatform;

use DrSoftFr\Module\HubAsYou\Domain\Exception\OrderBusyException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConflictException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException as Refusal;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentNotFoundException;

/**
 * The only messages the parcel API can send for an error of the module.
 *
 * The message of the exception is never sent. An error missing from this table gets
 * FALLBACK. Only integers and the field names below can be inserted in a message.
 */
final class ShipmentErrorMessages
{
    public const FALLBACK = 'The request was refused.';

    private const BY_CODE = [
        Refusal::EMPTY_FIELD => '%fields% cannot be empty.',
        Refusal::TOO_LONG => '%fields% cannot exceed %max% characters.',
        Refusal::INVALID_URL => 'trackingUrl must be an http(s) URL of %max% characters at most.',
        Refusal::INVALID_STATUS => 'Unknown status.',
        Refusal::DUPLICATE_REFERENCE => 'The externalReference is already used by another parcel.',
        Refusal::INVALID_DATES => 'Inconsistent dates: deliveredAt needs the status "delivered" and cannot be earlier than shippedAt.',
        Refusal::UNKNOWN_ORDER => 'Order %order% does not exist.',
        Refusal::LINE_NOT_IN_ORDER => 'Order line %line% does not belong to order %order%.',
        Refusal::VIRTUAL_LINE => 'Order line %line% is a virtual product: it cannot be in a parcel.',
        Refusal::QUANTITY_EXCEEDED => 'Order line %line%: %ordered% unit(s) ordered, %already% already in parcels, %sent% sent.',
        Refusal::CONTENT_CHANGED => 'The content of a parcel cannot be changed (%fields%): delete the parcel and declare it again.',
        Refusal::INVALID_LINE => 'Each line needs a positive orderDetailId and quantity.',
        Refusal::NO_LINE => 'A parcel needs at least one line.',
        Refusal::INVALID_ID => 'Invalid shipment id %id%.',
    ];

    private const BY_CLASS = [
        ShipmentNotFoundException::class => 'Parcel %id% not found.',
        ShipmentConflictException::class => 'Parcel %id% has changed since it was read: read it again before changing it.',
        OrderBusyException::class => 'The parcels of order %order% are being updated by another request: send this request again in a moment.',
    ];

    private const FIELD_NAMES = ['externalReference', 'orderId', 'lines', 'carrierName', 'trackingNumber'];

    public static function for(ShipmentException $exception): string
    {
        $template = $exception instanceof Refusal
            ? (self::BY_CODE[$exception->getCode()] ?? null)
            : (self::BY_CLASS[$exception::class] ?? null);

        if ($template === null) {
            return self::FALLBACK;
        }

        $values = [];

        foreach ($exception->parameters as $name => $value) {
            $values['%' . $name . '%'] = self::safeValue($value);
        }

        $message = strtr($template, $values);

        // A value is missing or was not allowed: never send a half-filled message
        return preg_match('/%\w+%|\(\)|^ /', $message) ? self::FALLBACK : $message;
    }

    /**
     * @param int|array<string> $value
     */
    private static function safeValue(int|array $value): string
    {
        return is_int($value)
            ? (string) $value
            : implode(', ', array_intersect($value, self::FIELD_NAMES));
    }
}
