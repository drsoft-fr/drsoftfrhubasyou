<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Mail;

use Context;
use Customer;
use Language;
use Link;
use Mail;
use Order;
use Validate;

/**
 * E-mails of the module to the customer, in the language of the order.
 * Templates overridable in themes/<theme>/modules/drsoftfrhubasyou/mails/<iso>/.
 */
class ShipmentMailer
{
    public const TEMPLATE_SHIPMENT = 'drsoftfrhubasyou_shipment';
    public const TEMPLATE_DELIVERED = 'drsoftfrhubasyou_delivered';

    private const MODULE_NAME = 'drsoftfrhubasyou';

    /**
     * "Your parcel is on its way": one parcel, its content and its tracking.
     *
     * @param array $shipment Row of ShipmentRepository (with its lines)
     *
     * @return bool False when not sent (no valid customer, mail error)
     */
    public function sendShipmentMail(Order $order, array $shipment): bool
    {
        return $this->send(
            $order,
            self::TEMPLATE_SHIPMENT,
            'Your parcel is on its way',
            $this->getShipmentVars($shipment) + [
                '{products_html}' => $this->getProductsHtml($shipment['lines']),
                '{products_txt}' => $this->getProductsTxt($shipment['lines']),
            ]
        );
    }

    /**
     * "Your order has been delivered": summary of every parcel of the order.
     *
     * @param array[] $shipments Rows of ShipmentRepository (with their lines)
     *
     * @return bool False when not sent (no valid customer, mail error)
     */
    public function sendDeliveredMail(Order $order, array $shipments): bool
    {
        $html = '';
        $txt = '';

        foreach ($shipments as $shipment) {
            $vars = $this->getShipmentVars($shipment);
            $html .= '<tr><td style="padding:12px 0;border-top:1px solid #e5e5e5;">'
                . htmlspecialchars($vars['{carrier_name}'], ENT_QUOTES, 'UTF-8') . ' · ' . $vars['{tracking_link_html}']
                . $this->getProductsHtml($shipment['lines'], 'margin:8px 0 0;padding-left:18px;')
                . '</td></tr>';
            $txt .= $vars['{carrier_name}'] . ' · ' . $vars['{tracking_number}'] . "\n"
                . $this->getProductsTxt($shipment['lines']) . "\n\n";
        }

        return $this->send(
            $order,
            self::TEMPLATE_DELIVERED,
            'Your order has been delivered',
            [
                '{shipments_html}' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="color:inherit;font-size:inherit;">' . $html . '</table>',
                '{shipments_txt}' => rtrim($txt),
            ]
        );
    }

    private function send(Order $order, string $template, string $subject, array $vars): bool
    {
        $customer = new Customer((int) $order->id_customer);

        if (!Validate::isLoadedObject($customer) || !Validate::isEmail($customer->email)) {
            return false;
        }

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
}
