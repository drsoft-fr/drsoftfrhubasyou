<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Service;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\OrderShippingProgress;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use DrSoftFr\Module\HubAsYou\Infrastructure\Configuration\Settings;
use DrSoftFr\Module\HubAsYou\Infrastructure\Mail\ShipmentMailer;
use DrSoftFr\Module\HubAsYou\Infrastructure\Order\OrderStateWriter;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderMailRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use Order;
use PrestaShopLogger;
use Throwable;
use Validate;

/**
 * What happens after a parcel is saved (Admin API or back office):
 *   - "parcel on its way" e-mail, once per parcel;
 *   - order state: shipped, then delivered when every unit is delivered;
 *   - "order delivered" e-mail, once per order, when every unit is delivered.
 * Each e-mail can be disabled in the module settings.
 *
 * Everything is worked out again from the saved parcels each time, so a step that
 * failed is done at the next save. A failure never refuses the parcel: it is logged
 * (Advanced parameters › Logs).
 */
class ShipmentEffects
{
    private const MODULE_NAME = 'drsoftfrhubasyou';

    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly OrderRepository $orders,
        private readonly OrderMailRepository $orderMails,
        private readonly ShipmentMailer $mailer,
        private readonly OrderStateWriter $stateWriter,
    ) {
    }

    public function onShipmentSaved(int $shipmentId): void
    {
        $orderId = 0;

        try {
            $shipment = $this->shipments->find($shipmentId);

            if (!$shipment) {
                return;
            }

            $orderId = (int) $shipment['id_order'];
            $order = new Order($orderId);

            if (!Validate::isLoadedObject($order)) {
                return;
            }

            $progress = $this->getProgress($orderId);

            $this->sendShipmentMailOnce($order, $shipment);
            $this->updateOrderState($order, $progress);

            if ($progress === OrderShippingProgress::Delivered) {
                $this->sendDeliveredMailOnce($order);
            }
        } catch (Throwable $t) {
            PrestaShopLogger::addLog(
                sprintf('%s - parcel #%d - %s', self::MODULE_NAME, $shipmentId, $t->getMessage()),
                3,
                (int) $t->getCode(),
                'Order',
                $orderId,
                true
            );
        }
    }

    private function getProgress(int $orderId): OrderShippingProgress
    {
        return OrderShippingProgress::of(
            $this->orders->findOrderedQuantities($orderId) ?? [],
            $this->shipments->getShippedQuantities($orderId, true),
            array_filter(array_map(
                static fn (array $shipment): ?ShipmentStatus => ShipmentStatus::tryFrom((string) $shipment['status']),
                $this->shipments->findByOrder($orderId)
            ))
        );
    }

    private function sendShipmentMailOnce(Order $order, array $shipment): void
    {
        $status = ShipmentStatus::tryFrom((string) $shipment['status']);

        if (!Settings::isMailEnabled() || !empty($shipment['mail_sent_at']) || !$status?->isOnItsWay()) {
            return;
        }

        if ($this->mailer->sendShipmentMail($order, $shipment)) {
            $this->shipments->update((int) $shipment['id_shipment'], ['mail_sent_at' => new DateTimeImmutable()]);
        }
    }

    private function sendDeliveredMailOnce(Order $order): void
    {
        $orderId = (int) $order->id;

        if (!Settings::isDeliveredMailEnabled() || $this->orderMails->hasDeliveredMailBeenSent($orderId)) {
            return;
        }

        if ($this->mailer->sendDeliveredMail($order, $this->shipments->findByOrder($orderId))) {
            $this->orderMails->markDeliveredMailSent($orderId);
        }
    }

    /**
     * Shipped state as soon as a parcel is on its way; delivered state once every
     * ordered unit is in a delivered parcel. Never goes back to an earlier state.
     */
    private function updateOrderState(Order $order, OrderShippingProgress $progress): void
    {
        $orderId = (int) $order->id;
        $shippedState = Settings::getShippedState();
        $deliveredState = Settings::getDeliveredState();

        // Order::hasBeenDelivered() reads the "delivery" flag, also set on the shipped state:
        // the history of the configured states is checked instead
        $hasBeenDelivered = $deliveredState && $this->orders->hasHadState($orderId, $deliveredState);

        if ($progress === OrderShippingProgress::Delivered && $deliveredState) {
            if (!$hasBeenDelivered) {
                $this->stateWriter->change($order, $deliveredState);
            }

            return;
        }

        if ($progress !== OrderShippingProgress::NotShipped
            && $shippedState
            && !$hasBeenDelivered
            && !$this->orders->hasHadState($orderId, $shippedState)
        ) {
            $this->stateWriter->change($order, $shippedState);
        }
    }
}
