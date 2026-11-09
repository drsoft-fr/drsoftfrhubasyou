<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit\ApiPlatform;

use DrSoftFr\Module\HubAsYou\ApiPlatform\ShipmentErrorMessages;
use DrSoftFr\Module\HubAsYou\Domain\Exception\OrderBusyException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentNotFoundException;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentQuantities;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentRules;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class ShipmentErrorMessagesTest extends TestCase
{
    private const SECRET = "SQLSTATE[42S02] Table 't1_orders' doesn't exist in /var/www/html/Db.php";

    public function testTheMessageOfTheExceptionIsNeverSent(): void
    {
        $exception = new ShipmentConstraintException(self::SECRET, ShipmentConstraintException::NO_LINE);

        self::assertSame('A parcel needs at least one line.', ShipmentErrorMessages::for($exception));
    }

    public function testAnErrorMissingFromTheTableGetsTheFallback(): void
    {
        foreach ([
            new ShipmentConstraintException(self::SECRET),
            new ShipmentConstraintException(self::SECRET, 9999),
            new ShipmentException(self::SECRET),
            new ShipmentConstraintException(self::SECRET, ShipmentConstraintException::DELIVERED_MAIL_FAILED),
        ] as $exception) {
            self::assertSame(ShipmentErrorMessages::FALLBACK, ShipmentErrorMessages::for($exception));
        }
    }

    public function testASubclassIsNotInTheTableOfItsParent(): void
    {
        $exception = new class(self::SECRET, parameters: ['id' => 1]) extends ShipmentNotFoundException {
        };

        self::assertSame(ShipmentErrorMessages::FALLBACK, ShipmentErrorMessages::for($exception));
    }

    public function testValuesAreInserted(): void
    {
        self::assertSame('Parcel 12 not found.', ShipmentErrorMessages::for(ShipmentNotFoundException::withId(12)));
        self::assertStringContainsString('order 45 ', ShipmentErrorMessages::for(OrderBusyException::forOrder(45)));
    }

    public function testATextValueThatIsNotAFieldNameIsNeverInserted(): void
    {
        $exception = new ShipmentConstraintException('x', ShipmentConstraintException::CONTENT_CHANGED, parameters: ['fields' => [self::SECRET]]);

        self::assertSame(ShipmentErrorMessages::FALLBACK, ShipmentErrorMessages::for($exception));
    }

    public function testKnownFieldsAreKeptAndOthersDropped(): void
    {
        $exception = new ShipmentConstraintException('x', ShipmentConstraintException::CONTENT_CHANGED, parameters: ['fields' => ['orderId', self::SECRET, 'lines']]);
        $message = ShipmentErrorMessages::for($exception);

        self::assertStringContainsString('(orderId, lines)', $message);
        self::assertStringNotContainsString('SQLSTATE', $message);
    }

    public function testAMissingValueGivesTheFallback(): void
    {
        $exception = new ShipmentConstraintException('x', ShipmentConstraintException::UNKNOWN_ORDER);

        self::assertSame(ShipmentErrorMessages::FALLBACK, ShipmentErrorMessages::for($exception));
    }

    public function testTheDuplicateReferenceIsNotRepeated(): void
    {
        $message = ShipmentErrorMessages::for(ShipmentConstraintException::duplicateReference('<script>'));

        self::assertSame('The externalReference is already used by another parcel.', $message);
    }

    public function testARealRefusalGivesTheMessageOfTheContract(): void
    {
        try {
            ShipmentQuantities::assertFit(45, [8 => ['ordered' => 2, 'refunded' => 0, 'returned' => 0, 'virtual' => false]], [8 => 1], [8 => 2]);
            self::fail('Refusal expected');
        } catch (ShipmentConstraintException $e) {
            self::assertSame('Order line 8: 2 unit(s) ordered, 1 already in parcels, 2 sent.', ShipmentErrorMessages::for($e));
        }
    }

    public function testARefusedUrlGivesItsLimit(): void
    {
        try {
            ShipmentRules::url('ftp://example.com');
            self::fail('Refusal expected');
        } catch (ShipmentConstraintException $e) {
            self::assertSame('trackingUrl must be an http(s) URL of 255 characters at most.', ShipmentErrorMessages::for($e));
        }
    }

    public function testAForbiddenCharacterNamesTheFieldNotTheValue(): void
    {
        try {
            ShipmentRules::text('carrierName', "DPD\nBcc: x@example.com");
            self::fail('Refusal expected');
        } catch (ShipmentConstraintException $e) {
            self::assertSame('carrierName cannot contain line breaks, control characters or invisible characters.', ShipmentErrorMessages::for($e));
        }
    }

    /**
     * A new refusal code without a message would silently get the fallback.
     */
    public function testEveryRefusalCodeHasAMessageExceptTheBackOfficeOnes(): void
    {
        $backOfficeOnly = ['DELIVERED_MAIL_NOT_ALLOWED', 'DELIVERED_MAIL_FAILED', 'SHIPMENT_MAIL_NOT_ALLOWED', 'SHIPMENT_MAIL_FAILED'];
        $table = (new ReflectionClass(ShipmentErrorMessages::class))->getConstant('BY_CODE');

        foreach ((new ReflectionClass(ShipmentConstraintException::class))->getConstants() as $name => $code) {
            if (in_array($name, $backOfficeOnly, true)) {
                self::assertArrayNotHasKey($code, $table, $name);
            } else {
                self::assertArrayHasKey($code, $table, $name);
            }
        }
    }
}
