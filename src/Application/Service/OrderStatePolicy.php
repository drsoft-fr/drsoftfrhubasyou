<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Service;

use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Domain\ShopOrder;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\OrderShippingProgress;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use DrSoftFr\Module\HubAsYou\Infrastructure\Configuration\Settings;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;

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
        private readonly Settings $settings,
    ) {
    }

    public function getProgress(int $orderId): OrderShippingProgress
    {
        return OrderShippingProgress::of(
            $this->orders->findLines($orderId) ?? [],
            $this->shipments->getShippedQuantities($orderId, true),
            array_map(static fn (Shipment $shipment): ShipmentStatus => $shipment->status, $this->shipments->findByOrder($orderId))
        );
    }

    /**
     * State the order should be moved to now, null when none: the shipped state as soon
     * as a parcel is on its way, the delivered state once every unit still due is in a
     * delivered parcel. Each state is set once; the order never goes back.
     */
    public function getTargetState(ShopOrder $order, OrderShippingProgress $progress): ?int
    {
        $shippedState = $this->settings->getShippedState($order);
        $deliveredState = $this->settings->getDeliveredState($order);

        // Order::hasBeenDelivered() reads the "delivery" flag, also set on the shipped state:
        // the history of the configured states is checked instead
        $hasBeenDelivered = $deliveredState && $this->orders->hasHadState($order->id, $deliveredState);

        if ($progress === OrderShippingProgress::Delivered && $deliveredState) {
            return $hasBeenDelivered ? null : $deliveredState;
        }

        if ($progress !== OrderShippingProgress::NotShipped
            && $shippedState
            && !$hasBeenDelivered
            && !$this->orders->hasHadState($order->id, $shippedState)
        ) {
            return $shippedState;
        }

        return null;
    }

    public function isDeliveredMailEnabled(ShopOrder $order): bool
    {
        return $this->settings->isDeliveredMailEnabled($order);
    }

    /**
     * The "order delivered" e-mail is enabled for the shop of the order, and the order is
     * entirely delivered.
     */
    public function mayDeliveredMailBeSent(ShopOrder $order): bool
    {
        return $this->isDeliveredMailEnabled($order)
            && $this->getProgress($order->id) === OrderShippingProgress::Delivered;
    }

    /**
     * The "parcel on its way" e-mail is enabled for the shop of the order, and the parcel
     * is travelling (a parcel already delivered has arrived).
     */
    public function mayShipmentMailBeSent(ShopOrder $order, ShipmentStatus $status): bool
    {
        return $this->settings->isMailEnabled($order) && $status->isTravelling();
    }

    /**
     * The module changes the state of an order only from one of the states chosen in
     * its settings (never, for example, from "Cancelled" or "Awaiting payment").
     */
    public function mayChangeCurrentState(ShopOrder $order): bool
    {
        return in_array($order->currentState, $this->settings->getChangeableStates($order), true);
    }
}
