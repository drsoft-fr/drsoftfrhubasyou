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

use DrSoftFr\Module\HubAsYou\Application\Command\AddShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Command\EditShipmentCommand;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentLogRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use DrSoftFr\Module\HubAsYou\Tests\Integration\Support\IntegrationTestCase;
use DrSoftFr\Module\HubAsYou\Tests\Integration\Support\TestOrder;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class AddShipmentHandlerTest extends IntegrationTestCase
{
    public function testADeclaredParcelIsSavedWithItsJournalLineAndItsEffects(): void
    {
        $order = $this->createOrder(2, 1);
        $reference = $this->reference();

        $id = $this->addHandler()->handle($this->declaration($reference, [$order->lineIds[0] => 2]))->getValue();

        $parcel = (new ShipmentRepository())->find($id);
        self::assertNotNull($parcel);
        self::assertSame([$reference, $order->id, 'DPD', ShipmentStatus::Shipped], [$parcel->externalReference, $parcel->orderId, $parcel->carrierName, $parcel->status]);
        self::assertSame([$order->lineIds[0] => 2], $parcel->quantities());
        self::assertSame(['added'], $this->journalActions());

        // Part of the order is on its way: the shipped state, the e-mail if enabled
        self::assertSame([$this->settings->getShippedState()], $this->stateWriter->states);
        $mailEnabled = $this->settings->isMailEnabled((new OrderRepository())->find($order->id));
        self::assertSame($mailEnabled ? [$reference] : [], $this->mailer->shipmentMails);
        self::assertSame($mailEnabled, $parcel->mailSentAt !== null, 'The e-mail is marked as sent only when it was');
        self::assertSame([], $this->mailer->deliveredMails);
    }

    public function testTheSameDeclarationSentAgainGivesTheSameParcel(): void
    {
        $order = $this->createOrder(2);
        $reference = $this->reference();
        $first = $this->addHandler()->handle($this->declaration($reference, [$order->lineIds[0] => 1]))->getValue();

        $again = $this->addHandler()->handle($this->declaration($reference, [$order->lineIds[0] => 1]))->getValue();

        self::assertSame($first, $again);
        self::assertCount(1, (new ShipmentRepository())->findByOrder($order->id));
        self::assertSame(['added', 'received_again'], $this->journalActions());
        self::assertLessThanOrEqual(1, count($this->mailer->shipmentMails), 'The e-mail of a parcel is sent once');
    }

    public function testAnotherContentUnderAUsedReferenceIsRefused(): void
    {
        $order = $this->createOrder(2);
        $reference = $this->reference();
        $this->addHandler()->handle($this->declaration($reference, [$order->lineIds[0] => 1]));

        $this->assertRefused(ShipmentConstraintException::DUPLICATE_REFERENCE, fn () => $this->addHandler()->handle($this->declaration($reference, [$order->lineIds[0] => 2])));

        self::assertSame([$order->lineIds[0] => 1], $this->parcel($reference)->quantities());
        self::assertSame(['added'], $this->journalActions());
    }

    public function testMoreUnitsThanOrderedAreRefusedAndNothingIsWritten(): void
    {
        $order = $this->createOrder(2, 1);
        $reference = $this->reference();

        $this->assertRefused(ShipmentConstraintException::QUANTITY_EXCEEDED, fn () => $this->addHandler()->handle($this->declaration($reference, [$order->lineIds[1] => 2])));

        self::assertNull($this->parcel($reference));
        self::assertSame([], $this->journalActions());
        self::assertSame([], $this->stateWriter->states);
        self::assertSame([], $this->mailer->shipmentMails);
    }

    public function testUnitsAlreadyInAParcelCount(): void
    {
        $order = $this->createOrder(2);
        $this->addHandler()->handle($this->declaration($this->reference(), [$order->lineIds[0] => 2]));

        $this->assertRefused(ShipmentConstraintException::QUANTITY_EXCEEDED, fn () => $this->addHandler()->handle($this->declaration($this->reference(), [$order->lineIds[0] => 1])));
    }

    public function testALineOfAnotherOrderIsRefused(): void
    {
        $order = $this->createOrder(1);
        $other = TestOrder::create($this->initialState, [1]);

        try {
            $this->assertRefused(ShipmentConstraintException::LINE_NOT_IN_ORDER, fn () => $this->addHandler()->handle($this->declaration($this->reference(), [$other->lineIds[0] => 1])));
        } finally {
            $other->delete();
        }
    }

    public function testAnUnknownOrderIsRefused(): void
    {
        $order = $this->createOrder(1);

        $this->assertRefused(ShipmentConstraintException::UNKNOWN_ORDER, fn () => $this->addHandler()->handle(new AddShipmentCommand($this->reference(), $order->id + 1_000_000, 'DPD', 'TN', 'shipped', [['orderDetailId' => $order->lineIds[0], 'quantity' => 1]])));
    }

    public function testAParcelIsNeverSavedWithoutItsJournalLine(): void
    {
        $order = $this->createOrder(1);
        $reference = $this->reference();
        $table = _DB_PREFIX_ . ShipmentLogRepository::TABLE;
        $db = \Db::getInstance();

        // The journal cannot be written: a real failure of the database, not a test double
        $db->execute('RENAME TABLE `' . $table . '` TO `' . $table . '_off`');

        try {
            $this->addHandler()->handle($this->declaration($reference, [$order->lineIds[0] => 1]));
            self::fail('The parcel must be refused when its journal line cannot be written.');
        } catch (ShipmentException $e) {
            self::assertNotInstanceOf(ShipmentConstraintException::class, $e, 'A technical failure, not a refusal of the data');
        } finally {
            $db->execute('RENAME TABLE `' . $table . '_off` TO `' . $table . '`');
        }

        self::assertNull($this->parcel($reference));
        self::assertSame([], $this->stateWriter->states);
        self::assertSame([], $this->mailer->shipmentMails);
    }

    public function testAnOrderEntirelyDeliveredGetsItsStateAndItsEmailOnce(): void
    {
        $order = $this->createOrder(1, 1);
        $first = $this->reference();
        $second = $this->reference();
        $this->addHandler()->handle($this->declaration($first, [$order->lineIds[0] => 1], 'delivered'));

        self::assertSame([], $this->mailer->deliveredMails, 'Part of the order is not delivered yet');
        self::assertNotContains($this->settings->getDeliveredState(), $this->stateWriter->states);

        $id = $this->addHandler()->handle($this->declaration($second, [$order->lineIds[1] => 1], 'delivered'))->getValue();
        // Saved again later (carrier corrected): no second e-mail
        $this->editHandler()->handle(new EditShipmentCommand($id, 'UPS'));

        self::assertSame($this->settings->getDeliveredState(), end($this->stateWriter->states));
        $mailEnabled = $this->settings->isDeliveredMailEnabled((new OrderRepository())->find($order->id));
        self::assertSame($mailEnabled ? [[$first, $second]] : [], $this->mailer->deliveredMails);
    }
}
