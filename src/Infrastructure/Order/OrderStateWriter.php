<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Order;

use Order;
use OrderHistory;

/**
 * Changes the state of an order the way the back office does (history, payment,
 * invoice, e-mail of the state if it has one).
 */
class OrderStateWriter
{
    public function change(Order $order, int $stateId): void
    {
        if ((int) $order->getCurrentState() === $stateId) {
            return;
        }

        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($stateId, $order, true);
        $history->addWithemail(true);
    }
}
