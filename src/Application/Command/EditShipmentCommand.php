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

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentDates;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentRules;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentId;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Updates the tracking of a parcel (Admin API PATCH, back office form).
 *
 * Null fields are left unchanged; an empty trackingUrl removes the tracking page.
 * expectedVersion (back office): version of the parcel the change is based on
 * (Shipment::version()); refused if the parcel changed meanwhile.
 * The content of the parcel (order, lines, external reference) cannot be changed:
 * delete the parcel and declare it again. These fields are only received to refuse a
 * different value (Admin API), instead of ignoring it.
 */
final class EditShipmentCommand
{
    public readonly ShipmentId $shipmentId;

    public readonly ?string $carrierName;

    public readonly ?string $trackingNumber;

    /** Null: unchanged; '': removed */
    public readonly ?string $trackingUrl;

    public readonly ?ShipmentStatus $status;

    public readonly ?DateTimeImmutable $shippedAt;

    public readonly ?DateTimeImmutable $deliveredAt;

    public readonly ?string $externalReference;

    /**
     * @var array<int, int>|null id_order_detail => quantity
     */
    public readonly ?array $lines;

    /**
     * @param array<int, array{orderDetailId?: int, quantity?: int}>|null $lines
     */
    public function __construct(
        int $shipmentId,
        ?string $carrierName = null,
        ?string $trackingNumber = null,
        ?string $trackingUrl = null,
        ?string $status = null,
        ?string $shippedAt = null,
        ?string $deliveredAt = null,
        public readonly ?string $expectedVersion = null,
        ?string $externalReference = null,
        public readonly ?int $orderId = null,
        ?array $lines = null,
    ) {
        $this->externalReference = $externalReference === null ? null : trim($externalReference);
        $this->lines = $lines === null ? null : ShipmentRules::lines($lines);
        $this->shipmentId = new ShipmentId($shipmentId);
        $this->carrierName = $carrierName === null ? null : ShipmentRules::text('carrierName', $carrierName);
        $this->trackingNumber = $trackingNumber === null ? null : ShipmentRules::text('trackingNumber', $trackingNumber);
        $this->trackingUrl = $trackingUrl === null ? null : (ShipmentRules::url($trackingUrl) ?? '');
        $this->status = $status === null ? null : ShipmentStatus::fromInput($status);
        $this->shippedAt = ShipmentDates::fromInput('shippedAt', $shippedAt);
        $this->deliveredAt = ShipmentDates::fromInput('deliveredAt', $deliveredAt);
    }
}
