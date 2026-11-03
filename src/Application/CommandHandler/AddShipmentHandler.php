<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DateTimeImmutable;
use Db;
use DbQuery;
use DrSoftFr\Module\HubAsYou\Application\Command\AddShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentNotifier;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentId;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;

#[AsCommandHandler]
final class AddShipmentHandler
{
    public function __construct(
        private readonly ShipmentRepository $repository,
        private readonly ShipmentNotifier $notifier,
    ) {
    }

    public function handle(AddShipmentCommand $command): ShipmentId
    {
        if ($this->repository->existsByExternalReference($command->externalReference)) {
            throw new ShipmentConstraintException(sprintf('The externalReference "%s" is already used by another parcel.', $command->externalReference));
        }

        $this->assertLinesFitTheOrder($command->orderId, $command->lines);

        $now = new DateTimeImmutable();
        $shipmentId = $this->repository->add(
            [
                'external_reference' => $command->externalReference,
                'id_order' => $command->orderId,
                'carrier_name' => $command->carrierName,
                'tracking_number' => $command->trackingNumber,
                'tracking_url' => $command->trackingUrl,
                'status' => $command->status->value,
                'shipped_at' => $command->shippedAt ?? ($command->status->isOnItsWay() ? $now : null),
                'delivered_at' => $command->deliveredAt ?? ($command->status === ShipmentStatus::Delivered ? $now : null),
            ],
            $command->lines
        );

        if (!$shipmentId) {
            throw new ShipmentException('The parcel could not be saved.');
        }

        $this->notifier->onShipmentSaved($shipmentId);

        return new ShipmentId($shipmentId);
    }

    /**
     * Every line must belong to the order, and the units already put in parcels
     * plus these ones cannot exceed the ordered quantity.
     *
     * @param array<int, int> $lines id_order_detail => quantity
     */
    private function assertLinesFitTheOrder(int $orderId, array $lines): void
    {
        $orderExists = (bool) Db::getInstance()->getValue(
            (new DbQuery())->select('1')->from('orders')->where('id_order = ' . $orderId)
        );

        if (!$orderExists) {
            throw new ShipmentConstraintException(sprintf('Order %d does not exist.', $orderId));
        }

        $ordered = [];
        $query = (new DbQuery())
            ->select('id_order_detail, product_quantity')
            ->from('order_detail')
            ->where('id_order = ' . $orderId);

        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $ordered[(int) $row['id_order_detail']] = (int) $row['product_quantity'];
        }

        $shipped = $this->repository->getShippedQuantities($orderId);

        foreach ($lines as $orderDetailId => $quantity) {
            if (!isset($ordered[$orderDetailId])) {
                throw new ShipmentConstraintException(sprintf('Order line %d does not belong to order %d.', $orderDetailId, $orderId));
            }

            $already = $shipped[$orderDetailId] ?? 0;

            if ($already + $quantity > $ordered[$orderDetailId]) {
                throw new ShipmentConstraintException(sprintf(
                    'Order line %d: %d unit(s) ordered, %d already in parcels, %d sent.',
                    $orderDetailId,
                    $ordered[$orderDetailId],
                    $already,
                    $quantity
                ));
            }
        }
    }
}
