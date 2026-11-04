<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;

/**
 * Rules on the quantities of an order put in parcels.
 *
 * Every array is indexed by order line (id_order_detail) and holds a number of units.
 */
final class ShipmentQuantities
{
    /**
     * Every line of the new parcel must belong to the order, and the units already
     * in parcels plus these ones cannot exceed the ordered quantity.
     *
     * @param array<int, int> $ordered Units ordered
     * @param array<int, int> $inParcels Units already in parcels
     * @param array<int, int> $lines Units of the new parcel
     *
     * @throws ShipmentConstraintException
     */
    public static function assertFit(int $orderId, array $ordered, array $inParcels, array $lines): void
    {
        foreach ($lines as $orderDetailId => $quantity) {
            if (!isset($ordered[$orderDetailId])) {
                throw new ShipmentConstraintException(sprintf('Order line %d does not belong to order %d.', $orderDetailId, $orderId));
            }

            $already = $inParcels[$orderDetailId] ?? 0;

            if ($already + $quantity > $ordered[$orderDetailId]) {
                throw new ShipmentConstraintException(sprintf(
                    'Order line %d: %d unit(s) ordered, %d already in parcels, %d sent.',
                    $orderDetailId,
                    $ordered[$orderDetailId],
                    $already,
                    $quantity
                ));
            }
        }
    }

    /**
     * True when every ordered unit is in a delivered parcel (false for an order without lines).
     *
     * @param array<int, int> $ordered Units ordered
     * @param array<int, int> $delivered Units in delivered parcels
     */
    public static function areAllDelivered(array $ordered, array $delivered): bool
    {
        if (!$ordered) {
            return false;
        }

        foreach ($ordered as $orderDetailId => $quantity) {
            if (($delivered[$orderDetailId] ?? 0) < $quantity) {
                return false;
            }
        }

        return true;
    }
}
