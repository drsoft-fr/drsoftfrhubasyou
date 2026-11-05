<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Configuration;

use Configuration;
use Order;

/**
 * Module settings (Modules › drSoft.fr HubAsYou › Configure), saved for the shop chosen
 * in the back office (Configuration::updateValue follows the shop context).
 */
final class Settings
{
    /** Order state set when the first parcel leaves (0: unchanged) */
    public const ORDER_STATE_SHIPPED = 'DRSOFT_FR_HUBASYOU_ORDER_STATE_SHIPPED';

    /** Order state set when every unit is in a delivered parcel (0: unchanged) */
    public const ORDER_STATE_DELIVERED = 'DRSOFT_FR_HUBASYOU_ORDER_STATE_DELIVERED';

    /** Send an e-mail to the customer for each parcel */
    public const SEND_MAIL = 'DRSOFT_FR_HUBASYOU_SEND_MAIL';

    /** Send an e-mail to the customer once every parcel is delivered */
    public const SEND_DELIVERED_MAIL = 'DRSOFT_FR_HUBASYOU_SEND_DELIVERED_MAIL';

    /** Order states the module may change the state from, comma-separated ids */
    public const CHANGEABLE_STATES = 'DRSOFT_FR_HUBASYOU_CHANGEABLE_STATES';

    /**
     * States proposed at installation, by their PrestaShop configuration key: paid and
     * not yet delivered (the merchant changes the list in the module settings).
     */
    private const DEFAULT_CHANGEABLE_STATE_KEYS = [
        'PS_OS_PAYMENT',
        'PS_OS_WS_PAYMENT',
        'PS_OS_OUTOFSTOCK_PAID',
        'PS_OS_PREPARATION',
        'PS_OS_SHIPPING',
    ];

    public const KEYS = [
        self::ORDER_STATE_SHIPPED,
        self::ORDER_STATE_DELIVERED,
        self::CHANGEABLE_STATES,
        self::SEND_MAIL,
        self::SEND_DELIVERED_MAIL,
    ];

    public static function install(): bool
    {
        return Configuration::updateValue(self::ORDER_STATE_SHIPPED, (int) Configuration::get('PS_OS_SHIPPING'))
            && Configuration::updateValue(self::ORDER_STATE_DELIVERED, (int) Configuration::get('PS_OS_DELIVERED'))
            && Configuration::updateValue(self::SEND_MAIL, 1)
            && Configuration::updateValue(self::SEND_DELIVERED_MAIL, 1)
            && self::setChangeableStates(self::getDefaultChangeableStates());
    }

    public static function uninstall(): bool
    {
        $result = true;

        foreach (self::KEYS as $key) {
            $result = Configuration::deleteByName($key) && $result;
        }

        return $result;
    }

    public static function getShippedState(?Order $order = null): int
    {
        return (int) self::get(self::ORDER_STATE_SHIPPED, $order);
    }

    public static function getDeliveredState(?Order $order = null): int
    {
        return (int) self::get(self::ORDER_STATE_DELIVERED, $order);
    }

    /**
     * @return int[]
     */
    public static function getChangeableStates(?Order $order = null): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) self::get(self::CHANGEABLE_STATES, $order)))));
    }

    /**
     * @param int[] $stateIds
     */
    public static function setChangeableStates(array $stateIds): bool
    {
        $stateIds = array_values(array_unique(array_filter(array_map('intval', $stateIds))));
        sort($stateIds);

        return Configuration::updateValue(self::CHANGEABLE_STATES, implode(',', $stateIds));
    }

    /**
     * @return int[]
     */
    public static function getDefaultChangeableStates(): array
    {
        return array_map(static fn (string $key): int => (int) Configuration::get($key), self::DEFAULT_CHANGEABLE_STATE_KEYS);
    }

    public static function isMailEnabled(?Order $order = null): bool
    {
        return (bool) self::get(self::SEND_MAIL, $order);
    }

    public static function isDeliveredMailEnabled(?Order $order = null): bool
    {
        return (bool) self::get(self::SEND_DELIVERED_MAIL, $order);
    }

    /**
     * Value for the shop of the order, whatever the shop of the request (an API call can
     * be made in the context of another shop, or of all of them); PrestaShop falls back on
     * the shop group, then on the global value. Without an order (configuration page):
     * value for the shop chosen in the back office. Not always a string: right after a
     * save, PrestaShop returns the value as it was given.
     */
    private static function get(string $key, ?Order $order): mixed
    {
        return Configuration::get($key, null, $order ? (int) $order->id_shop_group : null, $order ? (int) $order->id_shop : null);
    }
}
