<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Command;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentRules;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentId;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;

/**
 * Updates the tracking of a parcel (Admin API PATCH, back office form).
 *
 * Null fields are left unchanged; an empty trackingUrl removes the tracking page.
 * expectedVersion (back office): version of the parcel the change is based on
 * (ShipmentRepository::version()); refused if the parcel changed meanwhile.
 * The content of the parcel (order, lines, external reference) cannot be changed:
 * delete the parcel and declare it again.
 */
final class EditShipmentCommand
{
    public readonly ShipmentId $shipmentId;

    public readonly ?string $carrierName;

    public readonly ?string $trackingNumber;

    /** Null: unchanged; '': removed */
    public readonly ?string $trackingUrl;

    public readonly ?ShipmentStatus $status;

    public function __construct(
        int $shipmentId,
        ?string $carrierName = null,
        ?string $trackingNumber = null,
        ?string $trackingUrl = null,
        ?string $status = null,
        public readonly ?DateTimeImmutable $shippedAt = null,
        public readonly ?DateTimeImmutable $deliveredAt = null,
        public readonly ?string $expectedVersion = null,
    ) {
        $this->shipmentId = new ShipmentId($shipmentId);
        $this->carrierName = $carrierName === null ? null : ShipmentRules::text('carrierName', $carrierName);
        $this->trackingNumber = $trackingNumber === null ? null : ShipmentRules::text('trackingNumber', $trackingNumber);
        $this->trackingUrl = $trackingUrl === null ? null : (ShipmentRules::url($trackingUrl) ?? '');
        $this->status = $status === null
            ? null
            : (ShipmentStatus::tryFrom($status) ?? throw new ShipmentConstraintException(sprintf('Unknown status "%s".', $status), ShipmentConstraintException::INVALID_STATUS));
    }
}
