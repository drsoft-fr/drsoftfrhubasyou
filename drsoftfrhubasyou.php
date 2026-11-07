<?php

declare(strict_types=1);

use DrSoftFr\Module\HubAsYou\Application\Service\OrderStatePolicy;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentEffects;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use DrSoftFr\Module\HubAsYou\Infrastructure\Configuration\Settings;
use DrSoftFr\Module\HubAsYou\Infrastructure\Install\Installer;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\OrderLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Mail\ShipmentMailer;
use DrSoftFr\Module\HubAsYou\Infrastructure\Order\OrderStateWriter;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderMailRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentRepository;
use PrestaShop\PrestaShop\Core\Module\ModuleManager;

if (!defined('_PS_VERSION_')) {
    exit;
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

/**
 * Class drsoftfrhubasyou
 *
 * Receives, parcel by parcel, the tracking of the orders shipped by HubAsYou
 * through the PrestaShop Admin API (contract: docs/api.md), shows it to the
 * customer and in the back office, e-mails the customer and moves the order state.
 */
class drsoftfrhubasyou extends Module
{
    /** Hidden tab of the back office that edits and deletes a parcel (controller and permissions) */
    public const TAB_SHIPMENT = 'AdminDrSoftFrHubAsYouShipment';

    /**
     * @var string $authorEmail Author email
     */
    public $authorEmail;

    /**
     * @var string $moduleGithubRepositoryUrl Module GitHub repository URL
     */
    public $moduleGithubRepositoryUrl;

    /**
     * @var string $moduleGithubIssuesUrl Module GitHub issues URL
     */
    public $moduleGithubIssuesUrl;

    public function __construct()
    {
        $this->author = 'drSoft.fr';
        $this->bootstrap = true;
        $this->dependencies = [];
        $this->name = 'drsoftfrhubasyou';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '9.0.0',
            'max' => _PS_VERSION_,
        ];
        $this->tab = 'shipping_logistics';
        $this->tabs = [
            [
                // Edit / delete a parcel, from the order page (hidden from the menu)
                'class_name' => self::TAB_SHIPMENT,
                // Locale or ISO code => name (first one for the other languages)
                'name' => [
                    'en' => 'HubAsYou parcels',
                    'fr' => 'Colis HubAsYou',
                    'de' => 'HubAsYou-Pakete',
                    'it' => 'Pacchi HubAsYou',
                    'es' => 'Paquetes HubAsYou',
                    'nl' => 'HubAsYou-pakketten',
                    'pt' => 'Encomendas HubAsYou',
                ],
                'parent_class_name' => 'AdminParentOrders',
                'visible' => false,
            ],
        ];
        $this->version = '1.0.0';
        $this->authorEmail = 'contact@drsoft.fr';
        $this->moduleGithubRepositoryUrl = 'https://github.com/drsoft-fr/drsoftfrhubasyou';
        $this->moduleGithubIssuesUrl = 'https://github.com/drsoft-fr/drsoftfrhubasyou/issues';

        parent::__construct();

        $this->displayName = $this->trans('drSoft.fr HubAsYou', [], 'Modules.Drsoftfrhubasyou.Admin');
        $this->description = $this->trans('Receives the parcel tracking of the orders shipped by HubAsYou through the Admin API', [], 'Modules.Drsoftfrhubasyou.Admin');

        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall? The parcels received will be deleted.', [], 'Modules.Drsoftfrhubasyou.Admin');
    }

    public function install(): bool
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (!parent::install()) {
            return false;
        }

        try {
            (new Installer())->install($this);
        } catch (Throwable $t) {
            $this->handleException($t);

            return false;
        }

        return true;
    }

    public function uninstall(): bool
    {
        try {
            (new Installer())->uninstall($this->isBeingReset());
        } catch (Throwable $t) {
            $this->handleException($t);

            return false;
        }

        return parent::uninstall();
    }

    /**
     * "Reset" in the back office is an uninstallation followed by an installation, with no
     * way to keep the data: reset() is only called on an option the back office never sends,
     * and the actionBeforeResetModule hook is not called on a disabled module (or for an
     * employee without the "view" permission). So the caller is looked at directly.
     */
    private function isBeingReset(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if ($frame['function'] === 'reset' && ($frame['class'] ?? '') === ModuleManager::class) {
                return true;
            }
        }

        return false;
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /**
     * Configuration page: order states and e-mail, plus the steps to give HubAsYou its access.
     */
    public function getContent(): string
    {
        $output = '';

        try {
            if (Tools::isSubmit('submitDrSoftFrHubAsYouConfig')) {
                Configuration::updateValue(Settings::ORDER_STATE_SHIPPED, (int) Tools::getValue('order_state_shipped', 0));
                Configuration::updateValue(Settings::ORDER_STATE_DELIVERED, (int) Tools::getValue('order_state_delivered', 0));
                Configuration::updateValue(Settings::SEND_MAIL, (int) (bool) Tools::getValue('send_mail', 0));
                Configuration::updateValue(Settings::SEND_DELIVERED_MAIL, (int) (bool) Tools::getValue('send_delivered_mail', 0));
                Settings::setChangeableStates(array_filter(
                    array_column(OrderState::getOrderStates((int) $this->context->language->id), 'id_order_state'),
                    static fn ($stateId): bool => (bool) Tools::getValue('changeable_state_' . (int) $stateId)
                ));

                $output .= $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Drsoftfrhubasyou.Admin'));
            }

            return $output . $this->renderSetupHelp() . $this->renderForm();
        } catch (Throwable $t) {
            return $output . $this->displayError($t->getMessage());
        }
    }

    private function renderSetupHelp(): string
    {
        $this->context->smarty->assign([
            'admin_api_enabled' => (bool) Configuration::get('PS_ENABLE_ADMIN_API'),
            'api_base_url' => rtrim($this->context->link->getBaseLink(), '/') . '/admin-api',
        ]);

        return $this->display(__FILE__, 'views/templates/admin/setup.tpl');
    }

    /**
     * @throws PrestaShopException
     */
    private function renderForm(): string
    {
        $orderStates = [];

        foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) {
            $orderStates[] = ['id' => (int) $state['id_order_state'], 'name' => $state['name']];
        }

        $states = array_merge(
            [['id' => 0, 'name' => $this->trans('-- Do not change the order state --', [], 'Modules.Drsoftfrhubasyou.Admin')]],
            $orderStates
        );

        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Settings', [], 'Modules.Drsoftfrhubasyou.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->trans('Order state when the first parcel leaves', [], 'Modules.Drsoftfrhubasyou.Admin'),
                        'name' => 'order_state_shipped',
                        'options' => ['query' => $states, 'id' => 'id', 'name' => 'name'],
                        'desc' => $this->trans('Set once, when a parcel is shipped, in transit or delivered. If this state sends its own e-mail (Orders › Statuses), the customer receives it in addition to the parcel e-mail.', [], 'Modules.Drsoftfrhubasyou.Admin'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Order state when everything is delivered', [], 'Modules.Drsoftfrhubasyou.Admin'),
                        'name' => 'order_state_delivered',
                        'options' => ['query' => $states, 'id' => 'id', 'name' => 'name'],
                        'desc' => $this->trans('Set when every unit of the order is in a delivered parcel.', [], 'Modules.Drsoftfrhubasyou.Admin'),
                    ],
                    [
                        'type' => 'checkbox',
                        'label' => $this->trans('Order states the module may change', [], 'Modules.Drsoftfrhubasyou.Admin'),
                        'name' => 'changeable_state',
                        'values' => ['query' => $orderStates, 'id' => 'id', 'name' => 'name'],
                        'desc' => $this->trans('The module changes the state of an order only when its current state is ticked here (for example, never from "Canceled" or "Awaiting payment"). Otherwise the state stays as it is and a warning is shown on the order page. Tick the state set when the first parcel leaves, so that the order can then be set as delivered.', [], 'Modules.Drsoftfrhubasyou.Admin'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('E-mail the customer for each parcel', [], 'Modules.Drsoftfrhubasyou.Admin'),
                        'name' => 'send_mail',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'send_mail_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
                            ['id' => 'send_mail_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
                        ],
                        'desc' => $this->trans('Sent once per parcel, while it is on its way, in the language of the order (template "drsoftfrhubasyou_shipment"). Not sent for a parcel received as already delivered.', [],'Modules.Drsoftfrhubasyou.Admin'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('E-mail the customer when everything is delivered', [], 'Modules.Drsoftfrhubasyou.Admin'),
                        'name' => 'send_delivered_mail',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'send_delivered_mail_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
                            ['id' => 'send_delivered_mail_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
                        ],
                        'desc' => $this->trans('Sent once per order, when every unit is in a delivered parcel, with the summary of the parcels (template "drsoftfrhubasyou_delivered"). Independent of the order state: the "Delivered" state of PrestaShop sends no e-mail by default.', [], 'Modules.Drsoftfrhubasyou.Admin'),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                    'name' => 'submitDrSoftFrHubAsYouConfig',
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->identifier = $this->identifier;
        $helper->submit_action = '';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $fieldsValue = [
            'order_state_shipped' => Settings::getShippedState(),
            'order_state_delivered' => Settings::getDeliveredState(),
            'send_mail' => (int) Settings::isMailEnabled(),
            'send_delivered_mail' => (int) Settings::isDeliveredMailEnabled(),
        ];
        $changeableStates = Settings::getChangeableStates();

        foreach ($orderStates as $state) {
            $fieldsValue['changeable_state_' . $state['id']] = in_array($state['id'], $changeableStates, true);
        }

        $helper->tpl_vars = [
            'fields_value' => $fieldsValue,
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$form]);
    }

    /**
     * Customer account › order detail: the parcels of the order.
     * Template overridable in themes/<theme>/modules/drsoftfrhubasyou/views/templates/hook/order-detail.tpl
     */
    public function hookDisplayOrderDetail(array $params): string
    {
        try {
            $order = $params['order'] ?? null;

            if (!$order instanceof Order) {
                return '';
            }

            $shipments = $this->presentShipments((new ShipmentRepository())->findByOrder((int) $order->id));

            if (!$shipments) {
                return '';
            }

            $this->context->smarty->assign(['hubasyou_shipments' => $shipments]);

            return $this->fetch('module:' . $this->name . '/views/templates/hook/order-detail.tpl');
        } catch (Throwable $t) {
            $this->handleException($t);

            return '';
        }
    }

    /**
     * Back office › order page: the parcels received from HubAsYou.
     */
    public function hookDisplayAdminOrderMain(array $params): string
    {
        try {
            $orderId = (int) ($params['id_order'] ?? 0);
            $shipments = $this->presentShipments((new ShipmentRepository())->findByOrder($orderId));

            // Buttons only for the permissions of the employee's profile on the parcel tab
            // (the controller checks them again)
            $employee = $this->context->employee;
            $actions = array_filter(
                ['edit', 'delete'],
                static fn (string $action): bool => $employee && $employee->can($action, self::TAB_SHIPMENT)
            );
            $order = new Order($orderId);
            $policy = $this->getStatePolicy();

            foreach ($shipments as &$shipment) {
                foreach ($actions as $action) {
                    $shipment[$action . '_url'] = $this->context->link->getAdminLink(self::TAB_SHIPMENT, true, [], [
                        'id_shipment' => $shipment['id'],
                        'hubasyou_view' => $action,
                    ]);
                }

                if (in_array('edit', $actions, true) && $policy->mayShipmentMailBeSent($order, $shipment)) {
                    $shipment['mail_url'] = $this->context->link->getAdminLink(self::TAB_SHIPMENT, true, [], [
                        'id_shipment' => $shipment['id'],
                    ]);
                }
            }
            unset($shipment);

            $deliveredMailDate = (new OrderMailRepository())->findDeliveredMailDate($orderId);
            $deliveredMailAllowed = $shipments && $this->mayDeliveredMailBeSent($orderId);

            $this->context->smarty->assign([
                'hubasyou_shipments' => $shipments,
                'hubasyou_state_warning' => $shipments ? $this->getBlockedStateWarning($orderId) : '',
                'hubasyou_delivered_mail_at' => $deliveredMailDate ? Tools::displayDate($deliveredMailDate) : '',
                'hubasyou_delivered_mail_allowed' => $deliveredMailAllowed,
                'hubasyou_delivered_mail_url' => $deliveredMailAllowed && in_array('edit', $actions, true) ? $this->context->link->getAdminLink(self::TAB_SHIPMENT, true, [], [
                    'id_shipment' => $shipments[0]['id'],
                ]) : '',
            ]);

            return $this->display(__FILE__, 'views/templates/admin/order-shipments.tpl');
        } catch (Throwable $t) {
            $this->handleException($t);

            return '';
        }
    }

    /**
     * A line of the order was saved (refund, return…): it may settle the last units the
     * order was waiting for. actionProductCancel is too early: PrestaShop calls it before
     * saving the refunded quantities. A failure here must never stop the refund.
     */
    public function hookActionObjectOrderDetailUpdateAfter(array $params): void
    {
        try {
            $line = $params['object'] ?? null;

            if (!$line instanceof OrderDetail) {
                return;
            }

            $orderId = (int) $line->id_order;
            $shipments = new ShipmentRepository();

            if (!$shipments->findByOrder($orderId)) {
                return;
            }

            $effects = new ShipmentEffects(
                $shipments,
                $this->getStatePolicy(),
                new OrderMailRepository(),
                new ShipmentMailer(),
                new OrderStateWriter()
            );

            (new OrderLock())->synchronized($orderId, static fn () => $effects->onOrderLinesChanged($orderId));
        } catch (Throwable $t) {
            $this->handleException($t);
        }
    }

    private function mayDeliveredMailBeSent(int $orderId): bool
    {
        $order = new Order($orderId);

        return Validate::isLoadedObject($order)
            && $this->getStatePolicy()->mayDeliveredMailBeSent($order);
    }

    /**
     * Hooks run outside the kernels that load config/services.yml (front office): no container.
     */
    private function getStatePolicy(): OrderStatePolicy
    {
        return new OrderStatePolicy(new ShipmentRepository(), new OrderRepository());
    }

    /**
     * Warning for the order page when the parcels call for a new order state that the
     * module may not set from the current one (module settings), empty otherwise.
     */
    private function getBlockedStateWarning(int $orderId): string
    {
        $order = new Order($orderId);

        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        $policy = $this->getStatePolicy();
        $targetState = $policy->getTargetState($order, $policy->getProgress($orderId));
        $currentState = (int) $order->getCurrentState();

        if (!$targetState || $targetState === $currentState || $policy->mayChangeCurrentState($order)) {
            return '';
        }

        $languageId = (int) $this->context->language->id;

        return $this->trans(
            'According to its parcels, this order should be in the state "%target%", but the module did not change it: its current state "%current%" is not one the module may change (module settings).',
            [
                '%target%' => (new OrderState($targetState, $languageId))->name,
                '%current%' => (new OrderState($currentState, $languageId))->name,
            ],
            'Modules.Drsoftfrhubasyou.Admin'
        );
    }

    /**
     * Rows of the repository → data for the templates (labels and dates in the context language).
     */
    private function presentShipments(array $rows): array
    {
        $labels = $this->getStatusLabels();
        $date = static fn (?string $value): string => $value ? Tools::displayDate($value) : '';

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id_shipment'],
            'external_reference' => (string) $row['external_reference'],
            'carrier_name' => (string) $row['carrier_name'],
            'tracking_number' => (string) $row['tracking_number'],
            // For {l} sprintf values, which are never escaped (and the front office |escape is a no-op)
            'tracking_number_html' => htmlspecialchars((string) $row['tracking_number'], ENT_QUOTES, 'UTF-8'),
            'tracking_url' => (string) ($row['tracking_url'] ?? ''),
            'status' => (string) $row['status'],
            'status_label' => $labels[$row['status']] ?? (string) $row['status'],
            'shipped_at' => $date($row['shipped_at']),
            'delivered_at' => $date($row['delivered_at']),
            'mail_sent_at' => $date($row['mail_sent_at']),
            'lines' => $row['lines'],
        ], $rows);
    }

    /**
     * Labels of the parcel states (customer account, back office).
     *
     * @return array<string, string>
     */
    public function getStatusLabels(): array
    {
        return [
            ShipmentStatus::Shipped->value => $this->trans('Shipped', [], 'Modules.Drsoftfrhubasyou.Shop'),
            ShipmentStatus::InTransit->value => $this->trans('In transit', [], 'Modules.Drsoftfrhubasyou.Shop'),
            ShipmentStatus::Delivered->value => $this->trans('Delivered', [], 'Modules.Drsoftfrhubasyou.Shop'),
            ShipmentStatus::Exception->value => $this->trans('Delivery problem', [], 'Modules.Drsoftfrhubasyou.Shop'),
        ];
    }

    private function handleException(Throwable $t): void
    {
        $message = sprintf('%s - %s:%d - %s', $this->name, basename($t->getFile()), $t->getLine(), $t->getMessage());

        PrestaShopLogger::addLog($message, 3);

        $this->_errors[] = $message;
    }
}
