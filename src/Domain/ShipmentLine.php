<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain;

/**
 * Units of one order line in a parcel. The product is known once the parcel is saved.
 */
final class ShipmentLine
{
    public function __construct(
        public readonly int $orderDetailId,
        public readonly int $quantity,
        public readonly string $productName = '',
        public readonly string $productReference = '',
    ) {
    }
}
