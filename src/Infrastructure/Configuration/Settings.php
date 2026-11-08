<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Configuration;

use Configuration;
use DrSoftFr\Module\HubAsYou\Domain\ShopOrder;

/**
 * Module settings (Modules › drSoft.fr HubAsYou › Configure), saved for the shop chosen
 * in the back office (Configuration::updateValue follows the shop context).
 */
class Settings
{
    /** Technical name of the module (logs, path of the e-mail templates) */
    public const MODULE_NAME = 'drsoftfrhubasyou';

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

    public function install(): bool
    {
        return Configuration::updateValue(self::ORDER_STATE_SHIPPED, (int) Configuration::get('PS_OS_SHIPPING'))
            && Configuration::updateValue(self::ORDER_STATE_DELIVERED, (int) Configuration::get('PS_OS_DELIVERED'))
            && Configuration::updateValue(self::SEND_MAIL, 1)
            && Configuration::updateValue(self::SEND_DELIVERED_MAIL, 1)
            && $this->setChangeableStates($this->getDefaultChangeableStates());
    }

    public function uninstall(): bool
    {
        $result = true;

        foreach (self::KEYS as $key) {
            $result = Configuration::deleteByName($key) && $result;
        }

        return $result;
    }

    public function getShippedState(?ShopOrder $order = null): int
    {
        return (int) $this->get(self::ORDER_STATE_SHIPPED, $order);
    }

    public function getDeliveredState(?ShopOrder $order = null): int
    {
        return (int) $this->get(self::ORDER_STATE_DELIVERED, $order);
    }

    /**
     * The states ticked in the settings, plus, always, the state set when the first parcel
     * leaves: the module must be able to move the order on from it to the delivered state.
     * Without it, unticking that state would leave every order "Shipped" for ever.
     *
     * @return int[]
     */
    public function getChangeableStates(?ShopOrder $order = null): array
    {
        $states = array_map('intval', explode(',', (string) $this->get(self::CHANGEABLE_STATES, $order)));
        $states[] = $this->getShippedState($order);

        return array_values(array_unique(array_filter($states)));
    }

    /**
     * @param int[] $stateIds
     */
    public function setChangeableStates(array $stateIds): bool
    {
        $stateIds = array_values(array_unique(array_filter(array_map('intval', $stateIds))));
        sort($stateIds);

        return Configuration::updateValue(self::CHANGEABLE_STATES, implode(',', $stateIds));
    }

    /**
     * @return int[]
     */
    public function getDefaultChangeableStates(): array
    {
        return array_map(static fn (string $key): int => (int) Configuration::get($key), self::DEFAULT_CHANGEABLE_STATE_KEYS);
    }

    public function isMailEnabled(?ShopOrder $order = null): bool
    {
        return (bool) $this->get(self::SEND_MAIL, $order);
    }

    public function isDeliveredMailEnabled(?ShopOrder $order = null): bool
    {
        return (bool) $this->get(self::SEND_DELIVERED_MAIL, $order);
    }

    /**
     * Value for the shop of the order, whatever the shop of the request (an API call can
     * be made in the context of another shop, or of all of them); PrestaShop falls back on
     * the shop group, then on the global value. Without an order (configuration page):
     * value for the shop chosen in the back office. Not always a string: right after a
     * save, PrestaShop returns the value as it was given.
     */
    private function get(string $key, ?ShopOrder $order): mixed
    {
        return Configuration::get($key, null, $order?->shopGroupId, $order?->shopId);
    }
}
