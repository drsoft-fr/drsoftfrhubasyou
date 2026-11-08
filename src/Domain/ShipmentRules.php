<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;

/**
 * Rules on the fields of a parcel, checked by the commands: the same for the
 * Admin API (which also validates its payload) and for the back office.
 */
final class ShipmentRules
{
    public const TEXT_MAX_LENGTH = 64;
    public const URL_MAX_LENGTH = 255;

    /**
     * Control characters (line break, tab, NUL, DEL…), invisible format characters
     * (zero width space, right-to-left override…) and line or paragraph separators.
     */
    private const FORBIDDEN_CHARACTERS = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    /**
     * One line of visible text: what the customer reads in the e-mail and in the account
     * is what was saved. The spaces and line breaks around it are removed.
     *
     * @throws ShipmentConstraintException
     */
    public static function text(string $field, string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new ShipmentConstraintException(sprintf('%s cannot be empty.', $field), ShipmentConstraintException::EMPTY_FIELD, parameters: ['fields' => [$field]]);
        }

        if (!mb_check_encoding($value, 'UTF-8') || preg_match(self::FORBIDDEN_CHARACTERS, $value) !== 0) {
            throw new ShipmentConstraintException(sprintf('%s cannot contain line breaks, control characters or invisible characters.', $field), ShipmentConstraintException::FORBIDDEN_CHARACTER, parameters: ['fields' => [$field]]);
        }

        if (mb_strlen($value) > self::TEXT_MAX_LENGTH) {
            throw new ShipmentConstraintException(sprintf('%s cannot exceed %d characters.', $field, self::TEXT_MAX_LENGTH), ShipmentConstraintException::TOO_LONG, parameters: ['fields' => [$field], 'max' => self::TEXT_MAX_LENGTH]);
        }

        return $value;
    }

    /**
     * Empty value: no tracking page (null).
     *
     * @throws ShipmentConstraintException
     */
    public static function url(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if (mb_strlen($value) > self::URL_MAX_LENGTH
            || !in_array($scheme, ['http', 'https'], true)
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            throw new ShipmentConstraintException(sprintf('trackingUrl must be an http(s) URL of %d characters at most.', self::URL_MAX_LENGTH), ShipmentConstraintException::INVALID_URL, parameters: ['max' => self::URL_MAX_LENGTH]);
        }

        return $value;
    }

    /**
     * Lines of a parcel, by order line. The same order line sent twice is added up.
     *
     * @param array<int, array{orderDetailId?: int, quantity?: int}> $lines
     *
     * @return array<int, int> id_order_detail => quantity
     *
     * @throws ShipmentConstraintException
     */
    public static function lines(array $lines): array
    {
        $quantities = [];

        foreach ($lines as $line) {
            $orderDetailId = (int) ($line['orderDetailId'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($orderDetailId <= 0 || $quantity <= 0) {
                throw new ShipmentConstraintException('Each line needs a positive orderDetailId and quantity.', ShipmentConstraintException::INVALID_LINE);
            }

            $quantities[$orderDetailId] = ($quantities[$orderDetailId] ?? 0) + $quantity;
        }

        if (!$quantities) {
            throw new ShipmentConstraintException('A parcel needs at least one line.', ShipmentConstraintException::NO_LINE);
        }

        return $quantities;
    }

    /**
     * The content of a parcel cannot change: refused rather than ignored, so the sender
     * does not believe it was applied. Null: not sent. Sending the saved values again
     * (a whole parcel) is accepted, nothing would be lost.
     *
     * @param array<int, int>|null $lines id_order_detail => quantity
     * @param array<int, int> $savedLines id_order_detail => quantity
     *
     * @throws ShipmentConstraintException
     */
    public static function assertContentUnchanged(
        ?string $externalReference,
        ?int $orderId,
        ?array $lines,
        string $savedExternalReference,
        int $savedOrderId,
        array $savedLines,
    ): void {
        $changed = array_keys(array_filter([
            'externalReference' => $externalReference !== null && $externalReference !== $savedExternalReference,
            'orderId' => $orderId !== null && $orderId !== $savedOrderId,
            'lines' => $lines !== null && $lines != $savedLines,
        ]));

        if ($changed) {
            throw new ShipmentConstraintException(sprintf(
                'The content of a parcel cannot be changed (%s): delete the parcel and declare it again.',
                implode(', ', $changed)
            ), ShipmentConstraintException::CONTENT_CHANGED, parameters: ['fields' => $changed]);
        }
    }
}
