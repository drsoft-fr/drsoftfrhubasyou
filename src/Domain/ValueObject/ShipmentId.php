<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain\ValueObject;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;

/**
 * Identifier of a parcel. The Admin API normalizes it as {"shipmentId": <value>}
 * (namespace containing "ValueObject" + getValue()).
 */
final class ShipmentId
{
    private int $value;

    public function __construct(int $value)
    {
        if ($value <= 0) {
            throw new ShipmentConstraintException(sprintf('Invalid shipment id %d.', $value));
        }

        $this->value = $value;
    }

    public function getValue(): int
    {
        return $this->value;
    }
}
