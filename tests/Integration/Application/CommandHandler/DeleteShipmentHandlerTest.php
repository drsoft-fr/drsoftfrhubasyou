<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Integration\Application\CommandHandler;

use DrSoftFr\Module\HubAsYou\Application\Command\DeleteShipmentCommand;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentNotFoundException;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentLogRepository;
use DrSoftFr\Module\HubAsYou\Tests\Integration\Support\IntegrationTestCase;

final class DeleteShipmentHandlerTest extends IntegrationTestCase
{
    public function testADeletedParcelStaysInTheJournalAndFreesItsUnits(): void
    {
        $order = $this->createOrder(1);
        $lost = $this->reference();
        $id = $this->addHandler()->handle($this->declaration($lost, [$order->lineIds[0] => 1], 'exception'))->getValue();

        // The replacement is refused while the lost parcel holds the unit
        $replacement = $this->reference();
        $this->assertRefused(ShipmentConstraintException::QUANTITY_EXCEEDED, fn () => $this->addHandler()->handle($this->declaration($replacement, [$order->lineIds[0] => 1])));

        $this->deleteHandler()->handle(new DeleteShipmentCommand($id));

        self::assertNull($this->parcel($lost));
        $line = (new ShipmentLogRepository())->findByOrder($order->id, 1)[0];
        self::assertSame(['deleted', $lost, 'exception'], [$line['action'], $line['external_reference'], $line['details']['status']]);
        self::assertSame(1, $line['details']['lines'][0]['quantity'], 'The journal keeps the content of the deleted parcel');

        $this->addHandler()->handle($this->declaration($replacement, [$order->lineIds[0] => 1]));
        self::assertNotNull($this->parcel($replacement));
        self::assertSame(['added', 'deleted', 'added'], $this->journalActions());
    }

    public function testAnUnknownParcelIsNotFound(): void
    {
        $this->createOrder(1);

        $this->expectException(ShipmentNotFoundException::class);

        $this->deleteHandler()->handle(new DeleteShipmentCommand(2_100_000_000));
    }
}
