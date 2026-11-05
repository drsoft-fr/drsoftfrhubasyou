<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Service;

use DrSoftFr\Module\HubAsYou\Domain\ValueObject\OrderShippingProgress;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use DrSoftFr\Module\HubAsYou\Infrastructure\Configuration\Settings;
use DrSoftFr\Module\HubAsYou\Infrastructure\Mail\ShipmentMailer;
use DrSoftFr\Module\HubAsYou\Infrastructure\Order\OrderStateWriter;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderMailRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use Order;
use PrestaShopLogger;
use Throwable;
use Validate;

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
    private const MODULE_NAME = 'drsoftfrhubasyou';

    /** Changing the state of an order can save its lines: no second run from that hook */
    private static bool $running = false;

    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly OrderStatePolicy $statePolicy,
        private readonly OrderMailRepository $orderMails,
        private readonly ShipmentMailer $mailer,
        private readonly OrderStateWriter $stateWriter,
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

            $orderId = (int) $shipment['id_order'];
            $order = new Order($orderId);

            if (!Validate::isLoadedObject($order)) {
                return;
            }

            $this->sendShipmentMailOnce($order, $shipment);
            $this->applyProgress($order);
        }, function (Throwable $t) use ($shipmentId, &$orderId): void {
            $this->logFailure(sprintf('parcel #%d', $shipmentId), $t, $orderId);
        });
    }

    /**
     * A refund or a return can settle the last units an order was waiting for, with no
     * parcel left to save.
     */
    public function onOrderLinesChanged(int $orderId): void
    {
        $this->run(function () use ($orderId): void {
            $order = new Order($orderId);

            if (Validate::isLoadedObject($order)) {
                $this->applyProgress($order);
            }
        }, function (Throwable $t) use ($orderId): void {
            $this->logFailure(sprintf('order #%d', $orderId), $t, $orderId);
        });
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

    private function applyProgress(Order $order): void
    {
        $progress = $this->statePolicy->getProgress((int) $order->id);

        $this->updateOrderState($order, $progress);

        if ($progress === OrderShippingProgress::Delivered) {
            $this->sendDeliveredMailOnce($order);
        }
    }

    private function logFailure(string $subject, Throwable $t, int $orderId): void
    {
        PrestaShopLogger::addLog(
            sprintf('%s - %s - %s', self::MODULE_NAME, $subject, $t->getMessage()),
            3,
            (int) $t->getCode(),
            'Order',
            $orderId,
            true
        );
    }

    private function sendShipmentMailOnce(Order $order, array $shipment): void
    {
        $status = ShipmentStatus::tryFrom((string) $shipment['status']);

        if (!Settings::isMailEnabled() || !empty($shipment['mail_sent_at']) || !$status?->isTravelling()) {
            return;
        }

        $shipmentId = (int) $shipment['id_shipment'];

        $this->sendOnce(
            fn (): bool => $this->shipments->claimMail($shipmentId),
            fn (): bool => $this->mailer->sendShipmentMail($order, $shipment),
            fn () => $this->shipments->releaseMail($shipmentId)
        );
    }

    private function sendDeliveredMailOnce(Order $order): void
    {
        if (!Settings::isDeliveredMailEnabled()) {
            return;
        }

        $orderId = (int) $order->id;

        $this->sendOnce(
            fn (): bool => $this->orderMails->claimDeliveredMail($orderId),
            fn (): bool => $this->mailer->sendDeliveredMail($order, $this->shipments->findByOrder($orderId)),
            fn () => $this->orderMails->releaseDeliveredMail($orderId)
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
    private function updateOrderState(Order $order, OrderShippingProgress $progress): void
    {
        $orderId = (int) $order->id;
        $targetState = $this->statePolicy->getTargetState($orderId, $progress);
        $currentState = (int) $order->getCurrentState();

        if (!$targetState || $targetState === $currentState) {
            return;
        }

        if (!$this->statePolicy->mayChangeFrom($currentState)) {
            PrestaShopLogger::addLog(
                sprintf('%s - order #%d: state not changed to %d, the current state %d is not one the module may change (module settings)', self::MODULE_NAME, $orderId, $targetState, $currentState),
                2,
                null,
                'Order',
                $orderId,
                true
            );

            return;
        }

        $this->stateWriter->change($order, $targetState);
    }
}
