<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit\Domain;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use PHPUnit\Framework\TestCase;

final class ShipmentTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-06 12:00:00');
    }

    private function parcel(): Shipment
    {
        return Shipment::declare('HAY-1', 45, 'DPD', 'TN1', 'https://example.com/1', ShipmentStatus::Shipped, null, null, [101 => 1, 102 => 2], $this->now);
    }

    public function testANewParcelHasItsLinesAndDates(): void
    {
        $parcel = $this->parcel();

        self::assertSame(Shipment::NEW_ID, $parcel->id);
        self::assertSame([101 => 1, 102 => 2], $parcel->quantities());
        self::assertEquals($this->now, $parcel->shippedAt);
        self::assertNull($parcel->deliveredAt);
        self::assertNull($parcel->mailSentAt);
    }

    public function testNullLeavesAValueUnchanged(): void
    {
        $parcel = $this->parcel()->withChanges(null, null, null, null, null, null, $this->now);

        self::assertSame($this->parcel()->changeableValues(), $parcel->changeableValues());
    }

    public function testChangesAreApplied(): void
    {
        $parcel = $this->parcel()->withChanges('UPS', 'TN2', 'https://example.com/2', ShipmentStatus::InTransit, null, null, $this->now);

        self::assertSame('UPS', $parcel->carrierName);
        self::assertSame('TN2', $parcel->trackingNumber);
        self::assertSame('https://example.com/2', $parcel->trackingUrl);
        self::assertSame(ShipmentStatus::InTransit, $parcel->status);
    }

    public function testAnEmptyUrlRemovesTheTrackingPage(): void
    {
        self::assertNull($this->parcel()->withChanges(null, null, '', null, null, null, $this->now)->trackingUrl);
    }

    public function testDeliveringSetsTheDateAndLeavingDeliveredRemovesIt(): void
    {
        $delivered = $this->parcel()->withChanges(null, null, null, ShipmentStatus::Delivered, null, null, $this->now);

        self::assertEquals($this->now, $delivered->deliveredAt);
        self::assertNull($delivered->withChanges(null, null, null, ShipmentStatus::InTransit, null, null, $this->now)->deliveredAt);
    }

    public function testChangesDoNotTouchTheContentOrTheEmailDate(): void
    {
        $saved = new Shipment(12, 'HAY-1', 45, 'DPD', 'TN1', null, ShipmentStatus::Shipped, $this->now, null, $this->now, $this->parcel()->lines);
        $changed = $saved->withChanges('UPS', null, null, null, null, null, $this->now);

        self::assertSame(12, $changed->id);
        self::assertSame('HAY-1', $changed->externalReference);
        self::assertSame(45, $changed->orderId);
        self::assertSame($saved->lines, $changed->lines);
        self::assertEquals($this->now, $changed->mailSentAt);
    }

    public function testADifferentContentIsRefused(): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionCode(ShipmentConstraintException::CONTENT_CHANGED);

        $this->parcel()->assertContentUnchanged(null, null, [101 => 2]);
    }

    public function testTheSameContentIsAccepted(): void
    {
        $this->parcel()->assertContentUnchanged('HAY-1', 45, [102 => 2, 101 => 1]);

        $this->addToAssertionCount(1);
    }

    public function testTheVersionFollowsEveryChangeableValue(): void
    {
        $parcel = $this->parcel();

        self::assertSame($parcel->version(), $this->parcel()->version());

        foreach ([
            $parcel->withChanges('UPS', null, null, null, null, null, $this->now),
            $parcel->withChanges(null, 'TN2', null, null, null, null, $this->now),
            $parcel->withChanges(null, null, '', null, null, null, $this->now),
            $parcel->withChanges(null, null, null, ShipmentStatus::InTransit, null, null, $this->now),
            $parcel->withChanges(null, null, null, null, new DateTimeImmutable('2026-11-01 12:00:00'), null, $this->now),
        ] as $changed) {
            self::assertNotSame($parcel->version(), $changed->version());
        }
    }
}
