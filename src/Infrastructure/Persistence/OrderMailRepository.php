<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Persistence;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * E-mails sent once per order ("order delivered").
 */
class OrderMailRepository
{
    public const TABLE = 'drsoft_fr_hubasyou_order_mail';

    /**
     * Reserves the "order delivered" e-mail: true for the one request that inserts
     * the row (primary key on the order), false if it exists (sent or being sent).
     *
     * @throws ShipmentException Database error: not mistaken for "already sent"
     */
    public function claimDeliveredMail(int $orderId): bool
    {
        DatabaseError::write('The "order delivered" e-mail could not be reserved.', static fn (\Db $db): bool => $db->insert(self::TABLE, [
            'id_order' => $orderId,
            'delivered_mail_sent_at' => date(ShipmentRepository::DATE_FORMAT),
        ], false, true, \Db::INSERT_IGNORE));

        return (int) \Db::getInstance()->Affected_Rows() === 1;
    }

    /**
     * Date of the last sending, for an e-mail sent at the merchant's request.
     *
     * @throws ShipmentException Database error
     */
    public function saveDeliveredMailDate(int $orderId): void
    {
        DatabaseError::write('The date of the "order delivered" e-mail could not be saved.', static fn (\Db $db): bool => $db->execute(sprintf(
            "INSERT INTO `%s%s` (id_order, delivered_mail_sent_at) VALUES (%d, '%s') ON DUPLICATE KEY UPDATE delivered_mail_sent_at = VALUES(delivered_mail_sent_at)",
            _DB_PREFIX_,
            self::TABLE,
            $orderId,
            date(ShipmentRepository::DATE_FORMAT)
        ), false));
    }

    /**
     * Date of the "order delivered" e-mail, null when it has not been sent.
     */
    public function findDeliveredMailDate(int $orderId): ?string
    {
        return DatabaseReader::value(
            (new \DbQuery())
                ->select('delivered_mail_sent_at')
                ->from(self::TABLE)
                ->where('id_order = ' . $orderId)
        ) ?: null;
    }

    /**
     * Gives the reservation back when the e-mail could not be sent: tried again at the next save.
     *
     * @throws ShipmentException Database error
     */
    public function releaseDeliveredMail(int $orderId): void
    {
        DatabaseError::write('The reservation of the "order delivered" e-mail could not be given back.', static fn (\Db $db): bool => $db->delete(self::TABLE, 'id_order = ' . $orderId));
    }
}
