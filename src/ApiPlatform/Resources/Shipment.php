<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\ApiPlatform\Resources;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Application\Command\AddShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Command\DeleteShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Command\EditShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Query\GetShipmentForEditing;
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

/**
 * A parcel shipped by HubAsYou (contract: docs/api.md).
 *
 * No index.php in this folder: API Platform requires every file found here.
 */
#[ApiResource(
    operations: [
        new CQRSGet(
            uriTemplate: '/hubasyou/shipments/{shipmentId}',
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
            validationContext: ['groups' => ['Default', 'Update']],
            CQRSCommand: EditShipmentCommand::class,
            CQRSQuery: GetShipmentForEditing::class,
            scopes: ['hubasyou_shipment_write'],
        ),
        new CQRSDelete(
            uriTemplate: '/hubasyou/shipments/{shipmentId}',
            requirements: ['shipmentId' => '\d+'],
            CQRSCommand: DeleteShipmentCommand::class,
            scopes: ['hubasyou_shipment_write'],
        ),
    ],
    exceptionToStatus: [
        ShipmentConstraintException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
        ShipmentNotFoundException::class => Response::HTTP_NOT_FOUND,
    ],
)]
class Shipment
{
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
    #[Assert\NotBlank(allowNull: true, groups: ['Update'])]
    #[Assert\Length(max: ShipmentRules::TEXT_MAX_LENGTH)]
    public string $carrierName;

    #[Assert\NotBlank(groups: ['Create'])]
    #[Assert\NotBlank(allowNull: true, groups: ['Update'])]
    #[Assert\Length(max: ShipmentRules::TEXT_MAX_LENGTH)]
    public string $trackingNumber;

    #[Assert\Url(protocols: ['http', 'https'])]
    #[Assert\Length(max: ShipmentRules::URL_MAX_LENGTH)]
    public ?string $trackingUrl;

    #[ApiProperty(openapiContext: ['type' => 'string', 'enum' => ['shipped', 'in_transit', 'delivered', 'exception'], 'example' => 'shipped'])]
    #[Assert\NotBlank(groups: ['Create'])]
    #[Assert\Choice(callback: [ShipmentStatus::class, 'values'])]
    public string $status;

    public ?DateTimeImmutable $shippedAt;

    public ?DateTimeImmutable $deliveredAt;

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
