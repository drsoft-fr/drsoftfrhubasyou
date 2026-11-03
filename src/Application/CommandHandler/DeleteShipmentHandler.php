<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DrSoftFr\Module\HubAsYou\Application\Command\DeleteShipmentCommand;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentNotFoundException;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;

#[AsCommandHandler]
final class DeleteShipmentHandler
{
    public function __construct(
        private readonly ShipmentRepository $repository,
    ) {
    }

    public function handle(DeleteShipmentCommand $command): void
    {
        $shipmentId = $command->shipmentId->getValue();

        if (!$this->repository->find($shipmentId)) {
            throw new ShipmentNotFoundException(sprintf('Parcel %d not found.', $shipmentId));
        }

        if (!$this->repository->delete($shipmentId)) {
            throw new ShipmentException('The parcel could not be deleted.');
        }
    }
}
