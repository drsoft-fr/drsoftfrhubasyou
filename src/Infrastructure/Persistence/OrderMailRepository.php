<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Persistence;

use Db;
use DbQuery;

/**
 * E-mails sent once per order ("order delivered").
 */
class OrderMailRepository
{
    public const TABLE = 'drsoft_fr_hubasyou_order_mail';

    public function hasDeliveredMailBeenSent(int $orderId): bool
    {
        return (bool) Db::getInstance()->getValue(
            (new DbQuery())->select('1')->from(self::TABLE)->where('id_order = ' . $orderId)
        );
    }

    public function markDeliveredMailSent(int $orderId): void
    {
        Db::getInstance()->insert(self::TABLE, [
            'id_order' => $orderId,
            'delivered_mail_sent_at' => date('Y-m-d H:i:s'),
        ], false, true, Db::INSERT_IGNORE);
    }
}
