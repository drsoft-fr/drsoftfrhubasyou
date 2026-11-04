<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\QueryHandler;

use DrSoftFr\Module\HubAsYou\Application\Query\GetShipmentForEditing;
use DrSoftFr\Module\HubAsYou\Application\QueryResult\ShipmentForEditing;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentNotFoundException;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsQueryHandler;

#[AsQueryHandler]
final class GetShipmentForEditingHandler
{
    public function __construct(
        private readonly ShipmentRepository $repository,
    ) {
    }

    public function handle(GetShipmentForEditing $query): ShipmentForEditing
    {
        $shipmentId = $query->shipmentId->getValue();

        return ShipmentForEditing::fromRow(
            $this->repository->find($shipmentId) ?? throw ShipmentNotFoundException::withId($shipmentId)
        );
    }
}
