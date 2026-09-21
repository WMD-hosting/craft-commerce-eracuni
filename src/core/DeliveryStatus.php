<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

/**
 * Ported from mojwmd eracun_lib.php.
 *
 * Any status not listed in DELIVERED or FAILED falls through to BUCKET_PENDING below,
 * which covers 'documentSendingDraft', 'documentApprovedForSending', 'documentSent' and
 * any unrecognised status.
 */
final class DeliveryStatus
{
    public const BUCKET_DELIVERED = 'delivered';
    public const BUCKET_PENDING = 'pending';
    public const BUCKET_FAILED = 'failed';
    public const BUCKET_ERROR = 'error';

    private const DELIVERED = ['documentDeliveryConfirmed', 'documentReceivalConfirmed', 'documentPaymentFulfilled', 'documentPaymentFulfilledPartially'];
    private const FAILED = ['documentDeliveryFailed', 'documentRejectedByReceiver', 'documentRejectedByGateway', 'documentSendingCancelledBySender'];

    /** @return array{bucket:string, terminal:bool, status:?string, message:string} */
    public static function classify(array $raw): array
    {
        $status = isset($raw['status']) ? (string) $raw['status'] : null;
        $message = (string) ($raw['sendingResultMessage'] ?? $raw['description'] ?? $raw['message'] ?? '');

        if ($status === 'error') {
            return ['bucket' => self::BUCKET_ERROR, 'terminal' => false, 'status' => $status, 'message' => $message];
        }
        if (in_array($status, self::DELIVERED, true)) {
            return ['bucket' => self::BUCKET_DELIVERED, 'terminal' => true, 'status' => $status, 'message' => $message];
        }
        if (in_array($status, self::FAILED, true)) {
            return ['bucket' => self::BUCKET_FAILED, 'terminal' => true, 'status' => $status, 'message' => $message];
        }
        return ['bucket' => self::BUCKET_PENDING, 'terminal' => false, 'status' => $status, 'message' => $message];
    }
}
