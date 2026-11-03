<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\QueryResult;

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
