<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit\Domain\ValueObject;

use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShipmentStatusTest extends TestCase
{
    public function testValuesOfTheContract(): void
    {
        self::assertSame(['shipped', 'in_transit', 'delivered', 'exception'], ShipmentStatus::values());
    }

    /**
     * @return array<string, array{ShipmentStatus, bool, bool}>
     */
    public static function statusProvider(): array
    {
        return [
            'shipped' => [ShipmentStatus::Shipped, true, true],
            'in transit' => [ShipmentStatus::InTransit, true, true],
            'delivered: left the warehouse, no longer travelling' => [ShipmentStatus::Delivered, true, false],
            'exception' => [ShipmentStatus::Exception, false, false],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testStatus(ShipmentStatus $status, bool $onItsWay, bool $travelling): void
    {
        self::assertSame($onItsWay, $status->isOnItsWay());
        self::assertSame($travelling, $status->isTravelling());
    }
}
