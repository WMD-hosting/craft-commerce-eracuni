<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Controller;
use wmd\commerceeracuni\models\Document;
use wmd\commerceeracuni\Plugin;
use wmd\commerceeracuni\records\DocumentRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class DocumentsController extends Controller
{
    public function beforeAction($action): bool
    {
        // Craft's own checks (CSRF, CP request, action enabled) run first; only then ours.
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission(Plugin::PERM_MANAGE);
        return true;
    }

    public function actionIndex(): Response
    {
        $status = Craft::$app->getRequest()->getQueryParam('status');
        $query = DocumentRecord::find()->orderBy(['dateUpdated' => SORT_DESC])->limit(200);
        if ($status) {
            $query->andWhere(['status' => $status]);
        }
        $rows = array_map(static fn(DocumentRecord $r) => Document::fromRecord($r), $query->all());
        return $this->renderTemplate('commerce-eracuni/documents/index', ['rows' => $rows, 'status' => $status]);
    }

    /** Order panel (JSON) and plain POST. */
    public function actionSend(): ?Response
    {
        $this->requirePostRequest();
        $order = $this->order((int) Craft::$app->getRequest()->getRequiredBodyParam('orderId'));
        [$ok, $verifiedAttempts] = $this->mayResend((int) $order->id);
        if (!$ok) {
            return $this->fail(Craft::t('commerce-eracuni', 'Check in e-računi first: the last send may already have created this invoice.'));
        }
        Plugin::getInstance()->documents->queue($order, true, $verifiedAttempts);
        return $this->succeed(Craft::t('commerce-eracuni', 'Queued for e-računi.'));
    }

    /** Documents index. */
    public function actionRetry(): ?Response
    {
        $this->requirePostRequest();
        $r = DocumentRecord::findOne(['id' => (int) Craft::$app->getRequest()->getRequiredBodyParam('id')]);
        if (!$r) {
            throw new NotFoundHttpException();
        }
        [$ok, $verifiedAttempts] = $this->mayResend((int) $r->orderId);
        if (!$ok) {
            return $this->fail(Craft::t('commerce-eracuni', 'Check in e-računi first: the last send may already have created this invoice.'));
        }
        Plugin::getInstance()->documents->queue((int) $r->orderId, true, $verifiedAttempts);
        return $this->succeed(Craft::t('commerce-eracuni', 'Queued for retry.'));
    }

    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $order = $this->order((int) Craft::$app->getRequest()->getRequiredBodyParam('orderId'));
        try {
            return $this->asJson(['success' => true] + Plugin::getInstance()->documents->preview($order));
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionPdf(): Response
    {
        $r = DocumentRecord::findOne(['id' => (int) Craft::$app->getRequest()->getRequiredQueryParam('id')]);
        if (!$r || !$r->pdfPath || !is_file($r->pdfPath)) {
            throw new NotFoundHttpException('PDF not available.');
        }
        // Defense in depth: only Documents writes pdfPath, but never serve a
        // file outside the plugin's own storage directory.
        $storageDir = realpath(Craft::getAlias('@storage/commerce-eracuni'));
        $realPath = realpath($r->pdfPath);
        if ($storageDir === false || $realPath === false || !str_starts_with($realPath, $storageDir . DIRECTORY_SEPARATOR)) {
            throw new NotFoundHttpException('PDF not available.');
        }
        return Craft::$app->getResponse()->sendFile($r->pdfPath, basename($r->pdfPath), ['mimeType' => 'application/pdf', 'inline' => true]);
    }

    /**
     * An order whose last create has an unknown outcome is sent again only when staff confirm they
     * checked e-računi and found no invoice. The confirmation names the attempt they looked at, so
     * it cannot carry over to a later attempt; it is logged with the user.
     *
     * @return array{0:bool, 1:int|null} whether to send, and the verified attempt to pass on
     */
    private function mayResend(int $orderId): array
    {
        $doc = Plugin::getInstance()->documents->getForOrder($orderId);
        if (!$doc?->outcomeUnknown) {
            return [true, null];
        }
        $posted = Craft::$app->getRequest()->getBodyParam('verifiedAttempts');
        $attempts = $doc->attempts;
        if (!$doc->canVerifyNotBooked || $posted === null || $posted === '' || (int) $posted !== $attempts) {
            return [false, null];
        }
        $user = Craft::$app->getUser()->getIdentity();
        Plugin::warning("Order {$orderId}: {$user?->username} (#{$user?->id}) verified in e-računi that attempt {$attempts} was not booked.");
        return [true, $attempts];
    }

    private function succeed(string $message): ?Response
    {
        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asSuccess($message);
        }
        Craft::$app->getSession()->setNotice($message);
        return $this->redirectToPostedUrl();
    }

    private function fail(string $message): ?Response
    {
        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asFailure($message);
        }
        Craft::$app->getSession()->setError($message);
        return $this->redirectToPostedUrl();
    }

    private function order(int $id): Order
    {
        $order = Order::find()->id($id)->status(null)->one();
        if (!$order instanceof Order) {
            throw new NotFoundHttpException('Order not found.');
        }
        return $order;
    }
}
