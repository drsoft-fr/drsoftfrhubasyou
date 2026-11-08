<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Context;

use PrestaShop\PrestaShop\Core\Context\ApiClientContext;

/**
 * Who is calling, as PrestaShop authenticated it: never read from the request body.
 */
class RequestOrigin
{
    public function __construct(
        private readonly ApiClientContext $apiClientContext,
    ) {
    }

    /**
     * True for a call of the Admin API (HubAsYou), false for the back office.
     */
    public function isApiClient(): bool
    {
        return $this->apiClientContext->getApiClient() !== null;
    }
}
