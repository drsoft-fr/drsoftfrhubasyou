<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Persistence;

use Db;
use DbQuery;

/**
 * Reads the PrestaShop orders (lines, state history).
 */
class OrderRepository
{
    /**
     * Units ordered, by order line.
     *
     * @return array<int, int>|null id_order_detail => quantity; null when the order does not exist
     */
    public function findOrderedQuantities(int $orderId): ?array
    {
        $db = Db::getInstance();

        if (!$db->getValue((new DbQuery())->select('1')->from('orders')->where('id_order = ' . $orderId))) {
            return null;
        }

        $query = (new DbQuery())
            ->select('id_order_detail, product_quantity')
            ->from('order_detail')
            ->where('id_order = ' . $orderId);
        $quantities = [];

        foreach ($db->executeS($query) ?: [] as $row) {
            $quantities[(int) $row['id_order_detail']] = (int) $row['product_quantity'];
        }

        return $quantities;
    }

    /**
     * True when the order has been in this state at some point (order history).
     */
    public function hasHadState(int $orderId, int $stateId): bool
    {
        return (bool) Db::getInstance()->getValue(
            (new DbQuery())
                ->select('1')
                ->from('order_history')
                ->where('id_order = ' . $orderId . ' AND id_order_state = ' . $stateId)
        );
    }
}
