<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Query;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;

/**
 * Paginated list of parcels, filtered by order or by HubAsYou reference.
 *
 * The Admin API passes the pagination (offset, limit) and the GET parameters as they are in
 * the URL: strings, or its own default values when they are missing. A wrong value is
 * refused, never ignored: ignoring ?orderId=abc would answer with the parcels of every order.
 */
final class ListShipments
{
    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT = 100;

    public readonly ?int $orderId;

    public readonly ?string $externalReference;

    public readonly int $offset;

    public readonly int $limit;

    /**
     * @throws ShipmentConstraintException
     */
    public function __construct(
        int|string|null $orderId = null,
        ?string $externalReference = null,
        int|string|null $offset = null,
        int|string|null $limit = null,
    ) {
        $this->orderId = $orderId === null ? null : self::wholeNumber('orderId', $orderId, 1);
        $this->externalReference = $externalReference === null ? null : self::text('externalReference', $externalReference);
        $this->offset = $offset === null ? 0 : self::wholeNumber('offset', $offset, 0);
        $this->limit = $limit === null ? self::DEFAULT_LIMIT : min(self::MAX_LIMIT, self::wholeNumber('limit', $limit, 1));
    }

    /**
     * @throws ShipmentConstraintException
     */
    private static function wholeNumber(string $field, int|string $value, int $minimum): int
    {
        if (!preg_match('/^\d+$/D', (string) $value) || (int) $value < $minimum) {
            throw self::refused($field);
        }

        return (int) $value;
    }

    /**
     * @throws ShipmentConstraintException
     */
    private static function text(string $field, string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw self::refused($field);
        }

        return $value;
    }

    private static function refused(string $field): ShipmentConstraintException
    {
        return new ShipmentConstraintException(sprintf('The value of %s is not valid.', $field), ShipmentConstraintException::INVALID_LIST_PARAMETER, parameters: ['fields' => [$field]]);
    }
}
