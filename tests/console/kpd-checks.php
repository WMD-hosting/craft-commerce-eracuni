<?php
/**
 * KPD 2025 in a real Craft install: settings normalise and refuse codes (in Croatian too), the KPD
 * pane shows titles, warnings and errors, an order preview carries codes in the form e-računi
 * takes, and `sync/kpd` reads codes from the settings and from a field on products and variants.
 *
 * Usage, from a Craft install with Commerce and this plugin:
 *   php tests/console/kpd-checks.php <craft-root> [--src=<plugin src dir>]
 * Writes nothing: settings are validated, never saved, and e-računi is never called.
 * Exit code 0 = all PASS.
 */

$args = array_slice($argv, 1);
$src = null;
foreach ($args as $i => $arg) {
    if (str_starts_with($arg, '--src=')) {
        $src = realpath(substr($arg, 6)) ?: null;
        unset($args[$i]);
    }
}
$root = array_values($args)[0] ?? null;
if (!$root) {
    fwrite(STDERR, "usage: php tests/console/kpd-checks.php <craft-root> [--src=<dir>]\n");
    exit(2);
}
chdir($root);
require $root . '/bootstrap.php';
if ($src !== null) {
    spl_autoload_register(function(string $class) use ($src) {
        $prefix = 'wmd\\commerceeracuni\\';
        if (str_starts_with($class, $prefix) && is_file($file = $src . '/' . strtr(substr($class, strlen($prefix)), '\\', '/') . '.php')) {
            require $file;
        }
    }, true, true);
}
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\fields\PlainText;
use craft\web\View;
use wmd\commerceeracuni\controllers\SettingsController;
use wmd\commerceeracuni\core\KpdCatalog;
use wmd\commerceeracuni\models\Settings;
use wmd\commerceeracuni\Plugin;

$fail = 0;
$check = function(bool $ok, string $what) use (&$fail) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $what\n";
    $fail += $ok ? 0 : 1;
};

$plugin = Plugin::getInstance();
if ($src !== null) {
    $file = (new ReflectionClass(Plugin::class))->getFileName();
    $check(str_starts_with($file, $src), "plugin classes load from --src ($file)");
    $plugin->setBasePath($src);
    $source = Craft::$app->getI18n()->getMessageSource('commerce-eracuni');
    if ($source instanceof yii\i18n\PhpMessageSource) {
        $source->basePath = $src . '/translations';
    }
}
$check(is_file(dirname((new ReflectionClass(KpdCatalog::class))->getFileName(), 3) . '/resources/kpd/kpd-2025.json'), 'the KPD list ships next to src/');

// 1. Settings.
$s = new Settings();
$check($s->shippingKpd === '53.20.09', 'new installs default the shipping KPD to 53.20.09');
$s->shippingKpd = ' h 53 20 01 ';
$s->defaultKpd = '621011';
$s->kpdDefaults = ['clothing' => 'C14.10.23', 'empty' => ''];
$check($s->validate(['shippingKpd', 'defaultKpd', 'kpdDefaults']), 'valid codes pass: ' . json_encode($s->getErrors()));
$check($s->shippingKpd === '53.20.01' && $s->defaultKpd === '62.10.11' && $s->kpdDefaults === ['clothing' => '14.10.23'], 'codes are stored as the list writes them, empty defaults dropped');

$s = new Settings();
$s->shippingKpd = '532000';
$s->defaultKpd = '62.01';
$s->kpdDefaults = ['books' => '16.12.10', 'tools' => '62.01.11'];
$check(!$s->validate(['shippingKpd', 'defaultKpd', 'kpdDefaults']), 'invalid codes fail');
$check(str_starts_with($s->getFirstError('shippingKpd') ?? '', 'Shipping KPD: KPD 53.20.00 is not a KPD 2025 code. Nearest valid codes: 53.20.01 (Multimodalne kurirske usluge)'), 'the old default is refused, named, with the courier codes suggested: ' . $s->getFirstError('shippingKpd'));
$check(str_contains($s->getFirstError('defaultKpd') ?? '', 'six digits'), 'a code without six digits is refused');
$check(str_starts_with($s->getFirstError('kpdDefaults.tools') ?? '', 'tools: KPD 62.01.11') && $s->getFirstError('kpdDefaults.books') === null, 'per-type errors sit on kpdDefaults.<handle>, named; a code that starts in 2027 is accepted');

$language = Craft::$app->language;
Craft::$app->language = 'hr';
$s = new Settings();
$s->shippingKpd = '532000';
$s->validate(['shippingKpd']);
$check(str_starts_with($s->getFirstError('shippingKpd') ?? '', 'KPD za dostavu: KPD 53.20.00 nije šifra iz KPD 2025. Najbliže valjane šifre: 53.20.01'), 'the refusal is translated: ' . $s->getFirstError('shippingKpd'));
Craft::$app->language = $language;

// 2. The KPD pane.
$s = new Settings();
$s->shippingKpd = '532000';
$s->defaultKpd = '16.12.00';
$s->kpdDefaults = ['x' => '16.12.10'];
$notes = (new ReflectionMethod(SettingsController::class, 'kpdNotes'))->invoke(new SettingsController('settings', $plugin), $s);
$check(str_starts_with($notes['532000']['notice'] ?? '', 'KPD 53.20.00 is not a KPD 2025 code.') && $notes['532000']['attention'] === true, 'a stored code saved before checks existed is flagged on the pane without a save');
$s->kpdDefaults['y'] = '53.20.09';
$notes = (new ReflectionMethod(SettingsController::class, 'kpdNotes'))->invoke(new SettingsController('settings', $plugin), $s);
$check(($notes['53.20.09']['notice'] ?? '') === 'Ostale poštanske i kurirske usluge, d. n.' && $notes['53.20.09']['attention'] === false, 'a valid code shows its title');
$check(str_contains($notes['16.12.00']['notice'] ?? '', 'Valid until') && $notes['16.12.00']['attention'] === true, 'a code that NN 119/26 ends is flagged');
$check(str_contains($notes['16.12.10']['notice'] ?? '', 'Valid from') && $notes['16.12.10']['attention'] === true, 'a code that starts in 2027 is flagged');
$s->addError('defaultKpd', 'Global default KPD: an error on the field.');
$view = Craft::$app->getView();
$view->setTemplateMode(View::TEMPLATE_MODE_CP);
$html = $view->renderTemplate('commerce-eracuni/settings/_panes/kpd', [
    'settings' => $s,
    'productTypes' => Commerce::getInstance()->getProductTypes()->getAllProductTypes(),
    'kpdNotes' => $notes,
]);
$check(str_contains($html, 'Ostale poštanske i kurirske usluge, d. n.'), 'the pane renders the tip');
$check(str_contains($html, 'KPD 53.20.00 is not a KPD 2025 code.'), 'the pane warns about the stored invalid code');
$check(str_contains($html, 'Global default KPD: an error on the field.') && !str_contains($html, 'Valid until'), 'a field with an error shows the error, not its warning as well');
$check(substr_count($html, 'placeholder="62.10.11"') === 2 + count(Commerce::getInstance()->getProductTypes()->getAllProductTypes()), 'every KPD input is on the pane');

// 3. An order preview: the shipping line carries the default in the form e-računi takes.
$order = Order::find()->isCompleted(true)->andWhere(['>', 'commerce_orders.totalShippingCost', 0])->orderBy(['commerce_orders.id' => SORT_DESC])->one();
if ($order instanceof Order) {
    try {
        $preview = $plugin->documents->preview($order);
        $codes = array_column($preview['payload']['Items'], 'classificationCode', 'description');
        $check(in_array('H53.20.09', $codes, true), "order {$order->id} ({$preview['treatment']}): shipping line carries H53.20.09: " . json_encode($codes, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        $check(false, "order {$order->id} preview: " . $e->getMessage());
    }
} else {
    echo "SKIP  no completed order with shipping\n";
}

// 4. sync/kpd (its report goes straight to STDOUT, above each line it belongs to): settings only, then a plain text
// field on products or variants, whose values are no KPD codes.
$run = fn(array $params): int => (int) $app->runAction('commerce-eracuni/sync/kpd', $params);
$code = $run([]);
$check($code === 0, "sync/kpd passes with valid settings (exit {$code})");
$code = $run(['date' => '2027-13-45x']);
$check($code === 64, "sync/kpd refuses a malformed --date (exit {$code})");
$field = null;
foreach (array_merge(
    array_map(fn($t) => $t->getFieldLayout(), Commerce::getInstance()->getProductTypes()->getAllProductTypes()),
    array_map(fn($t) => $t->getVariantFieldLayout(), Commerce::getInstance()->getProductTypes()->getAllProductTypes()),
) as $layout) {
    foreach ($layout?->getCustomFields() ?? [] as $f) {
        if ($f instanceof PlainText) {
            $field = $f;
            break 2;
        }
    }
}
if ($field !== null) {
    $settings = $plugin->getSettings();
    $handle = $settings->kpdFieldHandle;
    $settings->kpdFieldHandle = $field->handle;
    try {
        $code = $run(['date' => '2027-01-01']);
    } finally {
        $settings->kpdFieldHandle = $handle;
    }
    $check($code === 65, "sync/kpd reads field \"{$field->handle}\", on one element type or both, and flags its values (exit {$code})");
} else {
    echo "SKIP  no plain text field on a product or variant layout\n";
}

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
