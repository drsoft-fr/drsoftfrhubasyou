<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Rules on the quantities of an order put in parcels.
 *
 * Every array is indexed by order line (id_order_detail). The lines of the order hold
 * ['ordered' => int, 'refunded' => int, 'returned' => int, 'virtual' => bool], the others
 * a number of units.
 */
final class ShipmentQuantities
{
    /**
     * Every line of the new parcel must be a physical product of the order, and the units
     * already in parcels plus these ones cannot exceed the ordered quantity.
     *
     * Refunded and returned units are not taken off: a returned unit has usually been in
     * a parcel, and a parcel that really left must not be refused because of a refund.
     *
     * @param array<int, array{ordered: int, refunded: int, returned: int, virtual: bool}> $orderLines
     * @param array<int, int> $inParcels Units already in parcels
     * @param array<int, int> $lines Units of the new parcel
     *
     * @throws ShipmentConstraintException
     */
    public static function assertFit(int $orderId, array $orderLines, array $inParcels, array $lines): void
    {
        foreach ($lines as $orderDetailId => $quantity) {
            if (!isset($orderLines[$orderDetailId])) {
                throw new ShipmentConstraintException(sprintf('Order line %d does not belong to order %d.', $orderDetailId, $orderId), ShipmentConstraintException::LINE_NOT_IN_ORDER, parameters: ['line' => $orderDetailId, 'order' => $orderId]);
            }

            if ($orderLines[$orderDetailId]['virtual']) {
                throw new ShipmentConstraintException(sprintf('Order line %d is a virtual product: it cannot be in a parcel.', $orderDetailId), ShipmentConstraintException::VIRTUAL_LINE, parameters: ['line' => $orderDetailId]);
            }

            $already = $inParcels[$orderDetailId] ?? 0;

            if ($already + $quantity > $orderLines[$orderDetailId]['ordered']) {
                throw new ShipmentConstraintException(sprintf(
                    'Order line %d: %d unit(s) ordered, %d already in parcels, %d sent.',
                    $orderDetailId,
                    $orderLines[$orderDetailId]['ordered'],
                    $already,
                    $quantity
                ), ShipmentConstraintException::QUANTITY_EXCEEDED, parameters: ['line' => $orderDetailId, 'ordered' => $orderLines[$orderDetailId]['ordered'], 'already' => $already, 'sent' => $quantity]);
            }
        }
    }

    /**
     * True when every unit still due is in a delivered parcel: virtual products, refunded
     * and returned units are not waited for. False when nothing was delivered at all (an
     * order without lines, or entirely refunded, is not a delivered order).
     *
     * @param array<int, array{ordered: int, refunded: int, returned: int, virtual: bool}> $orderLines
     * @param array<int, int> $delivered Units in delivered parcels
     */
    public static function areAllDelivered(array $orderLines, array $delivered): bool
    {
        $anyDelivered = false;

        foreach ($orderLines as $orderDetailId => $line) {
            if ($line['virtual']) {
                continue;
            }

            $units = $delivered[$orderDetailId] ?? 0;

            if ($units < $line['ordered'] - $line['refunded'] - $line['returned']) {
                return false;
            }

            $anyDelivered = $anyDelivered || $units > 0;
        }

        return $anyDelivered;
    }
}
