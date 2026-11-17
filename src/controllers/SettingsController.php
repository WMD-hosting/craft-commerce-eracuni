<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\helpers\App;
use craft\web\Controller;
use wmd\commerceeracuni\core\Client;
use wmd\commerceeracuni\core\PaymentMethodMap;
use wmd\commerceeracuni\helpers\Kpd;
use wmd\commerceeracuni\models\Settings;
use wmd\commerceeracuni\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireAdmin();
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $settings = Plugin::getInstance()->getSettings();
        $gateways = [];
        foreach (Commerce::getInstance()->getGateways()->getAllGateways() as $g) {
            $saved = $settings->paymentMap[$g->handle] ?? null;
            $gateways[] = [
                'handle' => $g->handle,
                'name' => $g->name,
                'mapping' => $saved ?? PaymentMethodMap::suggest($g->handle, get_class($g)),
                'suggested' => $saved === null,
            ];
        }
        return $this->renderTemplate('commerce-eracuni/settings/index', [
            'settings' => $settings,
            'gateways' => $gateways,
            'methods' => array_keys(PaymentMethodMap::METHODS),
            'invoiceMethods' => array_values(array_unique(array_column(PaymentMethodMap::METHODS, 'paymentMethodForInvoice'))),
            'orderStatuses' => Commerce::getInstance()->getOrderStatuses()->getAllOrderStatuses(),
            'productTypes' => Commerce::getInstance()->getProductTypes()->getAllProductTypes(),
            'kpdNotes' => $this->kpdNotes($settings),
        ]);
    }

    /**
     * The title of each KPD code on the KPD pane, or why it cannot be used, and whether it needs attention (invalid,
     * ending, or not started yet).
     *
     * @return array<string, array{notice: string, attention: bool}>
     */
    private function kpdNotes(Settings $settings): array
    {
        $notes = [];
        $today = Kpd::today();
        foreach ([$settings->shippingKpd, $settings->defaultKpd, ...array_values($settings->kpdDefaults)] as $value) {
            $check = Kpd::check((string) $value, $today);
            // A code saved before 1.1.0 (the old shipping default 532000, say) was never checked: say so here,
            // since only a save would show it as an error.
            $error = Kpd::error($check);
            $notice = $error ?? Kpd::notice($check);
            if ($notice !== null) {
                $notes[(string) $value] = ['notice' => $notice, 'attention' => $error !== null || Kpd::needsAttention($check)];
            }
        }
        return $notes;
    }

    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $s = Plugin::getInstance()->getSettings();
        try {
            $client = new Client(App::parseEnv($s->apiUrl), App::parseEnv($s->username), App::parseEnv($s->authToken));
            $res = $client->get('PartnerList', ['limit' => 1], 1);
            $ok = ($res['response']['status'] ?? '') === 'ok';
            $message = $ok ? Craft::t('commerce-eracuni', 'Connected.') : mb_substr((string) json_encode($res), 0, 500);
            return $this->asJson(['success' => $ok, 'message' => $message]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
