<?php
/**
 * The 1.0.2 send guards against a real Craft install: an outcome-unknown create is flagged on its
 * row (already while the request is in flight) and no path sends the order again (auto-send,
 * claim, console retry and order, the CP actions) until staff verify the attempt they checked; the
 * order panel has no form or named input to leak into Commerce's order form; the migration flags
 * rows that failed that way before;
 * backfill queries by resolved status ids and queues nothing when no trigger status exists.
 *
 * Usage, from a Craft install with Commerce and this plugin:
 *   php tests/console/retry-guards.php <craft-root> [--src=<plugin src dir>]
 * e-računi is never called: the Documents client is a stub whose invoice create times out.
 * Adding the `outcomeUnknown` column is DDL and stays; everything else runs in one transaction
 * that is rolled back. Exit code 0 = all PASS.
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
    fwrite(STDERR, "usage: php tests/console/retry-guards.php <craft-root> [--src=<dir>]\n");
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
use craft\db\Query;
use wmd\commerceeracuni\core\ClientInterface;
use wmd\commerceeracuni\core\EracuniException;
use wmd\commerceeracuni\jobs\SendInvoiceJob;
use wmd\commerceeracuni\migrations\m261023_000000_add_outcome_unknown;
use wmd\commerceeracuni\Plugin;
use wmd\commerceeracuni\records\DocumentRecord;

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
    // The message source was registered at boot with the installed copy's path.
    $source = Craft::$app->getI18n()->getMessageSource('commerce-eracuni');
    if ($source instanceof yii\i18n\PhpMessageSource) {
        $source->basePath = $src . '/translations';
    }
}

final class TimeoutClient implements ClientInterface
{
    public array $calls = [];
    /** Runs inside the create call, before it fails: sees the row as a concurrent claimer would. */
    public ?Closure $duringCreate = null;
    public ?Throwable $createError = null;

    public function call(string $method, array $data = [], int $maxRetries = 3, int $timeoutSeconds = 30): array
    {
        $this->calls[] = $method;
        if ($method === 'SalesInvoiceCreate') {
            if ($this->duringCreate) {
                ($this->duringCreate)();
            }
            throw $this->createError ?? EracuniException::transport('cURL error 28: Operation timed out after 120000 milliseconds');
        }
        throw new LogicException("Unexpected e-računi call {$method}");
    }

    public function get(string $method, array $params = [], int $maxRetries = 3): array
    {
        $this->calls[] = $method;
        if ($method === 'PartnerList') {
            return ['response' => ['status' => 'ok', 'result' => [['documentID' => 'P-1', 'BuyerData' => ['buyerCode' => 'WEB-1']]]]];
        }
        throw new LogicException("Unexpected e-računi call {$method}");
    }
}

// Column first: DDL commits implicitly, so it cannot sit inside the rolled-back transaction.
$migration = new m261023_000000_add_outcome_unknown();
$migration->compact = true;
$migration->safeUp();

$documents = $plugin->documents;
$client = new TimeoutClient();
$documents->setClient($client);
$settings = $plugin->getSettings();
$table = DocumentRecord::tableName();
$db = Craft::$app->getDb();

$orders = Order::find()->isCompleted(true)->orderBy(['commerce_orders.id' => SORT_DESC])->limit(6)->all();
if (count($orders) < 6) {
    fwrite(STDERR, "Needs six completed orders.\n");
    exit(2);
}
[$unknownOrder, $refusedOrder, $legacyOrder, $retriedOrder, $deadOrder, $definiteOrder] = $orders;

$jobsFor = function(string $since) use ($db): array {
    $ids = [];
    foreach ((new Query())->select(['job'])->from('{{%queue}}')->where(['>=', 'id', $since])->column($db) as $blob) {
        $job = unserialize(is_resource($blob) ? stream_get_contents($blob) : $blob);
        if ($job instanceof SendInvoiceJob) {
            $ids[] = $job->orderId;
        }
    }
    sort($ids);
    return $ids;
};
$nextQueueId = fn() => (string) (((int) (new Query())->from('{{%queue}}')->max('id', $db)) + 1);
$row = fn(Order $o) => DocumentRecord::findOne(['orderId' => $o->id, 'kind' => DocumentRecord::KIND_INVOICE]);

$transaction = $db->beginTransaction();
try {
    $db->createCommand()->delete($table, ['orderId' => array_map(fn($o) => $o->id, $orders)])->execute();

    // 1. A timed-out create flags the row, already while the request is in flight.
    $inFlight = null;
    $client->duringCreate = function() use (&$inFlight, $row, $unknownOrder, $documents) {
        $r = $row($unknownOrder);
        $inFlight = [$r->status, (bool) $r->outcomeUnknown, $documents->claim($unknownOrder, true) === null];
    };
    try {
        $documents->send($unknownOrder);
        $check(false, 'send() with a timed-out create throws');
    } catch (EracuniException $e) {
        $check($e->isOutcomeUnknown(), 'a timed-out create throws an outcome-unknown error');
    }
    $r = $row($unknownOrder);
    $check($r && $r->status === 'failed' && (bool) $r->outcomeUnknown, "row is failed with outcomeUnknown set (order {$unknownOrder->id})");
    $check($documents->isOutcomeUnknown((int) $unknownOrder->id), 'Documents::isOutcomeUnknown() reports it');
    $check($documents->getForOrder((int) $unknownOrder->id)?->outcomeUnknown === true, 'the Document model carries the flag');
    $creates = count(array_filter($client->calls, fn($c) => $c === 'SalesInvoiceCreate'));
    $check($creates === 1, "e-računi saw one create ({$creates})");
    $check($inFlight === ['pending', true, true], 'during the create the row is pending, flagged, and a forced claim is refused (' . json_encode($inFlight) . ')');
    $client->duringCreate = null;

    // A process that dies mid-create (here: an unexpected error) keeps the flag; an explicit refusal clears it.
    $client->createError = new RuntimeException('worker killed');
    try {
        $documents->send($deadOrder);
    } catch (RuntimeException) {
    }
    $check((bool) $row($deadOrder)->outcomeUnknown, 'an unexpected error during the create keeps the row flagged');
    $client->createError = EracuniException::domain('SalesInvoiceCreate failed: Invalid KPD');
    try {
        $documents->send($definiteOrder);
    } catch (EracuniException) {
    }
    $check(!$row($definiteOrder)->outcomeUnknown && $row($definiteOrder)->status === 'failed', 'an explicit refusal leaves the row unflagged');
    $client->createError = null;

    // A definite refusal for comparison.
    $db->createCommand()->insert($table, ['orderId' => $refusedOrder->id, 'kind' => 'invoice', 'status' => 'failed', 'error' => 'SalesInvoiceCreate failed: Invalid KPD', 'attempts' => 1])->execute();
    $check(!$documents->isOutcomeUnknown((int) $refusedOrder->id), 'a definite refusal is not outcome-unknown');

    // 2. Auto-send skips it, but still retries a definite refusal.
    $settings->autoSend = true;
    $settings->requirePaid = false;
    $settings->triggerStatuses = array_values(array_unique([$unknownOrder->getOrderStatus()?->handle, $refusedOrder->getOrderStatus()?->handle]));
    $check(!$documents->shouldAutoSend($unknownOrder), 'auto-send skips the outcome-unknown order');
    $check($documents->shouldAutoSend($refusedOrder), 'auto-send still retries a definite refusal');

    // 3. claim()/send() refuse it even with force; the job path is the same call.
    $check($documents->claim($unknownOrder, true) === null, 'claim(force) refuses it');
    $calls = count($client->calls);
    $doc = $documents->send($unknownOrder, true);
    $check(count($client->calls) === $calls && $doc->status === 'failed', 'send(force) returns without calling e-računi');
    $r = $row($unknownOrder);
    $check($r->status === 'failed' && (bool) $r->outcomeUnknown && (int) $r->attempts === 1, 'the row is untouched');

    // 4. Console retry re-queues the refusal only.
    $since = $nextQueueId();
    $code = Craft::$app->runAction('commerce-eracuni/sync/retry', ['all' => 1]);
    $queued = $jobsFor($since);
    $check($code === 0 && in_array((int) $refusedOrder->id, $queued, true), 'sync/retry re-queues the definite refusal');
    $check(!in_array((int) $unknownOrder->id, $queued, true), 'sync/retry skips the outcome-unknown order');

    // 5. Console order refuses without the override, sends with it.
    $calls = count($client->calls);
    $code = Craft::$app->runAction('commerce-eracuni/sync/order', [(string) $unknownOrder->id]);
    $check($code === 1 && count($client->calls) === $calls, 'sync/order refuses it without --verified-attempt');
    $code = Craft::$app->runAction('commerce-eracuni/sync/order', [(string) $unknownOrder->id, 'verifiedAttempt' => '0']);
    $check($code === 1 && count($client->calls) === $calls, 'sync/order refuses a verification of another attempt');
    $code = Craft::$app->runAction('commerce-eracuni/sync/order', [(string) $unknownOrder->id, 'verifiedAttempt' => '1']);
    $r = $row($unknownOrder);
    $check(in_array('SalesInvoiceCreate', array_slice($client->calls, $calls), true) && (int) $r->attempts === 2, 'sync/order --verified-attempt=1 sends again');
    $check((bool) $r->outcomeUnknown, 'a second timeout flags the row again');
    $calls = count($client->calls);
    $code = Craft::$app->runAction('commerce-eracuni/sync/order', [(string) $unknownOrder->id, 'verifiedAttempt' => '1']);
    $check($code === 1 && count($client->calls) === $calls, 're-running the same command after another unknown outcome is refused');

    // 6. An override names the attempt staff checked: an older one no longer counts.
    $check($documents->claim($unknownOrder, true, 1) === null, 'a verification of attempt 1 does not cover attempt 2');
    $taken = $documents->claim($unknownOrder, true, 2);
    $check($taken !== null && $taken->status === 'pending' && !$taken->outcomeUnknown && (int) $taken->attempts === 3, 'verifying attempt 2 takes it over and clears the flag');

    // 6b. A flagged pending row (worker died mid-create) waits for the TTR, then needs verification.
    $deadAttempts = (int) $row($deadOrder)->attempts;
    $db->createCommand()->update($table, ['status' => 'pending'], ['orderId' => $deadOrder->id])->execute();
    $check($documents->claim($deadOrder, true, $deadAttempts) === null, 'a fresh flagged pending row is not taken, even verified');
    $check($documents->getForOrder((int) $deadOrder->id)?->canVerifyNotBooked === false, 'the CP offers no override for it yet');
    $db->createCommand()->update($table, ['dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime('-1 day'))], ['orderId' => $deadOrder->id])->execute();
    $check($documents->getForOrder((int) $deadOrder->id)?->canVerifyNotBooked === true, 'past the TTR the CP offers the override');
    $check($documents->claim($deadOrder, true) === null, 'a stale flagged pending row is still not taken without verification');
    $check($documents->claim($deadOrder, true, $deadAttempts) !== null, 'a stale flagged pending row is taken once verified');

    // 7. The migration flags rows that failed this way before 1.0.2, and retried failures whose text may be gone.
    $db->createCommand()->insert($table, ['orderId' => $retriedOrder->id, 'kind' => 'invoice', 'status' => 'failed', 'error' => 'PartnerList: cURL error 28', 'attempts' => 3])->execute();
    $db->createCommand()->insert($table, ['orderId' => $legacyOrder->id, 'kind' => 'invoice', 'status' => 'failed', 'error' => 'SalesInvoiceCreate outcome unknown (cURL error 28). Verify in e-računi before retrying this order.', 'attempts' => 1])->execute();
    $check(!$documents->isOutcomeUnknown((int) $legacyOrder->id), 'a pre-1.0.2 row starts unflagged');
    $migration->safeUp();
    $check($documents->isOutcomeUnknown((int) $legacyOrder->id), 'the migration flags it from the error text');
    $check($documents->isOutcomeUnknown((int) $retriedOrder->id), 'the migration flags a retried failure with no documentID');
    $db->createCommand()->update($table, ['status' => 'pending', 'outcomeUnknown' => false, 'attempts' => 1, 'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime('-1 hour'))], ['orderId' => $retriedOrder->id])->execute();
    $migration->safeUp();
    $check($documents->isOutcomeUnknown((int) $retriedOrder->id), 'the migration flags a pending row with no documentID left past the TTR');
    $check(!$documents->isOutcomeUnknown((int) $refusedOrder->id), 'the migration leaves a single definite refusal alone');

    // 8. Trigger statuses resolve to ids in every store; none resolving queues nothing.
    $all = (int) Order::find()->isCompleted(true)->count();
    $check((int) Order::find()->isCompleted(true)->orderStatus(['confirmed'])->count() === $all, "precondition: Commerce ignores an unknown handle ({$all} orders match 'confirmed')");
    $settings->triggerStatuses = ['confirmed'];
    $resolved = $documents->triggerStatusIds();
    $check($resolved === ['ids' => [], 'unknown' => ['confirmed']], 'triggerStatusIds() resolves nothing for confirmed');
    $since = $nextQueueId();
    $code = Craft::$app->runAction('commerce-eracuni/sync/backfill', ['limit' => 1000]);
    $check($code === 78 && $jobsFor($since) === [], 'backfill with no existing trigger status exits CONFIG and queues nothing');

    $settings->triggerStatuses = ['confirmed', 'shipped'];
    $resolved = $documents->triggerStatusIds();
    $shippedIds = array_map('intval', (new Query())->select('id')->from('{{%commerce_orderstatuses}}')->where(['handle' => 'shipped'])->column());
    sort($shippedIds);
    $ids = $resolved['ids'];
    sort($ids);
    $check($ids === $shippedIds && $resolved['unknown'] === ['confirmed'], 'shipped resolves in every store (' . implode(',', $ids) . '), confirmed is reported');
    $expected = array_map('intval', Order::find()->isCompleted(true)->orderStatusId($shippedIds)->ids());
    $expected = array_values(array_diff($expected, array_map('intval', (new Query())->select('orderId')->from($table)->where(['or', ['status' => ['sent', 'pending']], ['outcomeUnknown' => true]])->column())));
    sort($expected);
    $since = $nextQueueId();
    $code = Craft::$app->runAction('commerce-eracuni/sync/backfill', ['limit' => 1000]);
    $check($code === 0 && $jobsFor($since) === $expected, 'backfill queues exactly the shipped orders (' . implode(',', $expected) . ')');
    $check(!in_array((int) $legacyOrder->id, $jobsFor($since), true), 'backfill never queues an outcome-unknown order');

    // 9. CP templates render both states.
    Craft::$app->set('request', new craft\web\Request(['url' => '/admin/commerce-eracuni', 'scriptUrl' => '/index.php', 'hostInfo' => 'http://constructor-cms.test', 'isConsoleRequest' => false, 'cookieValidationKey' => Craft::$app->getConfig()->getGeneral()->securityKey, 'enableCsrfValidation' => false]));
    Craft::$app->set('response', new craft\web\Response());
    $view = Craft::$app->getView();
    $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);
    $unknownDoc = $documents->getForOrder((int) $legacyOrder->id);
    $html = $view->renderTemplate('commerce-eracuni/_order-panel', ['order' => $legacyOrder, 'document' => $unknownDoc]);
    $check(str_contains($html, 'data-verified-attempts="' . $unknownDoc->attempts . '"') && !str_contains($html, 'btn small submit'), 'order panel offers only the override, for the attempt shown');
    $check(!preg_match('/<form|<input|\sname=/i', $html), 'order panel has no form or named input to leak into Commerce\'s #main-form');
    $html = $view->renderTemplate('commerce-eracuni/_order-panel', ['order' => $refusedOrder, 'document' => $documents->getForOrder((int) $refusedOrder->id)]);
    $check(!str_contains($html, 'data-verified-attempts') && str_contains($html, 'btn small submit'), 'order panel keeps plain Send for a definite refusal');
    $check(!preg_match('/<form|<input|\sname=/i', $html), 'nor does the plain Send');
    $rows = array_filter([$unknownDoc, $documents->getForOrder((int) $refusedOrder->id)]);
    foreach ($rows as $d) {
        // |datetime reads the signed-in user's preferences, which a console request has none of.
        $d->dateUpdated = null;
    }
    // Only the content block: the CP layout around it needs a web user session.
    $index = fn() => $view->getTwig()->load('commerce-eracuni/documents/index')->renderBlock('content', ['rows' => $rows, 'status' => null]);
    $html = $index();
    $check(substr_count($html, 'name="verifiedAttempts" value="' . $unknownDoc->attempts . '"') === 1 && substr_count($html, 'documents/retry') === 2, 'documents index: override for one row, plain Retry for the other');
    // CP actions: the override must name the current attempt of a settled row.
    $controller = new wmd\commerceeracuni\controllers\DocumentsController('documents', $plugin);
    $mayResend = (new ReflectionMethod($controller, 'mayResend'))->getClosure($controller);
    $request = Craft::$app->getRequest();
    $request->setBodyParams([]);
    $check($mayResend((int) $legacyOrder->id) === [false, null], 'CP: no verification, no resend');
    $request->setBodyParams(['verifiedAttempts' => (string) ($unknownDoc->attempts - 1)]);
    $check($mayResend((int) $legacyOrder->id) === [false, null], 'CP: a verification of another attempt is refused');
    $request->setBodyParams(['verifiedAttempts' => (string) $unknownDoc->attempts]);
    $check($mayResend((int) $legacyOrder->id) === [true, $unknownDoc->attempts], 'CP: verifying the current attempt passes it on');
    $request->setBodyParams([]);
    $check($mayResend((int) $refusedOrder->id) === [true, null], 'CP: a definite refusal needs no verification');

    Craft::$app->language = 'hr';
    $html = $index();
    $check(str_contains($html, 'Provjeri u e-Računima'), 'documents index says "Provjeri u e-Računima" in hr');
} finally {
    $transaction->rollBack();
}

echo $fail === 0 ? "\nALL PASS\n" : "\n{$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
