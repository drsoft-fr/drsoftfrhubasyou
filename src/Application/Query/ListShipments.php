<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Query;

/**
 * Paginated list of parcels, filtered by order or by HubAsYou reference.
 *
 * The Admin API passes the pagination (offset, limit) and the GET parameters
 * (?orderId=…&externalReference=…), the latter as strings.
 *
 * Without ?limit, PrestaShop passes its own default (10): DEFAULT_LIMIT only applies to
 * a limit that is not a positive number (the value PrestaShop announces for ?limit=0 too).
 */
final class ListShipments
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;

    public readonly ?int $orderId;

    public readonly ?string $externalReference;

    public readonly int $offset;

    public readonly int $limit;

    public function __construct(
        int|string|null $orderId = null,
        ?string $externalReference = null,
        int|string|null $offset = null,
        int|string|null $limit = null,
    ) {
        $this->orderId = $orderId !== null && (int) $orderId > 0 ? (int) $orderId : null;
        $this->externalReference = $externalReference !== null && trim($externalReference) !== '' ? trim($externalReference) : null;
        $this->offset = max(0, (int) $offset);
        $this->limit = (int) $limit > 0 ? min(self::MAX_LIMIT, (int) $limit) : self::DEFAULT_LIMIT;
    }
}
