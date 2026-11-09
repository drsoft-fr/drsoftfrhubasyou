<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit\Domain;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentQuantities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class ShipmentQuantitiesTest extends TestCase
{
    /**
     * @return array{ordered: int, refunded: int, returned: int, virtual: bool}
     */
    private static function line(int $ordered, int $refunded = 0, int $returned = 0, bool $virtual = false): array
    {
        return ['ordered' => $ordered, 'refunded' => $refunded, 'returned' => $returned, 'virtual' => $virtual];
    }

    public function testParcelUpToTheOrderedQuantityFits(): void
    {
        $this->expectNotToPerformAssertions();

        ShipmentQuantities::assertFit(45, [3 => self::line(3), 4 => self::line(1)], [3 => 1], [3 => 2, 4 => 1]);
    }

    public function testRefundedOrReturnedUnitsStillFitInAParcel(): void
    {
        $this->expectNotToPerformAssertions();

        ShipmentQuantities::assertFit(45, [3 => self::line(2, refunded: 1, returned: 1)], [], [3 => 2]);
    }

    public function testParcelOverTheOrderedQuantityIsRefused(): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionMessage('Order line 3: 2 unit(s) ordered, 1 already in parcels, 2 sent.');

        ShipmentQuantities::assertFit(45, [3 => self::line(2)], [3 => 1], [3 => 2]);
    }

    public function testLineOfAnotherOrderIsRefused(): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionMessage('Order line 9 does not belong to order 45.');

        ShipmentQuantities::assertFit(45, [3 => self::line(2)], [], [9 => 1]);
    }

    public function testVirtualProductIsRefused(): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionMessage('Order line 3 is a virtual product');

        ShipmentQuantities::assertFit(45, [3 => self::line(1, virtual: true)], [], [3 => 1]);
    }

    /**
     * @return array<string, array{array<int, array{ordered: int, refunded: int, returned: int, virtual: bool}>, array<int, int>, bool}>
     */
    public static function deliveredProvider(): array
    {
        return [
            'everything delivered' => [[3 => self::line(2), 4 => self::line(1)], [3 => 2, 4 => 1], true],
            'a unit still due' => [[3 => self::line(2), 4 => self::line(1)], [3 => 1, 4 => 1], false],
            'a line not delivered at all' => [[3 => self::line(2), 4 => self::line(1)], [3 => 2], false],
            '3 ordered, 2 delivered, the 3rd refunded' => [[3 => self::line(3, refunded: 1)], [3 => 2], true],
            '3 ordered, 2 delivered, the 3rd returned' => [[3 => self::line(3, returned: 1)], [3 => 2], true],
            '3 ordered, 1 delivered, 1 refunded, 1 still due' => [[3 => self::line(3, refunded: 1)], [3 => 1], false],
            'a line entirely refunded, the other delivered' => [[3 => self::line(1, refunded: 1), 4 => self::line(1)], [4 => 1], true],
            'delivered then returned' => [[3 => self::line(2, returned: 1)], [3 => 2], true],
            'a virtual product is not waited for' => [[3 => self::line(1), 4 => self::line(1, virtual: true)], [3 => 1], true],
            'only virtual products: nothing delivered' => [[4 => self::line(1, virtual: true)], [], false],
            'entirely refunded: nothing delivered' => [[3 => self::line(2, refunded: 2)], [], false],
            'no line' => [[], [], false],
        ];
    }

    /**
     * @param array<int, array{ordered: int, refunded: int, returned: int, virtual: bool}> $orderLines
     * @param array<int, int> $delivered
     */
    #[DataProvider('deliveredProvider')]
    public function testAreAllDelivered(array $orderLines, array $delivered, bool $expected): void
    {
        self::assertSame($expected, ShipmentQuantities::areAllDelivered($orderLines, $delivered));
    }
}
