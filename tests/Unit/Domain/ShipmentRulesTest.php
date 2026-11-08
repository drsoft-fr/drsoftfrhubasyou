<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit\Domain;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShipmentRulesTest extends TestCase
{
    public function testTextIsTrimmed(): void
    {
        self::assertSame('DPD', ShipmentRules::text('carrierName', '  DPD '));
    }

    public function testTextKeepsSpecialCharacters(): void
    {
        self::assertSame('Colis <b>3</b> & "Express"', ShipmentRules::text('carrierName', 'Colis <b>3</b> & "Express"'));
    }

    public function testEmptyTextIsRefused(): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionCode(ShipmentConstraintException::EMPTY_FIELD);
        $this->expectExceptionMessage('carrierName cannot be empty.');

        ShipmentRules::text('carrierName', "  \n ");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenCharacterProvider(): array
    {
        return [
            'line feed' => ["DPD\nBcc: x@example.com"],
            'carriage return' => ["DPD\rX"],
            'NUL' => ["DP\0D"],
            'tab' => ["DPD\tX"],
            'vertical tab' => ["DPD\vX"],
            'DEL' => ["DPD\x7FX"],
            'next line (C1)' => ["DPD\u{85}X"],
            'right-to-left override' => ["DPD\u{202E}X"],
            'zero width space' => ["DPD\u{200B}X"],
            'byte order mark' => ["DPD\u{FEFF}X"],
            'line separator' => ["DPD\u{2028}X"],
            'paragraph separator' => ["DPD\u{2029}X"],
            'invalid UTF-8' => ["DPD\xC3X"],
        ];
    }

    #[DataProvider('forbiddenCharacterProvider')]
    public function testForbiddenCharacterIsRefused(string $value): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionCode(ShipmentConstraintException::FORBIDDEN_CHARACTER);
        $this->expectExceptionMessage('trackingNumber cannot contain line breaks, control characters or invisible characters.');

        ShipmentRules::text('trackingNumber', $value);
    }

    public function testLineBreaksAroundTextAreRemoved(): void
    {
        self::assertSame('DPD Express', ShipmentRules::text('carrierName', "\r\n DPD Express\n"));
    }

    public function testVisibleUnicodeIsKept(): void
    {
        self::assertSame('Société Générale 🚚 №5', ShipmentRules::text('carrierName', 'Société Générale 🚚 №5'));
    }

    public function testTextLengthIsCountedInCharactersNotBytes(): void
    {
        $value = str_repeat('é', ShipmentRules::TEXT_MAX_LENGTH);

        self::assertSame($value, ShipmentRules::text('carrierName', $value));
    }

    public function testTooLongTextIsRefused(): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionCode(ShipmentConstraintException::TOO_LONG);

        ShipmentRules::text('carrierName', str_repeat('a', ShipmentRules::TEXT_MAX_LENGTH + 1));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function noUrlProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'spaces' => ['   '],
        ];
    }

    #[DataProvider('noUrlProvider')]
    public function testNoUrlMeansNoTrackingPage(?string $value): void
    {
        self::assertNull(ShipmentRules::url($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validUrlProvider(): array
    {
        $prefix = 'https://example.com/';

        return [
            'http' => ['http://tracking.example.com/250123'],
            'https with a query' => ['https://tracking.example.com/?a=1&b=2'],
            'scheme in capitals' => ['HTTPS://tracking.example.com/250123'],
            'longest allowed' => [$prefix . str_repeat('a', ShipmentRules::URL_MAX_LENGTH - strlen($prefix))],
        ];
    }

    #[DataProvider('validUrlProvider')]
    public function testValidUrlIsKeptAsItIs(string $value): void
    {
        self::assertSame($value, ShipmentRules::url(" $value "));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrlProvider(): array
    {
        $prefix = 'https://example.com/';

        return [
            'ftp' => ['ftp://example.com/file'],
            'javascript' => ['javascript:alert(1)'],
            'no scheme' => ['example.com/250123'],
            'not a URL' => ['https://'],
            'too long' => [$prefix . str_repeat('a', ShipmentRules::URL_MAX_LENGTH - strlen($prefix) + 1)],
        ];
    }

    #[DataProvider('invalidUrlProvider')]
    public function testInvalidUrlIsRefused(string $value): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionCode(ShipmentConstraintException::INVALID_URL);

        ShipmentRules::url($value);
    }

    public function testLinesAreIndexedByOrderLine(): void
    {
        self::assertSame([3 => 2, 4 => 1], ShipmentRules::lines([
            ['orderDetailId' => 3, 'quantity' => 2],
            ['orderDetailId' => 4, 'quantity' => 1],
        ]));
    }

    public function testSameOrderLineSentTwiceIsAddedUp(): void
    {
        self::assertSame([3 => 3], ShipmentRules::lines([
            ['orderDetailId' => 3, 'quantity' => 1],
            ['orderDetailId' => 3, 'quantity' => 2],
        ]));
    }

    public function testParcelWithoutLinesIsRefused(): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionMessage('A parcel needs at least one line.');

        ShipmentRules::lines([]);
    }

    /**
     * @return array<string, array{array<string, int>}>
     */
    public static function invalidLineProvider(): array
    {
        return [
            'order line missing' => [['quantity' => 1]],
            'quantity missing' => [['orderDetailId' => 3]],
            'order line at zero' => [['orderDetailId' => 0, 'quantity' => 1]],
            'negative order line' => [['orderDetailId' => -3, 'quantity' => 1]],
            'quantity at zero' => [['orderDetailId' => 3, 'quantity' => 0]],
            'negative quantity' => [['orderDetailId' => 3, 'quantity' => -1]],
        ];
    }

    /**
     * @param array<string, int> $line
     */
    #[DataProvider('invalidLineProvider')]
    public function testInvalidLineIsRefused(array $line): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionMessage('Each line needs a positive orderDetailId and quantity.');

        ShipmentRules::lines([$line]);
    }

    public function testContentNotSentIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        ShipmentRules::assertContentUnchanged(null, null, null, 'HAY-1', 45, [3 => 2]);
    }

    public function testSameContentSentAgainIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        ShipmentRules::assertContentUnchanged('HAY-1', 45, [3 => 2, 4 => 1], 'HAY-1', 45, [3 => 2, 4 => 1]);
    }

    public function testSameLinesInAnotherOrderAreAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        ShipmentRules::assertContentUnchanged(null, null, [4 => 1, 3 => 2], 'HAY-1', 45, [3 => 2, 4 => 1]);
    }

    /**
     * @return array<string, array{?string, ?int, ?array<int, int>, string}>
     */
    public static function changedContentProvider(): array
    {
        return [
            'another reference' => ['HAY-2', null, null, '(externalReference)'],
            'reference differing by case only' => ['hay-1', null, null, '(externalReference)'],
            'another order' => [null, 46, null, '(orderId)'],
            'another quantity' => [null, null, [3 => 1, 4 => 1], '(lines)'],
            'a line less' => [null, null, [3 => 2], '(lines)'],
            'a line more' => [null, null, [3 => 2, 4 => 1, 5 => 1], '(lines)'],
            'no line at all' => [null, null, [], '(lines)'],
            'several fields' => ['HAY-2', 46, [3 => 1], '(externalReference, orderId, lines)'],
        ];
    }

    /**
     * @param array<int, int>|null $lines
     */
    #[DataProvider('changedContentProvider')]
    public function testChangedContentIsRefusedAndNamed(?string $reference, ?int $orderId, ?array $lines, string $named): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionMessage($named);

        ShipmentRules::assertContentUnchanged($reference, $orderId, $lines, 'HAY-1', 45, [3 => 2, 4 => 1]);
    }
}
