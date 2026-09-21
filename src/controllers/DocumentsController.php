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
        $this->requirePermission(Plugin::PERM_MANAGE);
        return parent::beforeAction($action);
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

    public function actionSend(): Response
    {
        $this->requirePostRequest();
        $order = $this->order((int) Craft::$app->getRequest()->getRequiredBodyParam('orderId'));
        Plugin::getInstance()->documents->queue($order, true);
        Craft::$app->getSession()->setNotice(Craft::t('commerce-eracuni', 'Queued for e-računi.'));
        return $this->redirectToPostedUrl();
    }

    public function actionRetry(): Response
    {
        $this->requirePostRequest();
        $r = DocumentRecord::findOne(['id' => (int) Craft::$app->getRequest()->getRequiredBodyParam('id')]);
        if (!$r) {
            throw new NotFoundHttpException();
        }
        Plugin::getInstance()->documents->queue((int) $r->orderId, true);
        Craft::$app->getSession()->setNotice(Craft::t('commerce-eracuni', 'Queued for retry.'));
        return $this->redirectToPostedUrl();
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
        if ($storageDir === false || $realPath === false || !str_starts_with($realPath, $storageDir)) {
            throw new NotFoundHttpException('PDF not available.');
        }
        return Craft::$app->getResponse()->sendFile($r->pdfPath, basename($r->pdfPath), ['mimeType' => 'application/pdf', 'inline' => true]);
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
