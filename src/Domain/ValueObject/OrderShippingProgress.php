<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain\ValueObject;

use DrSoftFr\Module\HubAsYou\Domain\ShipmentQuantities;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Where the order stands, given its parcels.
 */
enum OrderShippingProgress
{
    /** No parcel on its way yet */
    case NotShipped;

    /** At least one parcel on its way */
    case Shipped;

    /** Every unit still due is in a delivered parcel, and no parcel is travelling */
    case Delivered;

    /**
     * A travelling parcel always holds the order back: a returned unit cannot be told
     * from the one still on its way on the same line, and the "order delivered" e-mail
     * cannot be taken back. A lost parcel has to be set as "exception" or deleted.
     *
     * @param array<int, array{ordered: int, refunded: int, returned: int, virtual: bool}> $orderLines By order line
     * @param array<int, int> $delivered Units in delivered parcels, by order line
     * @param ShipmentStatus[] $statuses States of the parcels of the order
     */
    public static function of(array $orderLines, array $delivered, array $statuses): self
    {
        $isTravelling = array_filter($statuses, static fn (ShipmentStatus $status): bool => $status->isTravelling()) !== [];

        if (!$isTravelling && ShipmentQuantities::areAllDelivered($orderLines, $delivered)) {
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
