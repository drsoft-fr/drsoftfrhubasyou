<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Command;

use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentId;

/**
 * Sends the "parcel on its way" e-mail at the merchant's request (back office): it failed
 * when the parcel was received, or the customer did not receive it. Sent again even if
 * already sent, only while the parcel is travelling.
 */
final class SendShipmentMailCommand
{
    public readonly ShipmentId $shipmentId;

    public function __construct(int $shipmentId)
    {
        $this->shipmentId = new ShipmentId($shipmentId);
    }
}
