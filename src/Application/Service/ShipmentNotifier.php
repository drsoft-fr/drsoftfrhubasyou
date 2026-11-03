<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Service;

use Context;
use Customer;
use DateTimeImmutable;
use Db;
use DbQuery;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use DrSoftFr\Module\HubAsYou\Infrastructure\Configuration\Settings;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use Language;
use Link;
use Mail;
use Order;
use OrderHistory;
use PrestaShopLogger;
use Throwable;
use Validate;

/**
 * What happens after a parcel is saved (Admin API or back office):
 *   - "parcel on its way" e-mail, once per parcel;
 *   - order state: shipped, then delivered when every unit is delivered;
 *   - "order delivered" e-mail, once per order, when every unit is delivered.
 * Each e-mail can be disabled in the module settings.
 *
 * A failure here never refuses the parcel: it is logged (Advanced parameters › Logs).
 */
class ShipmentNotifier
{
    public const MAIL_TEMPLATE = 'drsoftfrhubasyou_shipment';
    public const MAIL_TEMPLATE_DELIVERED = 'drsoftfrhubasyou_delivered';

    private const MODULE_NAME = 'drsoftfrhubasyou';
    private const TABLE_ORDER_MAIL = 'drsoft_fr_hubasyou_order_mail';

    public function __construct(
        private readonly ShipmentRepository $repository,
    ) {
    }

    public function onShipmentSaved(int $shipmentId): void
    {
        try {
            $shipment = $this->repository->find($shipmentId);

            if (!$shipment) {
                return;
            }

            $order = new Order((int) $shipment['id_order']);

            if (!Validate::isLoadedObject($order)) {
                return;
            }

            $allDelivered = $this->isFullyDelivered($order);

            $this->sendShipmentMailOnce($shipment, $order);
            $this->updateOrderState($order, $allDelivered);

            if ($allDelivered) {
                $this->sendDeliveredMailOnce($order);
            }
        } catch (Throwable $t) {
            PrestaShopLogger::addLog(
                sprintf('%s - parcel #%d - %s', self::MODULE_NAME, $shipmentId, $t->getMessage()),
                3,
                (int) $t->getCode(),
                'Order',
                (int) ($shipment['id_order'] ?? 0),
                true
            );
        }
    }

    /**
     * True when every ordered unit is in a delivered parcel.
     */
    private function isFullyDelivered(Order $order): bool
    {
        $ordered = [];

        foreach ($order->getProductsDetail() as $detail) {
            $ordered[(int) $detail['id_order_detail']] = (int) $detail['product_quantity'];
        }

        if (!$ordered) {
            return false;
        }

        $delivered = $this->repository->getShippedQuantities((int) $order->id, true);

        foreach ($ordered as $orderDetailId => $quantity) {
            if (($delivered[$orderDetailId] ?? 0) < $quantity) {
                return false;
            }
        }

        return true;
    }

    private function sendShipmentMailOnce(array $shipment, Order $order): void
    {
        $status = ShipmentStatus::tryFrom((string) $shipment['status']);

        if (!Settings::isMailEnabled() || !empty($shipment['mail_sent_at']) || !$status?->isOnItsWay()) {
            return;
        }

        $customer = $this->getCustomer($order);

        if (!$customer) {
            return;
        }

        $sent = $this->sendMail(
            $order,
            $customer,
            self::MAIL_TEMPLATE,
            'Your parcel is on its way',
            $this->getShipmentVars($shipment) + [
                '{products_html}' => $this->getProductsHtml($shipment['lines']),
                '{products_txt}' => $this->getProductsTxt($shipment['lines']),
            ]
        );

        if ($sent) {
            $this->repository->update((int) $shipment['id_shipment'], ['mail_sent_at' => new DateTimeImmutable()]);
        }
    }

    /**
     * "Order delivered" e-mail with the summary of every parcel, once per order.
     */
    private function sendDeliveredMailOnce(Order $order): void
    {
        $orderId = (int) $order->id;

        if (!Settings::isDeliveredMailEnabled() || $this->hasDeliveredMailBeenSent($orderId)) {
            return;
        }

        $customer = $this->getCustomer($order);

        if (!$customer) {
            return;
        }

        $shipmentsHtml = '';
        $shipmentsTxt = '';

        foreach ($this->repository->findByOrder($orderId) as $shipment) {
            $vars = $this->getShipmentVars($shipment);
            $shipmentsHtml .= '<tr><td style="padding:12px 0;border-top:1px solid #e5e5e5;">'
                . htmlspecialchars($vars['{carrier_name}'], ENT_QUOTES, 'UTF-8') . ' · ' . $vars['{tracking_link_html}']
                . $this->getProductsHtml($shipment['lines'], 'margin:8px 0 0;padding-left:18px;')
                . '</td></tr>';
            $shipmentsTxt .= $vars['{carrier_name}'] . ' · ' . $vars['{tracking_number}'] . "\n"
                . $this->getProductsTxt($shipment['lines']) . "\n\n";
        }

        $sent = $this->sendMail(
            $order,
            $customer,
            self::MAIL_TEMPLATE_DELIVERED,
            'Your order has been delivered',
            [
                '{shipments_html}' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="color:inherit;font-size:inherit;">' . $shipmentsHtml . '</table>',
                '{shipments_txt}' => rtrim($shipmentsTxt),
            ]
        );

        if ($sent) {
            Db::getInstance()->insert(self::TABLE_ORDER_MAIL, [
                'id_order' => $orderId,
                'delivered_mail_sent_at' => date('Y-m-d H:i:s'),
            ], false, true, Db::INSERT_IGNORE);
        }
    }

    private function hasDeliveredMailBeenSent(int $orderId): bool
    {
        return (bool) Db::getInstance()->getValue(
            (new DbQuery())->select('1')->from(self::TABLE_ORDER_MAIL)->where('id_order = ' . $orderId)
        );
    }

    private function getCustomer(Order $order): ?Customer
    {
        $customer = new Customer((int) $order->id_customer);

        return Validate::isLoadedObject($customer) && Validate::isEmail($customer->email) ? $customer : null;
    }

    /**
     * Sends a module e-mail in the language of the order (template overridable
     * in themes/<theme>/modules/drsoftfrhubasyou/mails/<iso>/).
     */
    private function sendMail(Order $order, Customer $customer, string $template, string $subject, array $vars): bool
    {
        $context = Context::getContext();

        if (!$context->link) {
            $context->link = new Link();
        }

        $language = new Language((int) $order->id_lang);

        return (bool) Mail::send(
            (int) $order->id_lang,
            $template,
            $context->getTranslator()->trans($subject, [], 'Modules.Drsoftfrhubasyou.Shop', $language->locale),
            $vars + [
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{order_name}' => $order->getUniqReference(),
            ],
            $customer->email,
            trim($customer->firstname . ' ' . $customer->lastname),
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . self::MODULE_NAME . '/mails/',
            false,
            (int) $order->id_shop
        );
    }

    private function getShipmentVars(array $shipment): array
    {
        $trackingUrl = (string) ($shipment['tracking_url'] ?? '');
        $trackingNumber = htmlspecialchars((string) $shipment['tracking_number'], ENT_QUOTES, 'UTF-8');

        return [
            '{carrier_name}' => (string) $shipment['carrier_name'],
            '{tracking_number}' => (string) $shipment['tracking_number'],
            '{tracking_url}' => $trackingUrl,
            '{tracking_link_html}' => $trackingUrl !== ''
                ? '<a href="' . htmlspecialchars($trackingUrl, ENT_QUOTES, 'UTF-8') . '" style="color:inherit;text-decoration:underline;">' . $trackingNumber . '</a>'
                : $trackingNumber,
        ];
    }

    private function getProductsHtml(array $lines, string $style = 'margin:0;padding-left:18px;'): string
    {
        $items = '';

        foreach ($lines as $line) {
            $items .= '<li>' . (int) $line['quantity'] . ' × ' . htmlspecialchars((string) $line['product_name'], ENT_QUOTES, 'UTF-8') . '</li>';
        }

        return '<ul style="' . $style . '">' . $items . '</ul>';
    }

    private function getProductsTxt(array $lines): string
    {
        $text = '';

        foreach ($lines as $line) {
            $text .= '- ' . (int) $line['quantity'] . ' x ' . $line['product_name'] . "\n";
        }

        return rtrim($text);
    }

    /**
     * Shipped state as soon as a parcel is on its way; delivered state once every
     * ordered unit is in a delivered parcel. Never goes back to an earlier state.
     */
    private function updateOrderState(Order $order, bool $allDelivered): void
    {
        $orderId = (int) $order->id;
        $deliveredState = Settings::getDeliveredState();

        if ($allDelivered && $deliveredState) {
            if (!$this->hasHadState($orderId, $deliveredState)) {
                $this->changeState($order, $deliveredState);
            }

            return;
        }

        $shippedState = Settings::getShippedState();
        $anyOnItsWay = false;

        foreach ($this->repository->findByOrder($orderId) as $shipment) {
            if (ShipmentStatus::tryFrom((string) $shipment['status'])?->isOnItsWay()) {
                $anyOnItsWay = true;

                break;
            }
        }

        // Order::hasBeenDelivered() reads the "delivery" flag, also set on the shipped state:
        // the history of the configured states is checked instead
        if ($anyOnItsWay && $shippedState
            && !$this->hasHadState($orderId, $shippedState)
            && !($deliveredState && $this->hasHadState($orderId, $deliveredState))
        ) {
            $this->changeState($order, $shippedState);
        }
    }

    private function hasHadState(int $orderId, int $stateId): bool
    {
        return (bool) Db::getInstance()->getValue(
            (new DbQuery())
                ->select('1')
                ->from('order_history')
                ->where('id_order = ' . $orderId . ' AND id_order_state = ' . $stateId)
        );
    }

    private function changeState(Order $order, int $stateId): void
    {
        if ((int) $order->getCurrentState() === $stateId) {
            return;
        }

        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($stateId, $order, true);
        $history->addWithemail(true);
    }
}
