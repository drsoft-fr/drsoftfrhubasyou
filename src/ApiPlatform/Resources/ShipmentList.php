<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\ApiPlatform\Resources;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use DrSoftFr\Module\HubAsYou\Application\Query\ListShipments;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use PrestaShopBundle\ApiPlatform\Metadata\CQRSPaginate;
use Symfony\Component\HttpFoundation\Response;

if (!defined('_PS_VERSION_')) {
    exit;
}

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
    exceptionToStatus: [
        // A wrong filter or pagination value in the URL
        ShipmentConstraintException::class => Response::HTTP_BAD_REQUEST,
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

    public ?\DateTimeImmutable $shippedAt;

    public ?\DateTimeImmutable $deliveredAt;

    #[ApiProperty(openapiContext: Shipment::LINES_OPENAPI)]
    public array $lines;
}
