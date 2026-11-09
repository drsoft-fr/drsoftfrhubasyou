<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the module needs to know about a PrestaShop order to decide what to do with it.
 * A photo taken when it was read: its state changes in PrestaShop, not here.
 */
final class ShopOrder
{
    public function __construct(
        public readonly int $id,
        public readonly int $currentState,
        public readonly int $shopId,
        public readonly int $shopGroupId,
    ) {
    }
}
