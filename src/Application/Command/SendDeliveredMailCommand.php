<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Command;

/**
 * Sends the "order delivered" e-mail at the merchant's request (back office): it failed
 * on the last parcel, or the customer did not receive it. Sent again even if already sent.
 */
final class SendDeliveredMailCommand
{
    public function __construct(public readonly int $orderId)
    {
    }
}
