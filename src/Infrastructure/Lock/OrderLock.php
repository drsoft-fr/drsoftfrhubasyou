<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Lock;

use DrSoftFr\Module\HubAsYou\Domain\Exception\OrderBusyException;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Handles the parcels of one order one request at a time: checking the quantities,
 * saving, then the effects (e-mails, order state) see the work of the previous request.
 *
 * Named lock of the database (GET_LOCK), held by the connection: released at the end
 * of the work, or by the database if the PHP process dies. No transaction stays open
 * while the e-mails are sent. A request can take the lock of its order again, for example
 * from the hook of an order line saved by a state change: the database counts the nested
 * locks, and each one is released in turn. A request works on one order at a time, so no
 * deadlock between two requests.
 */
class OrderLock
{
    /** Seconds to wait for another request on the same order */
    public const TIMEOUT = 15;

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws OrderBusyException When the order stays locked longer than TIMEOUT
     */
    public function synchronized(int $orderId, callable $work): mixed
    {
        $db = \Db::getInstance();
        $name = "'" . $this->getName($orderId) . "'";

        if ((int) $db->getValue('SELECT GET_LOCK(' . $name . ', ' . self::TIMEOUT . ')', false) !== 1) {
            throw OrderBusyException::forOrder($orderId);
        }

        try {
            return $work();
        } finally {
            $db->getValue('SELECT RELEASE_LOCK(' . $name . ')', false);
        }
    }

    /**
     * Unique per database and table prefix (several shops on one server), 64 characters at most.
     */
    private function getName(int $orderId): string
    {
        return 'hubasyou_' . sha1(_DB_NAME_ . '.' . _DB_PREFIX_) . '_' . $orderId;
    }
}
