<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Integration\Application\CommandHandler;

use DrSoftFr\Module\HubAsYou\Application\Command\EditShipmentCommand;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConflictException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentLogRepository;
use DrSoftFr\Module\HubAsYou\Tests\Integration\Support\IntegrationTestCase;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class EditShipmentHandlerTest extends IntegrationTestCase
{
    public function testOnlyTheChangedFieldsAreInTheJournal(): void
    {
        $order = $this->createOrder(1);
        $reference = $this->reference();
        $id = $this->addHandler()->handle($this->declaration($reference, [$order->lineIds[0] => 1]))->getValue();

        $this->editHandler()->handle(new EditShipmentCommand($id, 'UPS', status: 'in_transit'));

        self::assertSame(['UPS', ShipmentStatus::InTransit], [$this->parcel($reference)->carrierName, $this->parcel($reference)->status]);
        self::assertSame(['added', 'edited'], $this->journalActions());
        $line = (new ShipmentLogRepository())->findByOrder($order->id, 1)[0];
        self::assertSame(['carrier_name' => ['DPD', 'UPS'], 'status' => ['shipped', 'in_transit']], $line['details']['changes']);
        self::assertSame(self::API_CLIENT, $line['api_client']);
    }

    public function testAnUpdateThatChangesNothingWritesNothing(): void
    {
        $order = $this->createOrder(1);
        $id = $this->addHandler()->handle($this->declaration($this->reference(), [$order->lineIds[0] => 1]))->getValue();

        $this->editHandler()->handle(new EditShipmentCommand($id, 'DPD'));

        self::assertSame(['added'], $this->journalActions());
    }

    public function testTheContentOfAParcelCannotBeChanged(): void
    {
        $order = $this->createOrder(2);
        $reference = $this->reference();
        $id = $this->addHandler()->handle($this->declaration($reference, [$order->lineIds[0] => 1]))->getValue();

        $this->assertRefused(ShipmentConstraintException::CONTENT_CHANGED, fn () => $this->editHandler()->handle(new EditShipmentCommand($id, 'UPS', lines: [['orderDetailId' => $order->lineIds[0], 'quantity' => 2]])));

        // Refused as a whole: the carrier sent with it is not saved either
        self::assertSame('DPD', $this->parcel($reference)->carrierName);
        self::assertSame(['added'], $this->journalActions());
    }

    public function testTheApiCannotMoveADeliveredParcelBackButTheBackOfficeCan(): void
    {
        $order = $this->createOrder(1);
        $reference = $this->reference();
        $id = $this->addHandler()->handle($this->declaration($reference, [$order->lineIds[0] => 1], 'delivered'))->getValue();

        foreach (['shipped', 'in_transit', 'exception'] as $status) {
            $this->assertRefused(ShipmentConstraintException::DELIVERED_IS_FINAL, fn () => $this->editHandler(fromApi: true)->handle(new EditShipmentCommand($id, status: $status)));
        }

        self::assertSame(ShipmentStatus::Delivered, $this->parcel($reference)->status);

        $this->editHandler(fromApi: false)->handle(new EditShipmentCommand($id, status: 'in_transit'));

        $parcel = $this->parcel($reference);
        self::assertSame(ShipmentStatus::InTransit, $parcel->status);
        self::assertNull($parcel->deliveredAt, 'A parcel that leaves "delivered" loses its delivery date');
    }

    public function testAChangeBasedOnAnOldVersionIsRefused(): void
    {
        $order = $this->createOrder(1);
        $reference = $this->reference();
        $id = $this->addHandler()->handle($this->declaration($reference, [$order->lineIds[0] => 1]))->getValue();
        $version = $this->parcel($reference)->version();

        // Changed by HubAsYou while an employee had the form open
        $this->editHandler()->handle(new EditShipmentCommand($id, 'UPS'));

        try {
            $this->editHandler(fromApi: false)->handle(new EditShipmentCommand($id, 'GLS', expectedVersion: $version));
            self::fail('A change based on an old version must be refused.');
        } catch (ShipmentConflictException) {
        }

        self::assertSame('UPS', $this->parcel($reference)->carrierName);
    }
}
