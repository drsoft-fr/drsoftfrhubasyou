<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Log;

use Throwable;

/**
 * What went wrong after a parcel was saved, in the PrestaShop logs (Advanced parameters ›
 * Logs), attached to the order. The parcel stays saved: these lines are how the merchant
 * learns about it.
 */
class ShipmentEffectsLog
{
    /**
     * @param string $subject What failed, for example "parcel #12"
     */
    public function failure(string $subject, Throwable $t, int $orderId): void
    {
        ModuleLog::error(sprintf('%s - %s', $subject, ModuleLog::describe($t)), $orderId);
    }

    public function stateNotChanged(int $orderId, int $targetState, int $currentState): void
    {
        ModuleLog::warning(sprintf('order #%d: state not changed to %d, the current state %d is not one the module may change (module settings)', $orderId, $targetState, $currentState), $orderId);
    }
}
