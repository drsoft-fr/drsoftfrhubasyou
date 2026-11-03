<?php

declare(strict_types=1);

use DrSoftFr\Module\HubAsYou\Application\Command\DeleteShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Command\EditShipmentCommand;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;

/**
 * Back office › order page › HubAsYou parcels: edit the tracking of a parcel
 * (?hubasyou_view=edit) or delete it after a confirmation page (?hubasyou_view=delete).
 *
 * Same CQRS commands as the Admin API, so the same rules apply.
 */
class AdminDrSoftFrHubAsYouShipmentController extends ModuleAdminController
{
    public const TAB_CLASS_NAME = 'AdminDrSoftFrHubAsYouShipment';

    private const DOMAIN = 'Modules.Drsoftfrhubasyou.Admin';

    /**
     * @var drsoftfrhubasyou
     */
    public $module;

    private ?array $shipment = null;

    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();
    }

    public function init(): void
    {
        parent::init();

        $this->shipment = (new ShipmentRepository())->find((int) Tools::getValue('id_shipment'));

        if (!$this->shipment) {
            $this->errors[] = $this->trans('Parcel not found.', [], self::DOMAIN);
        }
    }

    public function postProcess()
    {
        if (!$this->shipment) {
            return;
        }

        $shipmentId = (int) $this->shipment['id_shipment'];

        try {
            if (Tools::isSubmit('submitHubAsYouShipmentEdit')) {
                $this->getCommandBus()->handle(new EditShipmentCommand(
                    $shipmentId,
                    (string) Tools::getValue('carrier_name'),
                    (string) Tools::getValue('tracking_number'),
                    (string) Tools::getValue('tracking_url'),
                    (string) Tools::getValue('status'),
                ));

                $this->redirectToOrder($this->trans('Parcel %reference% updated.', ['%reference%' => $this->shipment['external_reference']], self::DOMAIN));
            }

            if (Tools::isSubmit('submitHubAsYouShipmentDelete')) {
                $this->getCommandBus()->handle(new DeleteShipmentCommand($shipmentId));

                $this->redirectToOrder($this->trans('Parcel %reference% deleted.', ['%reference%' => $this->shipment['external_reference']], self::DOMAIN));
            }
        } catch (ShipmentException $e) {
            $this->errors[] = $this->getErrorMessage($e);
        }

        return parent::postProcess();
    }

    public function initContent(): void
    {
        if ($this->shipment) {
            $this->content = Tools::getValue('hubasyou_view') === 'delete'
                ? $this->renderDeleteConfirmation()
                : $this->renderEditForm();
        }

        parent::initContent();
    }

    public function initToolbarTitle(): void
    {
        parent::initToolbarTitle();

        if ($this->shipment) {
            $this->toolbar_title[] = $this->trans('Parcel %reference%', ['%reference%' => $this->shipment['external_reference']], self::DOMAIN);
        }
    }

    private function renderEditForm(): string
    {
        $statusOptions = [];

        foreach ($this->module->getStatusLabels() as $value => $label) {
            $statusOptions[] = ['id' => $value, 'name' => $label];
        }

        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Edit the tracking of parcel %reference%', ['%reference%' => $this->shipment['external_reference']], self::DOMAIN),
                    'icon' => 'icon-truck',
                ],
                'description' => $this->trans('Products: %products%. The content of a parcel cannot be changed: to fix it, delete the parcel and ask HubAsYou to send it again.', ['%products%' => $this->getProductsSummary()], self::DOMAIN),
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Carrier', [], self::DOMAIN),
                        'name' => 'carrier_name',
                        'required' => true,
                        'maxlength' => 64,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Tracking number', [], self::DOMAIN),
                        'name' => 'tracking_number',
                        'required' => true,
                        'maxlength' => 64,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Tracking URL', [], self::DOMAIN),
                        'name' => 'tracking_url',
                        'maxlength' => 255,
                        'desc' => $this->trans('Page of the carrier shown to the customer (http or https). Leave empty for none.', [], self::DOMAIN),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Status', [], self::DOMAIN),
                        'name' => 'status',
                        'options' => ['query' => $statusOptions, 'id' => 'id', 'name' => 'name'],
                        'desc' => $this->trans('Saving runs the same effects as HubAsYou: e-mail once per parcel, order state.', [], self::DOMAIN),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                    'name' => 'submitHubAsYouShipmentEdit',
                ],
                'buttons' => [
                    [
                        'href' => $this->getOrderUrl(),
                        'title' => $this->trans('Back to the order', [], self::DOMAIN),
                        'icon' => 'process-icon-back',
                    ],
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this->module;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->identifier = 'id_shipment';
        $helper->submit_action = '';
        $helper->currentIndex = $this->context->link->getAdminLink(self::TAB_CLASS_NAME, false)
            . '&id_shipment=' . (int) $this->shipment['id_shipment'];
        $helper->token = Tools::getAdminTokenLite(self::TAB_CLASS_NAME);
        $helper->tpl_vars = [
            'fields_value' => [
                'carrier_name' => Tools::getValue('carrier_name', $this->shipment['carrier_name']),
                'tracking_number' => Tools::getValue('tracking_number', $this->shipment['tracking_number']),
                'tracking_url' => Tools::getValue('tracking_url', (string) $this->shipment['tracking_url']),
                'status' => Tools::getValue('status', $this->shipment['status']),
            ],
            'languages' => $this->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$form]);
    }

    private function renderDeleteConfirmation(): string
    {
        $this->context->smarty->assign([
            'hubasyou_shipment' => $this->shipment,
            'hubasyou_products' => $this->getProductsSummary(),
            'hubasyou_action_url' => $this->context->link->getAdminLink(self::TAB_CLASS_NAME, true, [], [
                'id_shipment' => (int) $this->shipment['id_shipment'],
                'hubasyou_view' => 'delete',
            ]),
            'hubasyou_order_url' => $this->getOrderUrl(),
        ]);

        return $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/delete-confirm.tpl');
    }

    private function getProductsSummary(): string
    {
        return implode(', ', array_map(
            static fn (array $line): string => $line['quantity'] . ' × ' . $line['product_name'],
            $this->shipment['lines']
        ));
    }

    private function getOrderUrl(): string
    {
        return $this->context->link->getAdminLink('AdminOrders', true, [
            'route' => 'admin_orders_view',
            'orderId' => (int) $this->shipment['id_order'],
        ]);
    }

    private function redirectToOrder(string $message): void
    {
        try {
            SymfonyContainer::getInstance()->get('request_stack')->getSession()->getFlashBag()->add('success', $message);
        } catch (Throwable) {
            // No session: the order page shows the new state of the parcels anyway
        }

        Tools::redirectAdmin($this->getOrderUrl());
    }

    /**
     * Translated message for the errors of the fields edited here, API message otherwise.
     */
    private function getErrorMessage(ShipmentException $e): string
    {
        if (!$e instanceof ShipmentConstraintException) {
            return $e->getMessage();
        }

        return match ($e->getCode()) {
            ShipmentConstraintException::EMPTY_FIELD => $this->trans('The carrier and the tracking number are required.', [], self::DOMAIN),
            ShipmentConstraintException::TOO_LONG => $this->trans('The carrier and the tracking number cannot exceed 64 characters.', [], self::DOMAIN),
            ShipmentConstraintException::INVALID_URL => $this->trans('The tracking URL must start with http:// or https:// and cannot exceed 255 characters.', [], self::DOMAIN),
            ShipmentConstraintException::INVALID_STATUS => $this->trans('Unknown status.', [], self::DOMAIN),
            default => $e->getMessage(),
        };
    }

    private function getCommandBus(): CommandBusInterface
    {
        return SymfonyContainer::getInstance()->get('prestashop.core.command_bus');
    }
}
