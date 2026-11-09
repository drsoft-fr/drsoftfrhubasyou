<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\ApiPlatform\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use PrestaShopBundle\ApiPlatform\Processor\CommandProcessor;
use Symfony\Component\HttpFoundation\Request;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * PATCH of a parcel: a shipmentId in the body must be the one of the URL, then the
 * command of PrestaShop's CQRS processor runs as for any other operation.
 *
 * API Platform never reads an identifier from the body of a PATCH: without this check,
 * {"shipmentId": 13} sent to /shipments/12 would change parcel 12 without a word. The other
 * content fields (externalReference, orderId, lines) are refused by the command itself.
 * The raw body is read here because the deserialized resource never holds that value.
 */
final class ShipmentUpdateProcessor implements ProcessorInterface
{
    public function __construct(private readonly CommandProcessor $commands)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? json_decode($request->getContent(), true) : null;
        $urlId = (int) ($uriVariables['shipmentId'] ?? 0);

        if (is_array($body) && array_key_exists('shipmentId', $body) && $body['shipmentId'] !== $urlId) {
            throw new ShipmentConstraintException(sprintf('The shipmentId of the body is not the parcel %d of the URL.', $urlId), ShipmentConstraintException::ID_MISMATCH, parameters: ['id' => $urlId]);
        }

        return $this->commands->process($data, $operation, $uriVariables, $context);
    }
}
