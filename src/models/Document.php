<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\models;

use craft\base\Model;
use craft\helpers\UrlHelper;
use wmd\commerceeracuni\records\DocumentRecord;

class Document extends Model
{
    public ?int $id = null;
    public int $orderId = 0;
    public string $kind = DocumentRecord::KIND_INVOICE;
    public string $status = DocumentRecord::STATUS_PENDING;
    public ?string $documentId = null;
    public ?string $number = null;
    public ?string $pdfPath = null;
    public ?string $treatment = null;
    public bool $fiscalised = false;
    public ?string $method = null;
    public ?string $deliveryChannel = null;
    public ?string $deliveryTxnId = null;
    public ?string $deliveryStatus = null;
    public ?string $deliveryBucket = null;
    public ?string $error = null;
    public int $attempts = 0;
    public ?\DateTime $dateUpdated = null;

    public static function fromRecord(DocumentRecord $r): self
    {
        $m = new self();
        foreach (['id', 'orderId', 'kind', 'status', 'documentId', 'number', 'pdfPath', 'treatment', 'method', 'deliveryChannel', 'deliveryTxnId', 'deliveryStatus', 'deliveryBucket', 'error', 'attempts'] as $k) {
            $m->$k = $r->$k;
        }
        $m->fiscalised = (bool) $r->fiscalised;
        $m->dateUpdated = $r->dateUpdated ? new \DateTime($r->dateUpdated) : null;
        return $m;
    }

    public function isSent(): bool
    {
        return $this->status === DocumentRecord::STATUS_SENT;
    }

    /**
     * Control-panel action URL for the PDF, gated by the plugin's manage-documents permission.
     * It is not a signed or tokenised URL: the PDF never lives under the web root, and the action
     * only answers a logged-in CP user who holds that permission.
     */
    public function getPdfUrl(): ?string
    {
        if (!$this->pdfPath || !$this->id) {
            return null;
        }
        return UrlHelper::actionUrl('commerce-eracuni/documents/pdf', ['id' => $this->id]);
    }
}
