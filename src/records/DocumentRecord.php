<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $orderId
 * @property string $kind
 * @property string $status
 * @property string|null $documentId
 * @property string|null $number
 * @property string|null $pdfPath
 * @property string|null $treatment
 * @property bool $fiscalised
 * @property string|null $method
 * @property string|null $deliveryChannel
 * @property string|null $deliveryTxnId
 * @property string|null $deliveryStatus
 * @property string|null $deliveryBucket
 * @property string|null $payload
 * @property string|null $response
 * @property string|null $error
 * @property int $attempts
 */
class DocumentRecord extends ActiveRecord
{
    public const KIND_INVOICE = 'invoice';
    public const KIND_CREDIT = 'credit';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public static function tableName(): string
    {
        return '{{%commerce_eracuni_documents}}';
    }
}
