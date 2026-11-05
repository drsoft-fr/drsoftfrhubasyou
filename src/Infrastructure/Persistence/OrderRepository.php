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
     * Lines of an order, with what PrestaShop records on them: a product cancelled on an
     * unpaid order lowers product_quantity, a refund or a return only adds to its own
     * column. A product deleted from the catalog counts as physical.
     *
     * @return array<int, array{ordered: int, refunded: int, returned: int, virtual: bool}>|null by id_order_detail; null when the order does not exist
     */
    public function findLines(int $orderId): ?array
    {
        $db = Db::getInstance();

        if (!$db->getValue((new DbQuery())->select('1')->from('orders')->where('id_order = ' . $orderId))) {
            return null;
        }

        $query = (new DbQuery())
            ->select('od.id_order_detail, od.product_quantity, od.product_quantity_refunded, od.product_quantity_return, p.is_virtual')
            ->from('order_detail', 'od')
            ->leftJoin('product', 'p', 'p.id_product = od.product_id')
            ->where('od.id_order = ' . $orderId);
        $lines = [];

        foreach ($db->executeS($query) ?: [] as $row) {
            $lines[(int) $row['id_order_detail']] = [
                'ordered' => (int) $row['product_quantity'],
                'refunded' => (int) $row['product_quantity_refunded'],
                'returned' => (int) $row['product_quantity_return'],
                'virtual' => (bool) $row['is_virtual'],
            ];
        }

        return $lines;
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
