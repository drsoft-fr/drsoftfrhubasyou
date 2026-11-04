<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\ApiPlatform\Resources;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Application\Query\ListShipments;
use PrestaShopBundle\ApiPlatform\Metadata\CQRSPaginate;

/**
 * Paginated list of parcels: GET /hubasyou/shipments?orderId=…&externalReference=…
 *
 * PrestaShop's QueryListProvider merges the GET parameters into every item before
 * building it, so the filters "orderId" (a string in the URL) and "externalReference"
 * would replace the values of each parcel. Each parcel's own values are copied aside
 * when the query result is normalized (CQRSQueryMapping), then copied back over the
 * merged ones (ApiResourceMapping).
 */
#[ApiResource(
    operations: [
        new CQRSPaginate(
            uriTemplate: '/hubasyou/shipments',
            CQRSQuery: ListShipments::class,
            scopes: ['hubasyou_shipment_read'],
            CQRSQueryMapping: [
                '[items][@index][orderId]' => '[items][@index][parcelOrderId]',
                '[items][@index][externalReference]' => '[items][@index][parcelExternalReference]',
            ],
            ApiResourceMapping: [
                '[parcelOrderId]' => '[orderId]',
                '[parcelExternalReference]' => '[externalReference]',
            ],
            itemsField: 'items',
            countField: 'count',
        ),
    ],
)]
class ShipmentList
{
    #[ApiProperty(identifier: true)]
    public int $shipmentId;

    public string $externalReference;

    public int $orderId;

    public string $carrierName;

    public string $trackingNumber;

    public ?string $trackingUrl;

    public string $status;

    public ?DateTimeImmutable $shippedAt;

    public ?DateTimeImmutable $deliveredAt;

    #[ApiProperty(openapiContext: Shipment::LINES_OPENAPI)]
    public array $lines;
}
