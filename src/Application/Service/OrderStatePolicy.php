<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Service;

use DrSoftFr\Module\HubAsYou\Domain\ValueObject\OrderShippingProgress;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use DrSoftFr\Module\HubAsYou\Infrastructure\Configuration\Settings;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use Order;

/**
 * Which state the parcels call for, and whether the module may set it.
 *
 * Shared by the effects of a saved parcel (which change the state) and the order page
 * of the back office (which warns when the change is not allowed).
 */
class OrderStatePolicy
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly OrderRepository $orders,
    ) {
    }

    public function getProgress(int $orderId): OrderShippingProgress
    {
        return OrderShippingProgress::of(
            $this->orders->findLines($orderId) ?? [],
            $this->shipments->getShippedQuantities($orderId, true),
            array_filter(array_map(
                static fn (array $shipment): ?ShipmentStatus => ShipmentStatus::tryFrom((string) $shipment['status']),
                $this->shipments->findByOrder($orderId)
            ))
        );
    }

    /**
     * State the order should be moved to now, null when none: the shipped state as soon
     * as a parcel is on its way, the delivered state once every unit still due is in a
     * delivered parcel. Each state is set once; the order never goes back.
     */
    public function getTargetState(Order $order, OrderShippingProgress $progress): ?int
    {
        $orderId = (int) $order->id;
        $shippedState = Settings::getShippedState($order);
        $deliveredState = Settings::getDeliveredState($order);

        // Order::hasBeenDelivered() reads the "delivery" flag, also set on the shipped state:
        // the history of the configured states is checked instead
        $hasBeenDelivered = $deliveredState && $this->orders->hasHadState($orderId, $deliveredState);

        if ($progress === OrderShippingProgress::Delivered && $deliveredState) {
            return $hasBeenDelivered ? null : $deliveredState;
        }

        if ($progress !== OrderShippingProgress::NotShipped
            && $shippedState
            && !$hasBeenDelivered
            && !$this->orders->hasHadState($orderId, $shippedState)
        ) {
            return $shippedState;
        }

        return null;
    }

    /**
     * The "order delivered" e-mail is enabled for the shop of the order, and the order is
     * entirely delivered.
     */
    public function mayDeliveredMailBeSent(Order $order): bool
    {
        return Settings::isDeliveredMailEnabled($order)
            && $this->getProgress((int) $order->id) === OrderShippingProgress::Delivered;
    }

    /**
     * The "parcel on its way" e-mail is enabled for the shop of the order, and the parcel
     * is travelling (a parcel already delivered has arrived).
     *
     * @param array $shipment Row with the state of the parcel ('status')
     */
    public function mayShipmentMailBeSent(Order $order, array $shipment): bool
    {
        return Settings::isMailEnabled($order)
            && ShipmentStatus::tryFrom((string) $shipment['status'])?->isTravelling();
    }

    /**
     * The module changes the state of an order only from one of the states chosen in
     * its settings (never, for example, from "Cancelled" or "Awaiting payment").
     */
    public function mayChangeCurrentState(Order $order): bool
    {
        return in_array((int) $order->getCurrentState(), Settings::getChangeableStates($order), true);
    }
}
