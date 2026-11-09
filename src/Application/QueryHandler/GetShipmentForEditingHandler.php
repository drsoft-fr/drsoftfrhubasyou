<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\QueryHandler;

use DrSoftFr\Module\HubAsYou\Application\Query\GetShipmentForEditing;
use DrSoftFr\Module\HubAsYou\Application\QueryResult\ShipmentForEditing;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentNotFoundException;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsQueryHandler;

if (!defined('_PS_VERSION_')) {
    exit;
}

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

        return ShipmentForEditing::fromShipment(
            $this->repository->find($shipmentId) ?? throw ShipmentNotFoundException::withId($shipmentId)
        );
    }
}
