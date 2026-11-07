<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain;

/**
 * Units of one order line in a parcel. The product is copied from the order line when the
 * parcel is saved, and kept even if the line is later removed from the order.
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
