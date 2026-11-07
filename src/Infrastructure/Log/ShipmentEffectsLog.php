<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Log;

use DrSoftFr\Module\HubAsYou\Infrastructure\Configuration\Settings;
use PrestaShopLogger;
use Throwable;

/**
 * What went wrong after a parcel was saved, in the PrestaShop logs (Advanced parameters ›
 * Logs), attached to the order. The parcel stays saved: these lines are how the merchant
 * learns about it.
 */
class ShipmentEffectsLog
{
    private const SEVERITY_WARNING = 2;
    private const SEVERITY_ERROR = 3;

    /**
     * @param string $subject What failed, for example "parcel #12"
     */
    public function failure(string $subject, Throwable $t, int $orderId): void
    {
        PrestaShopLogger::addLog(
            sprintf('%s - %s - %s', Settings::MODULE_NAME, $subject, $t->getMessage()),
            self::SEVERITY_ERROR,
            (int) $t->getCode(),
            'Order',
            $orderId,
            true
        );
    }

    public function stateNotChanged(int $orderId, int $targetState, int $currentState): void
    {
        PrestaShopLogger::addLog(
            sprintf('%s - order #%d: state not changed to %d, the current state %d is not one the module may change (module settings)', Settings::MODULE_NAME, $orderId, $targetState, $currentState),
            self::SEVERITY_WARNING,
            null,
            'Order',
            $orderId,
            true
        );
    }
}
