<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain\ValueObject;

/**
 * States of a parcel, sent by HubAsYou.
 */
enum ShipmentStatus: string
{
    case Shipped = 'shipped';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Exception = 'exception';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * True when the parcel has left the warehouse (the customer can be told).
     */
    public function isOnItsWay(): bool
    {
        return $this !== self::Exception;
    }

    /**
     * True while the parcel is travelling: the customer can still be told it is on its
     * way (a parcel already delivered has arrived).
     */
    public function isTravelling(): bool
    {
        return $this === self::Shipped || $this === self::InTransit;
    }
}
