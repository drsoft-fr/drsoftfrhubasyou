<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Command;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Sends the "order delivered" e-mail at the merchant's request (back office): it failed
 * on the last parcel, or the customer did not receive it. Sent again even if already sent.
 */
final class SendDeliveredMailCommand
{
    public function __construct(public readonly int $orderId)
    {
    }
}
