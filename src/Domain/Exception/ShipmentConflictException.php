<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain\Exception;

/**
 * The parcel changed since the version the change was based on (form of the back
 * office opened before an update of HubAsYou): nothing saved, the form shows the current
 * values. Back office only: the Admin API sends no version, so it never raises it.
 */
class ShipmentConflictException extends ShipmentException
{
    public static function forShipment(int $shipmentId): self
    {
        return new self(sprintf('Parcel %d has changed since it was read: read it again before changing it.', $shipmentId), parameters: ['id' => $shipmentId]);
    }
}
