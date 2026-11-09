<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Integration\Support;

use Configuration;
use DrSoftFr\Module\HubAsYou\Application\Command\AddShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\CommandHandler\AddShipmentHandler;
use DrSoftFr\Module\HubAsYou\Application\CommandHandler\DeleteShipmentHandler;
use DrSoftFr\Module\HubAsYou\Application\CommandHandler\EditShipmentHandler;
use DrSoftFr\Module\HubAsYou\Application\Service\OrderStatePolicy;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentEffects;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Infrastructure\Configuration\Settings;
use DrSoftFr\Module\HubAsYou\Infrastructure\Context\RequestOrigin;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\OrderLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\ShipmentLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ShipmentEffectsLog;
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ShipmentJournal;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderMailRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentLogRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Core\Context\ApiClient;
use PrestaShop\PrestaShop\Core\Context\ApiClientContext;
use PrestaShop\PrestaShop\Core\Context\EmployeeContext;

/**
 * The real handlers, repositories and lock on the real database, with the e-mails and
 * the order states recorded (RecordingMailer, RecordingStateWriter).
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const API_CLIENT = 'integration-test';

    protected RecordingMailer $mailer;

    protected RecordingStateWriter $stateWriter;

    protected Settings $settings;

    protected ?TestOrder $order = null;

    /** State of the order before the first parcel: one the module may change */
    protected int $initialState = 0;

    protected function setUp(): void
    {
        $this->mailer = new RecordingMailer();
        $this->stateWriter = new RecordingStateWriter();
        $this->settings = new Settings();
    }

    protected function tearDown(): void
    {
        $this->order?->delete();
        $this->order = null;
    }

    /**
     * An order in a state the module may change (module settings), one line per quantity.
     */
    protected function createOrder(int ...$quantities): TestOrder
    {
        $states = array_values(array_diff(
            $this->settings->getChangeableStates(),
            [$this->settings->getShippedState(), $this->settings->getDeliveredState()]
        ));

        if (!$states) {
            self::markTestSkipped('No order state the module may change, apart from its own shipped and delivered states (module settings).');
        }

        $this->initialState = $states[0];

        return $this->order = TestOrder::create($this->initialState, $quantities);
    }

    protected function addHandler(bool $fromApi = true): AddShipmentHandler
    {
        return new AddShipmentHandler(new ShipmentRepository(), new OrderRepository(), $this->effects(), new OrderLock(), $this->journal($fromApi));
    }

    protected function editHandler(bool $fromApi = true): EditShipmentHandler
    {
        return new EditShipmentHandler(new ShipmentRepository(), $this->effects(), $this->shipmentLock(), $this->journal($fromApi), new RequestOrigin($this->apiClientContext($fromApi)));
    }

    protected function deleteHandler(bool $fromApi = true): DeleteShipmentHandler
    {
        return new DeleteShipmentHandler(new ShipmentRepository(), $this->effects(), $this->shipmentLock(), $this->journal($fromApi));
    }

    protected function parcel(string $externalReference): ?Shipment
    {
        return (new ShipmentRepository())->findByExternalReference($externalReference);
    }

    /**
     * @return string[] Actions of the module's journal for the test order, oldest first
     */
    protected function journalActions(): array
    {
        return array_reverse(array_column((new ShipmentLogRepository())->findByOrder($this->order->id, 1000), 'action'));
    }

    /**
     * @param array<int, int> $quantities id_order_detail => quantity
     */
    protected function declaration(string $reference, array $quantities, string $status = 'shipped'): AddShipmentCommand
    {
        $lines = [];

        foreach ($quantities as $lineId => $quantity) {
            $lines[] = ['orderDetailId' => $lineId, 'quantity' => $quantity];
        }

        return new AddShipmentCommand($reference, $this->order->id, 'DPD', 'TN-' . $reference, $status, $lines);
    }

    protected function reference(): string
    {
        return 'HAYTEST-' . bin2hex(random_bytes(6));
    }

    protected function assertRefused(int $code, callable $call): void
    {
        try {
            $call();
            self::fail('The request must be refused.');
        } catch (ShipmentConstraintException $e) {
            self::assertSame($code, $e->getCode(), $e->getMessage());
        }
    }

    protected function effects(): ShipmentEffects
    {
        $shipments = new ShipmentRepository();
        $orders = new OrderRepository();

        return new ShipmentEffects(
            $shipments,
            $orders,
            new OrderStatePolicy($shipments, $orders, $this->settings),
            new OrderMailRepository(),
            $this->mailer,
            $this->stateWriter,
            new ShipmentEffectsLog()
        );
    }

    private function shipmentLock(): ShipmentLock
    {
        return new ShipmentLock(new ShipmentRepository(), new OrderLock());
    }

    private function journal(bool $fromApi): ShipmentJournal
    {
        return new ShipmentJournal($this->apiClientContext($fromApi), new EmployeeContext(null, []), new ShipmentLogRepository());
    }

    /**
     * A call of the Admin API, or the back office (no API client).
     */
    private function apiClientContext(bool $fromApi): ApiClientContext
    {
        return new ApiClientContext($fromApi ? new ApiClient(0, self::API_CLIENT, [], null, (int) Configuration::get('PS_SHOP_DEFAULT')) : null);
    }
}
