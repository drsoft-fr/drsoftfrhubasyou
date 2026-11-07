<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Service;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Domain\ShopOrder;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\OrderShippingProgress;
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ShipmentEffectsLog;
use DrSoftFr\Module\HubAsYou\Infrastructure\Mail\ShipmentMailer;
use DrSoftFr\Module\HubAsYou\Infrastructure\Order\OrderStateWriter;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderMailRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use Throwable;

/**
 * What happens after a parcel is saved (Admin API or back office):
 *   - "parcel on its way" e-mail, once per parcel, while it is travelling (none for a
 *     parcel already delivered);
 *   - order state: shipped, then delivered when every unit still due is delivered;
 *   - "order delivered" e-mail, once per order, when every unit still due is delivered.
 * Each e-mail can be disabled in the module settings. The last two also run when the
 * lines of the order change (refund, return).
 *
 * Everything is worked out again from the saved parcels each time, so a step that
 * failed is done at the next save. A failure never refuses the parcel: it is logged
 * (Advanced parameters › Logs).
 */
class ShipmentEffects
{
    /** Changing the state of an order can save its lines: no second run from that hook */
    private static bool $running = false;

    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly OrderRepository $orders,
        private readonly OrderStatePolicy $statePolicy,
        private readonly OrderMailRepository $orderMails,
        private readonly ShipmentMailer $mailer,
        private readonly OrderStateWriter $stateWriter,
        private readonly ShipmentEffectsLog $log,
    ) {
    }

    public function onShipmentSaved(int $shipmentId): void
    {
        $orderId = 0;

        $this->run(function () use ($shipmentId, &$orderId): void {
            $shipment = $this->shipments->find($shipmentId);

            if (!$shipment) {
                return;
            }

            $orderId = $shipment->orderId;
            $order = $this->orders->find($orderId);

            if (!$order) {
                return;
            }

            $this->sendShipmentMailOnce($order, $shipment);
            $this->applyProgress($order);
        }, function (Throwable $t) use ($shipmentId, &$orderId): void {
            $this->log->failure(sprintf('parcel #%d', $shipmentId), $t, $orderId);
        });
    }

    /**
     * A refund or a return can settle the last units an order was waiting for, with no
     * parcel left to save.
     */
    public function onOrderLinesChanged(int $orderId): void
    {
        $this->run(function () use ($orderId): void {
            $order = $this->orders->find($orderId);

            if ($order) {
                $this->applyProgress($order);
            }
        }, function (Throwable $t) use ($orderId): void {
            $this->log->failure(sprintf('order #%d', $orderId), $t, $orderId);
        });
    }

    /**
     * "Order delivered" e-mail at the merchant's request, even if already sent; its date
     * becomes the date of this sending. Unlike the automatic e-mail, a failure is reported.
     *
     * @throws ShipmentConstraintException The e-mail is not allowed for this order, or could not be sent
     */
    public function sendDeliveredMailNow(int $orderId): void
    {
        $order = $this->orders->find($orderId);

        if (!$order || !$this->statePolicy->mayDeliveredMailBeSent($order)) {
            throw new ShipmentConstraintException('The order is not entirely delivered, or this e-mail is disabled.', ShipmentConstraintException::DELIVERED_MAIL_NOT_ALLOWED);
        }

        $this->sendOrReport(
            fn (): bool => $this->mailer->sendDeliveredMail($orderId, $this->shipments->findByOrder($orderId)),
            ShipmentConstraintException::DELIVERED_MAIL_FAILED
        );

        $this->orderMails->saveDeliveredMailDate($orderId);
    }

    /**
     * "Parcel on its way" e-mail at the merchant's request, even if already sent; its date
     * becomes the date of this sending. Unlike the automatic e-mail, a failure is reported.
     *
     * @throws ShipmentConstraintException The e-mail is not allowed for this parcel, or could not be sent
     */
    public function sendShipmentMailNow(Shipment $shipment): void
    {
        $order = $this->orders->find($shipment->orderId);

        if (!$order || !$this->statePolicy->mayShipmentMailBeSent($order, $shipment->status)) {
            throw new ShipmentConstraintException('The parcel is no longer on its way, or this e-mail is disabled.', ShipmentConstraintException::SHIPMENT_MAIL_NOT_ALLOWED);
        }

        $this->sendOrReport(
            fn (): bool => $this->mailer->sendShipmentMail($order->id, $shipment),
            ShipmentConstraintException::SHIPMENT_MAIL_FAILED
        );

        $this->shipments->saveMailDate($shipment->id);
    }

    /**
     * @param callable(): bool $send
     *
     * @throws ShipmentConstraintException
     */
    private function sendOrReport(callable $send, int $failureCode): void
    {
        try {
            $sent = $send();
        } catch (Throwable $t) {
            throw new ShipmentConstraintException('The e-mail could not be sent.', $failureCode, $t);
        }

        if (!$sent) {
            throw new ShipmentConstraintException('The e-mail could not be sent.', $failureCode);
        }
    }

    /**
     * @param callable(): void $work
     * @param callable(Throwable): void $onFailure
     */
    private function run(callable $work, callable $onFailure): void
    {
        if (self::$running) {
            return;
        }

        self::$running = true;

        try {
            $work();
        } catch (Throwable $t) {
            $onFailure($t);
        } finally {
            self::$running = false;
        }
    }

    private function applyProgress(ShopOrder $order): void
    {
        $progress = $this->statePolicy->getProgress($order->id);

        $this->updateOrderState($order, $progress);

        if ($progress === OrderShippingProgress::Delivered) {
            $this->sendDeliveredMailOnce($order);
        }
    }

    private function sendShipmentMailOnce(ShopOrder $order, Shipment $shipment): void
    {
        if ($shipment->mailSentAt || !$this->statePolicy->mayShipmentMailBeSent($order, $shipment->status)) {
            return;
        }

        $this->sendOnce(
            fn (): bool => $this->shipments->claimMail($shipment->id),
            fn (): bool => $this->mailer->sendShipmentMail($order->id, $shipment),
            fn () => $this->shipments->releaseMail($shipment->id)
        );
    }

    private function sendDeliveredMailOnce(ShopOrder $order): void
    {
        if (!$this->statePolicy->isDeliveredMailEnabled($order)) {
            return;
        }

        $this->sendOnce(
            fn (): bool => $this->orderMails->claimDeliveredMail($order->id),
            fn (): bool => $this->mailer->sendDeliveredMail($order->id, $this->shipments->findByOrder($order->id)),
            fn () => $this->orderMails->releaseDeliveredMail($order->id)
        );
    }

    /**
     * The e-mail is reserved in the database (atomic) before it is sent, so two requests
     * cannot both send it; the reservation is given back if sending fails.
     *
     * @param callable(): bool $claim
     * @param callable(): bool $send
     * @param callable(): void $release
     */
    private function sendOnce(callable $claim, callable $send, callable $release): void
    {
        if (!$claim()) {
            return;
        }

        try {
            $sent = $send();
        } catch (Throwable $t) {
            $release();

            throw $t;
        }

        if (!$sent) {
            $release();
        }
    }

    /**
     * Moves the order to the state its parcels call for (OrderStatePolicy), only from a
     * state the module may change; otherwise the state stays as it is, with a warning
     * in the logs (and on the order page of the back office).
     */
    private function updateOrderState(ShopOrder $order, OrderShippingProgress $progress): void
    {
        $targetState = $this->statePolicy->getTargetState($order, $progress);

        if (!$targetState || $targetState === $order->currentState) {
            return;
        }

        if (!$this->statePolicy->mayChangeCurrentState($order)) {
            $this->log->stateNotChanged($order->id, $targetState, $order->currentState);

            return;
        }

        $this->stateWriter->change($order->id, $targetState);
    }
}
