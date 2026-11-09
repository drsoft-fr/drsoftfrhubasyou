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

use Db;
use DrSoftFr\Module\HubAsYou\Infrastructure\Order\OrderStateWriter;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Records the order states the module sets, and writes only what the module reads back:
 * the current state of the order and a line of its history. No hook, no e-mail of the
 * state. Like OrderStateWriter, nothing happens when the order is already in that state.
 */
final class RecordingStateWriter extends OrderStateWriter
{
    /** @var int[] */
    public array $states = [];

    public function change(int $orderId, int $stateId): void
    {
        $db = Db::getInstance();

        if ((int) $db->getValue('SELECT current_state FROM ' . _DB_PREFIX_ . 'orders WHERE id_order = ' . $orderId, false) === $stateId) {
            return;
        }

        $this->states[] = $stateId;
        $db->update('orders', ['current_state' => $stateId], 'id_order = ' . $orderId);
        $db->insert('order_history', ['id_order' => $orderId, 'id_order_state' => $stateId, 'id_employee' => 0, 'date_add' => date('Y-m-d H:i:s')]);
    }
}
