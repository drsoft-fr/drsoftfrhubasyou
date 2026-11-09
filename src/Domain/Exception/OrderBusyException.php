<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain\Exception;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Another request is updating the parcels of the same order and did not finish in
 * time: temporary, the same request can be sent again (HTTP 503).
 */
class OrderBusyException extends ShipmentException
{
    public static function forOrder(int $orderId): self
    {
        return new self(sprintf('The parcels of order %d are being updated by another request: send this request again in a moment.', $orderId), parameters: ['order' => $orderId]);
    }
}
