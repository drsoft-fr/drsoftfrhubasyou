<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Integration\Support;

use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Infrastructure\Mail\ShipmentMailer;

/**
 * Records the e-mails the module asks for instead of sending them.
 */
final class RecordingMailer extends ShipmentMailer
{
    /** @var array<int, string> External references of the parcels of the "parcel on its way" e-mails */
    public array $shipmentMails = [];

    /** @var array<int, string[]> External references of the parcels listed in each "order delivered" e-mail */
    public array $deliveredMails = [];

    public function sendShipmentMail(int $orderId, Shipment $shipment): void
    {
        $this->shipmentMails[] = $shipment->externalReference;
    }

    public function sendDeliveredMail(int $orderId, array $shipments): void
    {
        $this->deliveredMails[] = array_map(static fn (Shipment $shipment): string => $shipment->externalReference, $shipments);
    }
}
