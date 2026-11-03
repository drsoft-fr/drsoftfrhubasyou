<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Query;

use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentId;

final class GetShipmentForEditing
{
    public readonly ShipmentId $shipmentId;

    public function __construct(int $shipmentId)
    {
        $this->shipmentId = new ShipmentId($shipmentId);
    }
}
