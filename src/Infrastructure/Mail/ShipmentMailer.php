<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Mail;

use Context;
use Customer;
use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentLine;
use DrSoftFr\Module\HubAsYou\Infrastructure\Configuration\Settings;
use Language;
use Link;
use Mail;
use Order;
use RuntimeException;
use Validate;

/**
 * E-mails of the module to the customer, in the language of the order.
 * Templates overridable in themes/<theme>/modules/drsoftfrhubasyou/mails/<iso>/.
 *
 * PrestaShop puts the same variables, as they are, in the HTML and the text template:
 * the values received from HubAsYou are therefore given twice, escaped for the HTML
 * template (…_html) and raw for the text one. {firstname}, {lastname} and {order_name}
 * come from PrestaShop (names validated by Validate::isCustomerName, as in its own e-mails).
 */
class ShipmentMailer
{
    public const TEMPLATE_SHIPMENT = 'drsoftfrhubasyou_shipment';
    public const TEMPLATE_DELIVERED = 'drsoftfrhubasyou_delivered';

    /**
     * "Your parcel is on its way": one parcel, its content and its tracking.
     *
     * @throws RuntimeException Not sent, with the reason
     */
    public function sendShipmentMail(int $orderId, Shipment $shipment): void
    {
        $this->send($orderId, self::TEMPLATE_SHIPMENT, 'Your parcel is on its way', $this->getShipmentMailVars($shipment));
    }

    /**
     * "Your order has been delivered": summary of the delivered parcels of the order.
     *
     * @param Shipment[] $shipments
     *
     * @throws RuntimeException Not sent, with the reason
     */
    public function sendDeliveredMail(int $orderId, array $shipments): void
    {
        $this->send($orderId, self::TEMPLATE_DELIVERED, 'Your order has been delivered', $this->getDeliveredMailVars($shipments));
    }

    /**
     * Variables of the "parcel on its way" template (without those of the customer and the order).
     *
     * @return array<string, string>
     */
    public function getShipmentMailVars(Shipment $shipment): array
    {
        return $this->getTrackingVars($shipment) + [
            '{products_html}' => $this->getProductsHtml($shipment->lines),
            '{products_txt}' => $this->getProductsTxt($shipment->lines),
        ];
    }

    /**
     * Variables of the "order delivered" template (without those of the customer and the order).
     *
     * @param Shipment[] $shipments
     *
     * @return array<string, string>
     */
    public function getDeliveredMailVars(array $shipments): array
    {
        $html = '';
        $txt = '';

        foreach ($shipments as $shipment) {
            $vars = $this->getTrackingVars($shipment);
            $html .= '<tr><td style="padding:12px 0;border-top:1px solid #e5e5e5;">'
                . $vars['{carrier_name_html}'] . ' · ' . $vars['{tracking_link_html}']
                . $this->getProductsHtml($shipment->lines, 'margin:8px 0 0;padding-left:18px;')
                . '</td></tr>';
            $txt .= $vars['{carrier_name}'] . ' · ' . $vars['{tracking_number}'] . "\n"
                . $this->getProductsTxt($shipment->lines) . "\n\n";
        }

        return [
            '{shipments_html}' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="color:inherit;font-size:inherit;">' . $html . '</table>',
            '{shipments_txt}' => rtrim($txt),
        ];
    }

    /**
     * @throws RuntimeException Not sent: no customer with a valid e-mail address, or refused by PrestaShop
     */
    private function send(int $orderId, string $template, string $subject, array $vars): void
    {
        $order = new Order($orderId);
        $customer = new Customer((int) $order->id_customer);

        if (!Validate::isLoadedObject($customer) || !Validate::isEmail($customer->email)) {
            throw new RuntimeException(sprintf('The e-mail was not sent: customer #%d of order #%d does not exist or has no valid e-mail address.', (int) $order->id_customer, $orderId));
        }

        $context = Context::getContext();

        if (!$context->link) {
            $context->link = new Link();
        }

        $language = new Language((int) $order->id_lang);

        $sent = Mail::send(
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
            _PS_MODULE_DIR_ . Settings::MODULE_NAME . '/mails/',
            false,
            (int) $order->id_shop
        );

        if (!$sent) {
            // PrestaShop writes its own reason in the logs just before (SMTP error, missing template…)
            throw new RuntimeException(sprintf('The e-mail was not sent: PrestaShop refused or failed to send it (template %s, see the e-mail settings and the log line before this one).', $template));
        }
    }

    /**
     * Carrier and tracking: raw for the text template, escaped (…_html) for the HTML one.
     *
     * @return array<string, string>
     */
    private function getTrackingVars(Shipment $shipment): array
    {
        $trackingUrl = (string) $shipment->trackingUrl;
        $trackingNumberHtml = $this->escape($shipment->trackingNumber);

        return [
            '{carrier_name}' => $shipment->carrierName,
            '{carrier_name_html}' => $this->escape($shipment->carrierName),
            '{tracking_number}' => $shipment->trackingNumber,
            '{tracking_number_html}' => $trackingNumberHtml,
            '{tracking_url}' => $trackingUrl,
            '{tracking_link_html}' => $trackingUrl !== ''
                ? '<a href="' . $this->escape($trackingUrl) . '" style="color:inherit;text-decoration:underline;">' . $trackingNumberHtml . '</a>'
                : $trackingNumberHtml,
        ];
    }

    /**
     * @param ShipmentLine[] $lines
     */
    private function getProductsHtml(array $lines, string $style = 'margin:0;padding-left:18px;'): string
    {
        $items = '';

        foreach ($lines as $line) {
            $items .= '<li>' . $line->quantity . ' × ' . $this->escape($line->productName) . '</li>';
        }

        return '<ul style="' . $style . '">' . $items . '</ul>';
    }

    /**
     * @param ShipmentLine[] $lines
     */
    private function getProductsTxt(array $lines): string
    {
        $text = '';

        foreach ($lines as $line) {
            $text .= '- ' . $line->quantity . ' x ' . $line->productName . "\n";
        }

        return rtrim($text);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
