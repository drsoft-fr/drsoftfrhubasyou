<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\QueryHandler;

use DrSoftFr\Module\HubAsYou\Application\Query\ListShipments;
use DrSoftFr\Module\HubAsYou\Application\QueryResult\ShipmentForEditing;
use DrSoftFr\Module\HubAsYou\Application\QueryResult\ShipmentList;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsQueryHandler;

#[AsQueryHandler]
final class ListShipmentsHandler
{
    public function __construct(
        private readonly ShipmentRepository $repository,
    ) {
    }

    public function handle(ListShipments $query): ShipmentList
    {
        [$shipments, $count] = $this->repository->findPaginated(
            $query->orderId,
            $query->externalReference,
            $query->offset,
            $query->limit
        );

        return new ShipmentList(array_map([ShipmentForEditing::class, 'fromShipment'], $shipments), $count);
    }
}
