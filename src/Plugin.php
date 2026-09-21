<?php

declare(strict_types=1);

namespace wmd\commerceeracuni;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use verbb\base\helpers\Plugin as VerbbPlugin;
use verbb\base\LogTrait;
use wmd\commerceeracuni\models\Settings;
use wmd\commerceeracuni\services\DeliverySync;
use wmd\commerceeracuni\services\Documents;
use wmd\commerceeracuni\services\OrderSnapshotFactory;
use wmd\commerceeracuni\variables\EracuniVariable;
use yii\base\Event;

/**
 * e-Računi for Commerce.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Documents $documents
 * @property-read OrderSnapshotFactory $snapshots
 * @property-read DeliverySync $deliverySync
 */
class Plugin extends BasePlugin
{
    use LogTrait;

    public const PERM_MANAGE = 'commerce-eracuni-manage-documents';

    public string $schemaVersion = '1.0.1';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return ['components' => [
            'documents' => Documents::class,
            'snapshots' => OrderSnapshotFactory::class,
            'deliverySync' => DeliverySync::class,
        ]];
    }

    public function init(): void
    {
        parent::init();
        VerbbPlugin::bootstrapPlugin('commerce-eracuni');

        if (!class_exists(Commerce::class)) {
            return;
        }

        $this->registerPermissions();
        $this->registerCpRoutes();
        $this->registerVariable();

        Craft::$app->onInit(function() {
            $this->registerOrderEvents();
            $this->registerOrderPanel();
        });
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('commerce-eracuni/settings'));
    }

    public function getCpNavItem(): ?array
    {
        $nav = parent::getCpNavItem();
        $nav['label'] = Craft::t('commerce-eracuni', 'e-Računi');
        $nav['subnav']['documents'] = ['label' => Craft::t('commerce-eracuni', 'Documents'), 'url' => 'commerce-eracuni'];
        if (Craft::$app->getUser()->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $nav['subnav']['settings'] = ['label' => Craft::t('commerce-eracuni', 'Settings'), 'url' => 'commerce-eracuni/settings'];
        }
        return $nav;
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    private function registerOrderEvents(): void
    {
        $handler = function(Event $e): void {
            $order = $e->sender;
            if (!$order instanceof Order || $order->getIsDraft() || $order->propagating) {
                return;
            }
            if ($this->documents->shouldAutoSend($order)) {
                $this->documents->queue($order);
            }
        };
        Event::on(Order::class, Order::EVENT_AFTER_SAVE, function(ModelEvent $e) use ($handler) {
            if (!$e->isNew) {
                $handler($e);
            }
        });
        Event::on(Order::class, Order::EVENT_AFTER_ORDER_PAID, $handler);
    }

    private function registerOrderPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context): string {
            $order = $context['order'] ?? null;
            if (!$order instanceof Order || !$order->isCompleted || !Craft::$app->getUser()->checkPermission(self::PERM_MANAGE)) {
                return '';
            }
            return Craft::$app->getView()->renderTemplate('commerce-eracuni/_order-panel', [
                'order' => $order,
                'document' => $this->documents->getForOrder((int) $order->id),
            ], View::TEMPLATE_MODE_CP);
        });
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $e) {
            $e->rules['commerce-eracuni'] = 'commerce-eracuni/documents/index';
            $e->rules['commerce-eracuni/settings'] = 'commerce-eracuni/settings/index';
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $e) {
            $e->permissions[] = [
                'heading' => Craft::t('commerce-eracuni', 'e-Računi for Commerce'),
                'permissions' => [
                    self::PERM_MANAGE => ['label' => Craft::t('commerce-eracuni', 'Send, preview and retry e-računi documents')],
                ],
            ];
        });
    }

    private function registerVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $e) {
            /** @var CraftVariable $v */
            $v = $e->sender;
            $v->set('commerceEracuni', EracuniVariable::class);
        });
    }
}
