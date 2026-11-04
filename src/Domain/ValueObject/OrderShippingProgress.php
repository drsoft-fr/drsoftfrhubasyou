<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain\ValueObject;

use DrSoftFr\Module\HubAsYou\Domain\ShipmentQuantities;

/**
 * Where the order stands, given its parcels.
 */
enum OrderShippingProgress
{
    /** No parcel on its way yet */
    case NotShipped;

    /** At least one parcel on its way */
    case Shipped;

    /** Every ordered unit is in a delivered parcel */
    case Delivered;

    /**
     * @param array<int, int> $ordered Units ordered, by order line
     * @param array<int, int> $delivered Units in delivered parcels, by order line
     * @param ShipmentStatus[] $statuses States of the parcels of the order
     */
    public static function of(array $ordered, array $delivered, array $statuses): self
    {
        if (ShipmentQuantities::areAllDelivered($ordered, $delivered)) {
            return self::Delivered;
        }

        foreach ($statuses as $status) {
            if ($status->isOnItsWay()) {
                return self::Shipped;
            }
        }

        return self::NotShipped;
    }
}
