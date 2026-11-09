<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Integration\Support;

use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderMailRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentLogRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A fake order written straight to the database: no customer, no cart, no hook, no
 * e-mail. Only what the module reads: the order (shop, state) and its lines (quantities).
 */
final class TestOrder
{
    public const REFERENCE = 'HAYTEST';

    /**
     * @param int[] $lineIds id_order_detail, in the order of the quantities given to create()
     */
    private function __construct(
        public readonly int $id,
        public readonly array $lineIds,
    ) {
    }

    /**
     * @param int[] $quantities One order line per quantity
     */
    public static function create(int $stateId, array $quantities): self
    {
        $db = \Db::getInstance();
        $shopId = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $now = date('Y-m-d H:i:s');

        self::insert('orders', [
            'reference' => self::REFERENCE,
            'id_shop_group' => (int) (new \Shop($shopId))->id_shop_group,
            'id_shop' => $shopId,
            'id_carrier' => 0,
            'id_lang' => (int) \Configuration::get('PS_LANG_DEFAULT'),
            'id_customer' => 0,
            'id_cart' => 0,
            'id_currency' => (int) \Configuration::get('PS_CURRENCY_DEFAULT'),
            'id_address_delivery' => 0,
            'id_address_invoice' => 0,
            'current_state' => $stateId,
            'payment' => 'Integration test',
            'invoice_date' => $now,
            'delivery_date' => $now,
            'date_add' => $now,
            'date_upd' => $now,
        ]);
        $orderId = (int) $db->Insert_ID();
        $lineIds = [];

        foreach (array_values($quantities) as $i => $quantity) {
            self::insert('order_detail', [
                'id_order' => $orderId,
                'id_shop' => $shopId,
                'product_id' => 0,
                'product_name' => 'Integration test product ' . ($i + 1),
                'product_reference' => self::REFERENCE . '-' . ($i + 1),
                'product_quantity' => $quantity,
                'product_weight' => 0,
                'tax_name' => '',
            ]);
            $lineIds[] = (int) $db->Insert_ID();
        }

        return new self($orderId, $lineIds);
    }

    /**
     * The order, its lines, and everything the module and PrestaShop's logs kept about it.
     */
    public function delete(): void
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;

        $db->execute('DELETE l FROM ' . $p . ShipmentRepository::TABLE_LINE . ' l INNER JOIN ' . $p . ShipmentRepository::TABLE . ' s ON s.id_shipment = l.id_shipment WHERE s.id_order = ' . $this->id);

        foreach ([ShipmentRepository::TABLE, OrderMailRepository::TABLE, ShipmentLogRepository::TABLE, 'order_history', 'order_detail', 'orders'] as $table) {
            $db->delete($table, 'id_order = ' . $this->id);
        }

        $db->delete('log', "object_type = 'Order' AND object_id = " . $this->id . " AND message LIKE 'drsoftfrhubasyou%'");
    }

    /**
     * @param array<string, int|string> $row
     */
    private static function insert(string $table, array $row): void
    {
        if (!\Db::getInstance()->insert($table, $row)) {
            throw new \RuntimeException(sprintf('The test order could not be written in %s: %s', $table, \Db::getInstance()->getMsgError()));
        }
    }
}
