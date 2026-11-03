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
 */
#[ApiResource(
    operations: [
        new CQRSPaginate(
            uriTemplate: '/hubasyou/shipments',
            CQRSQuery: ListShipments::class,
            scopes: ['hubasyou_shipment_read'],
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

    #[ApiProperty(openapiContext: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'orderDetailId' => ['type' => 'integer'],
                'quantity' => ['type' => 'integer'],
            ],
        ],
    ])]
    public array $lines;
}
