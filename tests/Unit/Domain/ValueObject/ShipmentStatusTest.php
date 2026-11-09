<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit\Domain\ValueObject;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class ShipmentStatusTest extends TestCase
{
    public function testValuesOfTheContract(): void
    {
        self::assertSame(['shipped', 'in_transit', 'delivered', 'exception'], ShipmentStatus::values());
    }

    public function testAKnownStatusIsAccepted(): void
    {
        self::assertSame(ShipmentStatus::InTransit, ShipmentStatus::fromInput('in_transit'));
    }

    public function testAnUnknownStatusIsRefused(): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionCode(ShipmentConstraintException::INVALID_STATUS);

        ShipmentStatus::fromInput('lost');
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
