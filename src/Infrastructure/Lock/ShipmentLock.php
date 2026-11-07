<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Lock;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentNotFoundException;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;

/**
 * Works on an existing parcel with its order locked (OrderLock). The parcel is read again
 * once the lock is held: it may have changed or been deleted while waiting. Reading it
 * first only gives the order to lock, which never changes on a parcel.
 */
class ShipmentLock
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly OrderLock $orderLock,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(array): T $work Receives the parcel as read under the lock
     *
     * @return T
     *
     * @throws ShipmentNotFoundException
     */
    public function synchronized(int $shipmentId, callable $work): mixed
    {
        $orderId = (int) $this->find($shipmentId)['id_order'];

        return $this->orderLock->synchronized($orderId, fn (): mixed => $work($this->find($shipmentId)));
    }

    /**
     * @throws ShipmentNotFoundException
     */
    private function find(int $shipmentId): array
    {
        return $this->shipments->find($shipmentId) ?? throw ShipmentNotFoundException::withId($shipmentId);
    }
}
