<?php

declare(strict_types=1);

use DrSoftFr\Module\HubAsYou\Application\Command\DeleteShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Command\EditShipmentCommand;
use DrSoftFr\Module\HubAsYou\Application\Command\SendDeliveredMailCommand;
use DrSoftFr\Module\HubAsYou\Application\Command\SendShipmentMailCommand;
use DrSoftFr\Module\HubAsYou\Domain\Exception\OrderBusyException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConflictException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentRules;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;

/**
 * Back office › order page › HubAsYou parcels: edit the tracking of a parcel
 * (?hubasyou_view=edit) or delete it after a confirmation page (?hubasyou_view=delete);
 * send the "parcel on its way" or "order delivered" e-mail again (buttons of the order page).
 *
 * Same CQRS commands as the Admin API, so the same rules apply.
 */
class AdminDrSoftFrHubAsYouShipmentController extends ModuleAdminController
{
    public const TAB_CLASS_NAME = drsoftfrhubasyou::TAB_SHIPMENT;

    private const DOMAIN = 'Modules.Drsoftfrhubasyou.Admin';

    /**
     * @var drsoftfrhubasyou
     */
    public $module;

    private ?array $shipment = null;

    /** After a conflict: the form shows the saved values, not the ones sent */
    private bool $showCurrentValues = false;

    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();
    }

    public function init(): void
    {
        parent::init();

        try {
            $this->shipment = (new ShipmentRepository())->find((int) Tools::getValue('id_shipment'));
        } catch (ShipmentException $e) {
            $this->addError($this->getErrorMessage($e));

            return;
        }

        if (!$this->shipment) {
            $this->addError($this->trans('Parcel not found.', [], self::DOMAIN));
        }
    }

    public function postProcess()
    {
        if (!$this->shipment) {
            return;
        }

        $shipmentId = (int) $this->shipment['id_shipment'];

        // Button of the order page (sent with a parcel of the order): the answer is shown there
        if (Tools::isSubmit('submitHubAsYouDeliveredMail') && $this->checkPermission('edit')) {
            try {
                $this->getCommandBus()->handle(new SendDeliveredMailCommand((int) $this->shipment['id_order']));
            } catch (ShipmentException $e) {
                $this->redirectToOrder($this->getErrorMessage($e), 'error');
            }

            $this->redirectToOrder($this->trans('The order delivered e-mail has been sent.', [], self::DOMAIN));
        }

        if (Tools::isSubmit('submitHubAsYouShipmentMail') && $this->checkPermission('edit')) {
            try {
                $this->getCommandBus()->handle(new SendShipmentMailCommand($shipmentId));
            } catch (ShipmentException $e) {
                $this->redirectToOrder($this->getErrorMessage($e), 'error');
            }

            $this->redirectToOrder($this->trans('The e-mail of parcel %reference% has been sent.', ['%reference%' => $this->escapeHtml($this->shipment['external_reference'])], self::DOMAIN));
        }

        try {
            if (Tools::isSubmit('submitHubAsYouShipmentEdit') && $this->checkPermission('edit')) {
                $this->getCommandBus()->handle(new EditShipmentCommand(
                    $shipmentId,
                    (string) Tools::getValue('carrier_name'),
                    (string) Tools::getValue('tracking_number'),
                    (string) Tools::getValue('tracking_url'),
                    (string) Tools::getValue('status'),
                    null,
                    null,
                    (string) Tools::getValue('hubasyou_version'),
                ));

                $this->redirectToOrder($this->trans('Parcel %reference% updated.', ['%reference%' => $this->escapeHtml($this->shipment['external_reference'])], self::DOMAIN));
            }

            if (Tools::isSubmit('submitHubAsYouShipmentDelete') && $this->checkPermission('delete')) {
                $this->getCommandBus()->handle(new DeleteShipmentCommand($shipmentId));

                $this->redirectToOrder($this->trans('Parcel %reference% deleted.', ['%reference%' => $this->escapeHtml($this->shipment['external_reference'])], self::DOMAIN));
            }
        } catch (ShipmentConflictException) {
            // Changed meanwhile (HubAsYou, another employee): show its current values and version
            $this->addError($this->trans('This parcel was changed (by HubAsYou or another employee) while you were editing it. Its current values are shown below: check them and save again if needed.', [], self::DOMAIN));
            $this->shipment = (new ShipmentRepository())->find($shipmentId);
            $this->showCurrentValues = true;
        } catch (ShipmentException $e) {
            $this->addError($this->getErrorMessage($e));
        }

        return parent::postProcess();
    }

    /**
     * Edit form (permission "edit" of this tab) or delete confirmation (permission
     * "delete"); without the permission, only the error is shown.
     */
    public function initContent(): void
    {
        $view = Tools::getValue('hubasyou_view') === 'delete' ? 'delete' : 'edit';

        if ($this->shipment && $this->checkPermission($view)) {
            $this->content = $view === 'delete'
                ? $this->renderDeleteConfirmation()
                : $this->renderEditForm();
        }

        parent::initContent();
    }

    /**
     * Permission of the employee's profile on this tab (Advanced Parameters › Team ›
     * Permissions), with PrestaShop's message when it is missing.
     *
     * @param 'edit'|'delete' $action
     */
    private function checkPermission(string $action): bool
    {
        if ($this->access($action)) {
            return true;
        }

        $this->addError($action === 'delete'
            ? $this->trans('You do not have permission to delete this.', [], 'Admin.Notifications.Error')
            : $this->trans('You do not have permission to edit this.', [], 'Admin.Notifications.Error'));

        return false;
    }

    private function addError(string $message): void
    {
        if (!in_array($message, $this->errors, true)) {
            $this->errors[] = $message;
        }
    }

    public function initToolbarTitle(): void
    {
        parent::initToolbarTitle();

        if ($this->shipment) {
            $this->toolbar_title[] = $this->trans('Parcel %reference%', ['%reference%' => $this->escapeHtml($this->shipment['external_reference'])], self::DOMAIN);
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
                    // HelperForm prints the legend and the description as they are: values escaped here
                    'title' => $this->trans('Edit the tracking of parcel %reference%', ['%reference%' => $this->escapeHtml($this->shipment['external_reference'])], self::DOMAIN),
                    'icon' => 'icon-truck',
                ],
                'description' => $this->trans('Products: %products%. The content of a parcel cannot be changed: to fix it, delete the parcel and ask HubAsYou to send it again.', ['%products%' => $this->escapeHtml($this->getProductsSummary())], self::DOMAIN),
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Carrier', [], self::DOMAIN),
                        'name' => 'carrier_name',
                        'required' => true,
                        'maxlength' => ShipmentRules::TEXT_MAX_LENGTH,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Tracking number', [], self::DOMAIN),
                        'name' => 'tracking_number',
                        'required' => true,
                        'maxlength' => ShipmentRules::TEXT_MAX_LENGTH,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Tracking URL', [], self::DOMAIN),
                        'name' => 'tracking_url',
                        'maxlength' => ShipmentRules::URL_MAX_LENGTH,
                        'desc' => $this->trans('Page of the carrier shown to the customer (http or https). Leave empty for none.', [], self::DOMAIN),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Status', [], self::DOMAIN),
                        'name' => 'status',
                        'options' => ['query' => $statusOptions, 'id' => 'id', 'name' => 'name'],
                        'desc' => $this->trans('Saving runs the same effects as HubAsYou: e-mail once per parcel, order state.', [], self::DOMAIN),
                    ],
                    [
                        'type' => 'hidden',
                        'name' => 'hubasyou_version',
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
                'carrier_name' => $this->getFormValue('carrier_name', $this->shipment['carrier_name']),
                'tracking_number' => $this->getFormValue('tracking_number', $this->shipment['tracking_number']),
                'tracking_url' => $this->getFormValue('tracking_url', (string) $this->shipment['tracking_url']),
                'status' => $this->getFormValue('status', $this->shipment['status']),
                // Version the employee is working from: kept after a refused save, renewed after a conflict
                'hubasyou_version' => $this->getFormValue('hubasyou_version', ShipmentRepository::version($this->shipment)),
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

    /**
     * Value sent by the form (refused save: the employee's input is kept), else the saved one.
     */
    private function getFormValue(string $field, string $saved): string
    {
        return $this->showCurrentValues ? $saved : (string) Tools::getValue($field, $saved);
    }

    /**
     * The page title, the form legend and description and the flash messages are printed
     * as they are (or only stripped of tags): values received from HubAsYou go in escaped.
     */
    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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

    /**
     * @param 'success'|'error' $type
     */
    private function redirectToOrder(string $message, string $type = 'success'): void
    {
        try {
            SymfonyContainer::getInstance()->get('request_stack')->getSession()->getFlashBag()->add($type, $message);
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
        if ($e instanceof OrderBusyException) {
            return $this->trans('This order is being updated by HubAsYou: try again in a moment.', [], self::DOMAIN);
        }

        if (!$e instanceof ShipmentConstraintException) {
            return $e->getMessage();
        }

        return match ($e->getCode()) {
            ShipmentConstraintException::EMPTY_FIELD => $this->trans('The carrier and the tracking number are required.', [], self::DOMAIN),
            ShipmentConstraintException::TOO_LONG => $this->trans('The carrier and the tracking number cannot exceed %max% characters.', ['%max%' => ShipmentRules::TEXT_MAX_LENGTH], self::DOMAIN),
            ShipmentConstraintException::INVALID_URL => $this->trans('The tracking URL must start with http:// or https:// and cannot exceed %max% characters.', ['%max%' => ShipmentRules::URL_MAX_LENGTH], self::DOMAIN),
            ShipmentConstraintException::INVALID_STATUS => $this->trans('Unknown status.', [], self::DOMAIN),
            ShipmentConstraintException::DELIVERED_MAIL_NOT_ALLOWED => $this->trans('This e-mail cannot be sent: the order is not entirely delivered, or this e-mail is disabled in the module settings.', [], self::DOMAIN),
            ShipmentConstraintException::DELIVERED_MAIL_FAILED => $this->trans('The order delivered e-mail could not be sent: check the e-mail settings and the logs, then try again.', [], self::DOMAIN),
            ShipmentConstraintException::SHIPMENT_MAIL_NOT_ALLOWED => $this->trans('This e-mail cannot be sent: the parcel is no longer on its way, or this e-mail is disabled in the module settings.', [], self::DOMAIN),
            ShipmentConstraintException::SHIPMENT_MAIL_FAILED => $this->trans('The parcel e-mail could not be sent: check the e-mail settings and the logs, then try again.', [], self::DOMAIN),
            default => $e->getMessage(),
        };
    }

    private function getCommandBus(): CommandBusInterface
    {
        return SymfonyContainer::getInstance()->get('prestashop.core.command_bus');
    }
}
