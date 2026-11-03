<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Configuration;

use Configuration;

/**
 * Module settings (Modules › drSoft.fr HubAsYou › Configure).
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

    public const KEYS = [
        self::ORDER_STATE_SHIPPED,
        self::ORDER_STATE_DELIVERED,
        self::SEND_MAIL,
        self::SEND_DELIVERED_MAIL,
    ];

    public static function install(): bool
    {
        return Configuration::updateValue(self::ORDER_STATE_SHIPPED, (int) Configuration::get('PS_OS_SHIPPING'))
            && Configuration::updateValue(self::ORDER_STATE_DELIVERED, (int) Configuration::get('PS_OS_DELIVERED'))
            && Configuration::updateValue(self::SEND_MAIL, 1)
            && Configuration::updateValue(self::SEND_DELIVERED_MAIL, 1);
    }

    public static function uninstall(): bool
    {
        $result = true;

        foreach (self::KEYS as $key) {
            $result = Configuration::deleteByName($key) && $result;
        }

        return $result;
    }

    public static function getShippedState(): int
    {
        return (int) Configuration::get(self::ORDER_STATE_SHIPPED);
    }

    public static function getDeliveredState(): int
    {
        return (int) Configuration::get(self::ORDER_STATE_DELIVERED);
    }

    public static function isMailEnabled(): bool
    {
        return (bool) Configuration::get(self::SEND_MAIL);
    }

    public static function isDeliveredMailEnabled(): bool
    {
        return (bool) Configuration::get(self::SEND_DELIVERED_MAIL);
    }
}
