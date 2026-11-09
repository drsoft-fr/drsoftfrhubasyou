<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Integration\ApiPlatform;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Patch;
use DrSoftFr\Module\HubAsYou\ApiPlatform\EventListener\ShipmentErrorListener;
use DrSoftFr\Module\HubAsYou\ApiPlatform\Resources\Shipment;
use DrSoftFr\Module\HubAsYou\ApiPlatform\Resources\ShipmentList;
use DrSoftFr\Module\HubAsYou\ApiPlatform\ShipmentErrorMessages;
use DrSoftFr\Module\HubAsYou\Domain\Exception\OrderBusyException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentNotFoundException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The answer of the API to each refusal, with the codes the resources really declare
 * (exceptionToStatus of their ApiResource attribute) and Symfony's real event.
 */
final class ShipmentErrorListenerTest extends TestCase
{
    public function testEachRefusalOfTheParcelResourceGetsItsCodeAndItsMessage(): void
    {
        $samples = [
            ShipmentConstraintException::class => new ShipmentConstraintException('internal', ShipmentConstraintException::NO_LINE),
            ShipmentNotFoundException::class => ShipmentNotFoundException::withId(12),
            OrderBusyException::class => OrderBusyException::forOrder(45),
        ];
        $statuses = $this->exceptionToStatus(Shipment::class);

        self::assertSame(array_keys($samples), array_keys($statuses), 'A sample for each exception the resource declares');

        foreach ($samples as $class => $exception) {
            $response = $this->answer($exception, $statuses);
            $body = json_decode((string) $response?->getContent(), true);

            self::assertSame($statuses[$class], $response?->getStatusCode(), $class);
            self::assertSame($statuses[$class], $body['status'], $class);
            self::assertSame(ShipmentErrorMessages::for($exception), $body['detail'], $class);
            self::assertNotSame(ShipmentErrorMessages::FALLBACK, $body['detail'], $class);
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }
    }

    public function testABusyOrderTellsWhenToRetry(): void
    {
        $response = $this->answer(OrderBusyException::forOrder(45), $this->exceptionToStatus(Shipment::class));

        self::assertSame(503, $response?->getStatusCode());
        self::assertSame((string) ShipmentErrorListener::RETRY_AFTER_SECONDS, $response->headers->get('Retry-After'));
        self::assertNull($this->answer(ShipmentNotFoundException::withId(12), $this->exceptionToStatus(Shipment::class))?->headers->get('Retry-After'));
    }

    public function testAWrongListParameterIsABadRequest(): void
    {
        $exception = new ShipmentConstraintException('internal', ShipmentConstraintException::INVALID_LIST_PARAMETER, parameters: ['fields' => ['limit']]);

        $response = $this->answer($exception, $this->exceptionToStatus(ShipmentList::class));

        self::assertSame(400, $response?->getStatusCode());
        self::assertSame('The value of limit is not valid.', json_decode((string) $response->getContent(), true)['detail']);
    }

    public function testATechnicalFailureKeepsTheGenericAnswerOfPrestaShop(): void
    {
        self::assertNull($this->answer(new ShipmentException('SQLSTATE[HY000] secret'), $this->exceptionToStatus(Shipment::class)));
    }

    public function testOtherRequestsAreLeftAlone(): void
    {
        // Back office (no operation of API Platform), or an error that is not the module's
        self::assertNull($this->answer(ShipmentNotFoundException::withId(12), null));
        self::assertNull($this->answer(new RuntimeException('other'), $this->exceptionToStatus(Shipment::class)));
    }

    /**
     * @param array<class-string, int>|null $statuses null: not a request of the Admin API
     */
    private function answer(Throwable $exception, ?array $statuses): ?Response
    {
        $request = new Request();

        if ($statuses !== null) {
            $request->attributes->set('_api_operation', new Patch(exceptionToStatus: $statuses));
        }

        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $exception);
        (new ShipmentErrorListener())->onKernelException($event);

        return $event->getResponse();
    }

    /**
     * @return array<class-string, int>
     */
    private function exceptionToStatus(string $resource): array
    {
        return (new ReflectionClass($resource))->getAttributes(ApiResource::class)[0]->getArguments()['exceptionToStatus'];
    }
}
