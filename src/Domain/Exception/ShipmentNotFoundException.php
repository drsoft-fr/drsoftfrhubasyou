<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain\Exception;

/**
 * Unknown parcel: HTTP 404.
 */
class ShipmentNotFoundException extends ShipmentException
{
    public static function withId(int $shipmentId): self
    {
        return new self(sprintf('Parcel %d not found.', $shipmentId), parameters: ['id' => $shipmentId]);
    }
}
