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
use DrSoftFr\Module\HubAsYou\ApiPlatform\Processor\ShipmentUpdateProcessor;
use DrSoftFr\Module\HubAsYou\Application\Command\AddShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Command\DeleteShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Command\EditShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Query\GetShipmentForEditing;
use DrSoftFr\Module\HubAsYou\Domain\Exception\OrderBusyException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentNotFoundException;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentRules;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use PrestaShopBundle\ApiPlatform\Metadata\CQRSCreate;
use PrestaShopBundle\ApiPlatform\Metadata\CQRSDelete;
use PrestaShopBundle\ApiPlatform\Metadata\CQRSGet;
use PrestaShopBundle\ApiPlatform\Metadata\CQRSPartialUpdate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A parcel shipped by HubAsYou (contract: docs/api.md).
 *
 * No index.php in this folder: API Platform requires every file found here.
 */
#[ApiResource(
    operations: [
        new CQRSGet(
            uriTemplate: '/hubasyou/shipments/{shipmentId}',
            requirements: self::ID_REQUIREMENT,
            CQRSQuery: GetShipmentForEditing::class,
            scopes: ['hubasyou_shipment_read'],
        ),
        new CQRSCreate(
            uriTemplate: '/hubasyou/shipments',
            validationContext: ['groups' => ['Default', 'Create']],
            CQRSCommand: AddShipmentCommand::class,
            CQRSQuery: GetShipmentForEditing::class,
            scopes: ['hubasyou_shipment_write'],
        ),
        new CQRSPartialUpdate(
            uriTemplate: '/hubasyou/shipments/{shipmentId}',
            requirements: self::ID_REQUIREMENT,
            validationContext: ['groups' => ['Default', 'Update']],
            // Refuses a shipmentId of the body that is not the one of the URL, then runs the command
            processor: ShipmentUpdateProcessor::class,
            CQRSCommand: EditShipmentCommand::class,
            CQRSQuery: GetShipmentForEditing::class,
            scopes: ['hubasyou_shipment_write'],
        ),
        new CQRSDelete(
            uriTemplate: '/hubasyou/shipments/{shipmentId}',
            requirements: self::ID_REQUIREMENT,
            CQRSCommand: DeleteShipmentCommand::class,
            scopes: ['hubasyou_shipment_write'],
        ),
    ],
    exceptionToStatus: [
        ShipmentConstraintException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
        ShipmentNotFoundException::class => Response::HTTP_NOT_FOUND,
        // Temporary: the same request can be sent again
        OrderBusyException::class => Response::HTTP_SERVICE_UNAVAILABLE,
    ],
)]
class Shipment
{
    /**
     * Only a whole number written the normal way names a parcel. Without it, PHP's conversion
     * makes "1.5", "1abc" or "01" the parcel 1: a typing mistake would act on another parcel.
     */
    private const ID_REQUIREMENT = ['shipmentId' => '[1-9]\d*'];

    /** OpenAPI schema of the lines of a parcel (also used by ShipmentList) */
    public const LINES_OPENAPI = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'orderDetailId' => ['type' => 'integer'],
                'quantity' => ['type' => 'integer'],
            ],
        ],
    ];

    #[ApiProperty(identifier: true)]
    public int $shipmentId;

    #[Assert\NotBlank(groups: ['Create'])]
    #[Assert\Length(max: ShipmentRules::TEXT_MAX_LENGTH)]
    public string $externalReference;

    #[Assert\NotBlank(groups: ['Create'])]
    #[Assert\Positive]
    public int $orderId;

    #[Assert\NotBlank(groups: ['Create'])]
    #[Assert\Length(max: ShipmentRules::TEXT_MAX_LENGTH)]
    public string $carrierName;

    #[Assert\NotBlank(groups: ['Create'])]
    #[Assert\Length(max: ShipmentRules::TEXT_MAX_LENGTH)]
    public string $trackingNumber;

    #[Assert\Url(protocols: ['http', 'https'])]
    #[Assert\Length(max: ShipmentRules::URL_MAX_LENGTH)]
    public ?string $trackingUrl;

    #[ApiProperty(openapiContext: ['type' => 'string', 'enum' => ['shipped', 'in_transit', 'delivered', 'exception'], 'example' => 'shipped'])]
    #[Assert\NotBlank(groups: ['Create'])]
    #[Assert\Choice(callback: [ShipmentStatus::class, 'values'])]
    public string $status;

    /**
     * The dates stay text here and are read by the commands: read as a date here, a wrong
     * value would stop the request with a 500, which tells the sender to try again.
     */
    #[ApiProperty(openapiContext: ['type' => 'string', 'format' => 'date-time'])]
    public ?string $shippedAt;

    #[ApiProperty(openapiContext: ['type' => 'string', 'format' => 'date-time'])]
    public ?string $deliveredAt;

    #[ApiProperty(openapiContext: [...self::LINES_OPENAPI, 'example' => [['orderDetailId' => 101, 'quantity' => 1]]])]
    #[Assert\NotBlank(groups: ['Create'])]
    #[Assert\All(constraints: [
        new Assert\Collection(fields: [
            'orderDetailId' => [new Assert\NotBlank(), new Assert\Type('integer'), new Assert\Positive()],
            'quantity' => [new Assert\NotBlank(), new Assert\Type('integer'), new Assert\Positive()],
        ]),
    ])]
    public array $lines;
}
