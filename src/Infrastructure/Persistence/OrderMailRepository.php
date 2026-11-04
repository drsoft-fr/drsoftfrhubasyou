<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Persistence;

use Db;

/**
 * E-mails sent once per order ("order delivered").
 */
class OrderMailRepository
{
    public const TABLE = 'drsoft_fr_hubasyou_order_mail';

    /**
     * Reserves the "order delivered" e-mail: true for the one request that inserts
     * the row (primary key on the order), false if it exists (sent or being sent).
     */
    public function claimDeliveredMail(int $orderId): bool
    {
        $db = Db::getInstance();

        return $db->insert(self::TABLE, [
            'id_order' => $orderId,
            'delivered_mail_sent_at' => date('Y-m-d H:i:s'),
        ], false, true, Db::INSERT_IGNORE)
            && (int) $db->Affected_Rows() === 1;
    }

    /**
     * Gives the reservation back when the e-mail could not be sent: tried again at the next save.
     */
    public function releaseDeliveredMail(int $orderId): void
    {
        Db::getInstance()->delete(self::TABLE, 'id_order = ' . $orderId);
    }
}
