<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\QueryResult;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A page of parcels and the total count (fields "items" and "count" read by the Admin API).
 */
final class ShipmentList
{
    /**
     * @param ShipmentForEditing[] $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $count,
    ) {
    }

    /**
     * @return ShipmentForEditing[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
