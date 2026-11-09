<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

use DrSoftFr\Module\HubAsYou\Application\Service\OrderStatePolicy;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentEffects;
use DrSoftFr\Module\HubAsYou\Domain\Shipment;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentLine;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use DrSoftFr\Module\HubAsYou\Infrastructure\Configuration\Settings;
use DrSoftFr\Module\HubAsYou\Infrastructure\Install\Installer;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\OrderLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ShipmentEffectsLog;
use DrSoftFr\Module\HubAsYou\Infrastructure\Mail\ShipmentMailer;
use DrSoftFr\Module\HubAsYou\Infrastructure\Order\OrderStateWriter;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\DatabaseError;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderMailRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\OrderRepository;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\ShipmentLogRepository;
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
    /** Hidden tab of the back office that edits and deletes a parcel and shows the whole history of an order's parcels (controller and permissions) */
    public const TAB_SHIPMENT = 'AdminDrSoftFrHubAsYouShipment';

    /** Lines of the parcel history shown on the order page, the most recent ones; the rest is on the history page */
    public const JOURNAL_ORDER_PAGE_LINES = 20;

    /**
     * @var string Author email
     */
    public $authorEmail;

    /**
     * @var string Module GitHub repository URL
     */
    public $moduleGithubRepositoryUrl;

    /**
     * @var string Module GitHub issues URL
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
            // The Admin API features the module relies on were only tested on 9.1
            'min' => '9.1.0',
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
        $this->version = '1.0.1';
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
     * True when a form of the back office was really sent. Tools::isSubmit() alone also
     * accepts a GET: a mere link with the tokens would change or delete data.
     */
    public function isPosted(string $submit): bool
    {
        return Tools::isSubmit($submit) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }

    /**
     * Configuration page: order states and e-mail, plus the steps to give HubAsYou its access.
     */
    public function getContent(): string
    {
        $output = '';

        try {
            if ($this->isPosted('submitDrSoftFrHubAsYouConfig')) {
                $output .= $this->saveSettings()
                    ? $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Drsoftfrhubasyou.Admin'))
                    : $this->displayError($this->trans('The settings could not all be saved: check them below and save again. The error is in Advanced parameters > Logs.', [], 'Modules.Drsoftfrhubasyou.Admin'));
                $output .= $this->getShippedStateNotice();
            }

            return $output . $this->renderSetupHelp() . $this->renderForm() . $this->renderFooter();
        } catch (Throwable $t) {
            return $output . $this->displayError($t->getMessage()) . $this->renderFooter();
        }
    }

    /**
     * Signature of the author at the bottom of the back-office screens of the module:
     * name, version, contact, GitHub links and the drSoft.fr logo.
     */
    public function renderFooter(): string
    {
        $this->context->smarty->assign('hubasyou_footer', [
            'name' => $this->displayName,
            'version' => $this->version,
            'author' => $this->author,
            'email' => $this->authorEmail,
            'repository' => $this->moduleGithubRepositoryUrl,
            'issues' => $this->moduleGithubIssuesUrl,
            'logo' => $this->getPathUri() . 'views/img/logo-dark-master.png',
        ]);

        return $this->context->smarty->fetch($this->getLocalPath() . 'views/templates/admin/_footer.tpl');
    }

    /**
     * Every setting is written, even after a failure. Each failure is logged at once, with
     * the error of the database (the next query would clear it).
     */
    private function saveSettings(): bool
    {
        $writes = [
            Settings::ORDER_STATE_SHIPPED => static fn (): bool => Configuration::updateValue(Settings::ORDER_STATE_SHIPPED, (int) Tools::getValue('order_state_shipped', 0)),
            Settings::ORDER_STATE_DELIVERED => static fn (): bool => Configuration::updateValue(Settings::ORDER_STATE_DELIVERED, (int) Tools::getValue('order_state_delivered', 0)),
            Settings::SEND_MAIL => static fn (): bool => Configuration::updateValue(Settings::SEND_MAIL, (int) (bool) Tools::getValue('send_mail', 0)),
            Settings::SEND_DELIVERED_MAIL => static fn (): bool => Configuration::updateValue(Settings::SEND_DELIVERED_MAIL, (int) (bool) Tools::getValue('send_delivered_mail', 0)),
            Settings::CHANGEABLE_STATES => fn (): bool => (new Settings())->setChangeableStates(array_filter(
                array_column(OrderState::getOrderStates((int) $this->context->language->id), 'id_order_state'),
                static fn ($stateId): bool => (bool) Tools::getValue('changeable_state_' . (int) $stateId)
            )),
        ];
        $saved = true;

        foreach ($writes as $key => $write) {
            $failure = sprintf('The module setting %s could not be saved.', $key);

            try {
                if (!$write()) {
                    DatabaseError::log($failure);
                    $saved = false;
                }
            } catch (Throwable $t) {
                DatabaseError::log($failure, $t);
                $saved = false;
            }
        }

        return $saved;
    }

    /**
     * The merchant unticked the state set when the first parcel leaves: it stays changeable
     * (Settings::getChangeableStates) and its box ticked; the notice says why.
     */
    private function getShippedStateNotice(): string
    {
        $shippedState = (int) Tools::getValue('order_state_shipped', 0);

        if (!$shippedState || Tools::getValue('changeable_state_' . $shippedState)) {
            return '';
        }

        $state = new OrderState($shippedState, (int) $this->context->language->id);

        return $this->displayWarning($this->trans(
            'The state "%state%" stays ticked: it is the state set when the first parcel leaves, and the module must be able to change it to set the order as delivered.',
            ['%state%' => htmlspecialchars((string) $state->name, ENT_QUOTES, 'UTF-8')],
            'Modules.Drsoftfrhubasyou.Admin'
        ));
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
                        'desc' => $this->trans('The module changes the state of an order only when its current state is ticked here (for example, never from "Canceled" or "Awaiting payment"). Otherwise the state stays as it is and a warning is shown on the order page. The state set when the first parcel leaves is always changeable, so that the order can then be set as delivered.', [], 'Modules.Drsoftfrhubasyou.Admin'),
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
                        'desc' => $this->trans('Sent once per parcel, while it is on its way, in the language of the order (template "drsoftfrhubasyou_shipment"). Not sent for a parcel received as already delivered.', [], 'Modules.Drsoftfrhubasyou.Admin'),
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
        $settings = new Settings();
        $fieldsValue = [
            'order_state_shipped' => $settings->getShippedState(),
            'order_state_delivered' => $settings->getDeliveredState(),
            'send_mail' => (int) $settings->isMailEnabled(),
            'send_delivered_mail' => (int) $settings->isDeliveredMailEnabled(),
        ];
        $changeableStates = $settings->getChangeableStates();

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
            $order = (new OrderRepository())->find($orderId);
            $policy = $this->getStatePolicy();

            foreach ($shipments as &$shipment) {
                foreach ($actions as $action) {
                    $shipment[$action . '_url'] = $this->context->link->getAdminLink(self::TAB_SHIPMENT, true, [], [
                        'id_shipment' => $shipment['id'],
                        'hubasyou_view' => $action,
                    ]);
                }

                if ($order && in_array('edit', $actions, true) && $policy->mayShipmentMailBeSent($order, ShipmentStatus::from($shipment['status']))) {
                    $shipment['mail_url'] = $this->context->link->getAdminLink(self::TAB_SHIPMENT, true, [], [
                        'id_shipment' => $shipment['id'],
                    ]);
                }
            }
            unset($shipment);

            $deliveredMailDate = (new OrderMailRepository())->findDeliveredMailDate($orderId);
            $deliveredMailAllowed = $shipments && $this->mayDeliveredMailBeSent($orderId);
            $journal = new ShipmentLogRepository();
            $journalTotal = $journal->countByOrder($orderId);

            $this->context->smarty->assign([
                'hubasyou_shipments' => $shipments,
                'hubasyou_journal' => $this->presentJournal($journal->findByOrder($orderId, self::JOURNAL_ORDER_PAGE_LINES)),
                'hubasyou_journal_total' => $journalTotal,
                // Same permission as the history page: "view" on the parcel tab
                'hubasyou_journal_url' => $journalTotal > self::JOURNAL_ORDER_PAGE_LINES && $employee && $employee->can('view', self::TAB_SHIPMENT)
                    ? $this->getJournalUrl($orderId)
                    : '',
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
                new OrderRepository(),
                $this->getStatePolicy(),
                new OrderMailRepository(),
                new ShipmentMailer(),
                new OrderStateWriter(null, (int) ($this->context->employee->id ?? 0)),
                new ShipmentEffectsLog()
            );

            (new OrderLock())->synchronized($orderId, static fn () => $effects->onOrderChanged($orderId));
        } catch (Throwable $t) {
            $this->handleException($t);
        }
    }

    private function mayDeliveredMailBeSent(int $orderId): bool
    {
        $order = (new OrderRepository())->find($orderId);

        return $order && $this->getStatePolicy()->mayDeliveredMailBeSent($order);
    }

    /**
     * Hooks run outside the kernels that load config/services.yml (front office): no container.
     */
    private function getStatePolicy(): OrderStatePolicy
    {
        return new OrderStatePolicy(new ShipmentRepository(), new OrderRepository(), new Settings());
    }

    /**
     * Warning for the order page when the parcels call for a new order state that the
     * module may not set from the current one (module settings), empty otherwise.
     */
    private function getBlockedStateWarning(int $orderId): string
    {
        $order = (new OrderRepository())->find($orderId);

        if (!$order) {
            return '';
        }

        $policy = $this->getStatePolicy();
        $targetState = $policy->getTargetState($order, $policy->getProgress($orderId));

        if (!$targetState || $targetState === $order->currentState || $policy->mayChangeCurrentState($order)) {
            return '';
        }

        $languageId = (int) $this->context->language->id;

        return $this->trans(
            'According to its parcels, this order should be in the state "%target%", but the module did not change it: its current state "%current%" is not one the module may change (module settings).',
            [
                '%target%' => (new OrderState($targetState, $languageId))->name,
                '%current%' => (new OrderState($order->currentState, $languageId))->name,
            ],
            'Modules.Drsoftfrhubasyou.Admin'
        );
    }

    /**
     * Parcels → data for the templates (labels and dates in the context language).
     *
     * @param Shipment[] $shipments
     */
    private function presentShipments(array $shipments): array
    {
        $labels = $this->getStatusLabels();
        $date = static fn (?DateTimeImmutable $value): string => $value ? Tools::displayDate($value->format(ShipmentRepository::DATE_FORMAT)) : '';

        return array_map(static fn (Shipment $shipment): array => [
            'id' => $shipment->id,
            'external_reference' => $shipment->externalReference,
            'carrier_name' => $shipment->carrierName,
            'tracking_number' => $shipment->trackingNumber,
            // For {l} sprintf values, which are never escaped (and the front office |escape is a no-op)
            'tracking_number_html' => htmlspecialchars($shipment->trackingNumber, ENT_QUOTES, 'UTF-8'),
            'tracking_url' => (string) $shipment->trackingUrl,
            'status' => $shipment->status->value,
            'status_label' => $labels[$shipment->status->value],
            'shipped_at' => $date($shipment->shippedAt),
            'delivered_at' => $date($shipment->deliveredAt),
            'mail_sent_at' => $date($shipment->mailSentAt),
            'lines' => array_map(static fn (ShipmentLine $line): array => [
                'quantity' => $line->quantity,
                'product_name' => $line->productName,
                'product_reference' => $line->productReference,
            ], $shipment->lines),
        ], $shipments);
    }

    /**
     * Back office page with the whole history of an order's parcels, one page of lines at a time.
     *
     * @param int<1, max> $page
     */
    public function getJournalUrl(int $orderId, int $page = 1): string
    {
        return $this->context->link->getAdminLink(self::TAB_SHIPMENT, true, [], array_filter([
            'hubasyou_view' => 'journal',
            'id_order' => $orderId,
            'page' => $page > 1 ? $page : null,
        ]));
    }

    /**
     * Journal rows → data for the order page and the history page: who, what and the
     * details, in the context language. Raw values: the template escapes them.
     *
     * @param array<int, array<string, mixed>> $rows ShipmentLogRepository::findByOrder()
     *
     * @return array<int, array{date: string, reference: string, action: string, actor: string, details: string[]}>
     */
    public function presentJournal(array $rows): array
    {
        $domain = 'Modules.Drsoftfrhubasyou.Admin';
        $actions = [
            ShipmentLogRepository::ADDED => $this->trans('Parcel declared', [], $domain),
            ShipmentLogRepository::RECEIVED_AGAIN => $this->trans('Parcel received again, unchanged', [], $domain),
            ShipmentLogRepository::EDITED => $this->trans('Parcel edited', [], $domain),
            ShipmentLogRepository::DELETED => $this->trans('Parcel deleted', [], $domain),
            ShipmentLogRepository::SHIPMENT_MAIL_SENT => $this->trans('"Parcel on its way" e-mail sent', [], $domain),
            ShipmentLogRepository::DELIVERED_MAIL_SENT => $this->trans('"Order delivered" e-mail sent', [], $domain),
        ];
        $fields = [
            'status' => $this->trans('Status', [], $domain),
            'carrier_name' => $this->trans('Carrier', [], $domain),
            'tracking_number' => $this->trans('Tracking number', [], $domain),
            'tracking_url' => $this->trans('Tracking URL', [], $domain),
            'shipped_at' => $this->trans('Shipped on', [], $domain),
            'delivered_at' => $this->trans('Delivered on', [], $domain),
        ];
        $statuses = $this->getStatusLabels();
        $value = static function (string $field, mixed $raw) use ($statuses): string {
            if ($raw === null || $raw === '') {
                return '—';
            }

            return match ($field) {
                'status' => $statuses[(string) $raw] ?? (string) $raw,
                'shipped_at', 'delivered_at' => Tools::displayDate((string) $raw, true),
                default => (string) $raw,
            };
        };

        return array_map(function (array $row) use ($actions, $fields, $value, $domain): array {
            $details = [];
            $data = $row['details'];

            if (isset($data['changes']) && is_array($data['changes'])) {
                foreach ($data['changes'] as $field => [$old, $new]) {
                    $details[] = $this->trans('%field%: %old% → %new%', ['%field%' => $fields[$field] ?? $field, '%old%' => $value($field, $old), '%new%' => $value($field, $new)], $domain);
                }
            } else {
                foreach ($fields as $field => $label) {
                    if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                        $details[] = $this->trans('%field%: %value%', ['%field%' => $label, '%value%' => $value($field, $data[$field])], $domain);
                    }
                }

                foreach ($data['lines'] ?? [] as $line) {
                    $details[] = sprintf(
                        '%d × %s%s',
                        (int) ($line['quantity'] ?? 0),
                        (string) ($line['product_name'] ?? ''),
                        !empty($line['product_reference']) ? ' (' . $line['product_reference'] . ')' : ''
                    );
                }
            }

            if ($row['api_client'] !== null) {
                $actor = $this->trans('API client "%name%"', ['%name%' => $row['api_client']], $domain);
            } elseif ($row['id_employee'] !== null) {
                $actor = $row['lastname'] !== null
                    ? trim($row['firstname'] . ' ' . $row['lastname'])
                    : $this->trans('Employee #%id%', ['%id%' => (int) $row['id_employee']], $domain);
            } else {
                $actor = $this->trans('Unknown', [], $domain);
            }

            return [
                'date' => Tools::displayDate((string) $row['date_add'], true),
                'reference' => (string) $row['external_reference'],
                'action' => $actions[$row['action']] ?? (string) $row['action'],
                'actor' => $actor,
                'details' => $details,
            ];
        }, $rows);
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
