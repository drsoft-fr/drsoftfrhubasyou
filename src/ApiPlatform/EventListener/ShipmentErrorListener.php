<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\ApiPlatform\EventListener;

use ApiPlatform\Metadata\HttpOperation;
use DrSoftFr\Module\HubAsYou\ApiPlatform\ShipmentErrorMessages;
use DrSoftFr\Module\HubAsYou\Domain\Exception\OrderBusyException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Answers the refusals of the parcel API with a message of ShipmentErrorMessages.
 *
 * With debug disabled, the error path used by the Admin API replaces the message of
 * every exception with the text of its internal status: 422 + "Internal Server Error".
 * The status comes from the exceptionToStatus of the resource (Shipment): an exception
 * that is not listed there keeps the generic answer of PrestaShop.
 */
final class ShipmentErrorListener
{
    /**
     * 503 (order busy): seconds to wait before sending the request again. The lock is
     * waited for 15 seconds, so the other request has been running for at least that long.
     */
    public const RETRY_AFTER_SECONDS = 5;

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $operation = $event->getRequest()->attributes->get('_api_operation');

        if (!$exception instanceof ShipmentException || !$operation instanceof HttpOperation) {
            return;
        }

        $status = $operation->getExceptionToStatus()[$exception::class] ?? null;

        if (null === $status) {
            return;
        }

        $headers = ['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'deny'];

        if ($exception instanceof OrderBusyException) {
            $headers['Retry-After'] = (string) self::RETRY_AFTER_SECONDS;
        }

        $event->setResponse(new JsonResponse(
            [
                'type' => 'https://tools.ietf.org/html/rfc2616#section-10',
                'title' => 'An error occurred',
                'status' => $status,
                'detail' => ShipmentErrorMessages::for($exception),
            ],
            $status,
            $headers,
        ));
    }
}
