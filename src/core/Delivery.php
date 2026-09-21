<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\core;

/** Port of mojwmd eracun_send.php. */
final class Delivery
{
    public const CHANNEL_AS4 = 'as4';
    public const CHANNEL_FINA = 'fina';

    public function __construct(private ClientInterface $client)
    {
    }

    public function sendAs4(string $documentId): DeliveryResult
    {
        $res = $this->client->call('SendDocumentToAS4Endpoint', ['documentID' => $documentId], 1, 40);
        $ok = ($res['response']['status'] ?? '') === 'ok';
        $txn = isset($res['response']['sendingTransactionID']) ? (string) $res['response']['sendingTransactionID'] : null;
        return new DeliveryResult(
            $ok, self::CHANNEL_AS4, $txn,
            $ok ? DeliveryStatus::BUCKET_PENDING : DeliveryStatus::BUCKET_FAILED,
            $ok ? 'documentSent' : ($res['response']['status'] ?? null),
            (string) ($res['response']['description'] ?? ''),
        );
    }

    public function finaReceiverActive(string $oib): ?bool
    {
        $oib = preg_replace('/\D/', '', $oib) ?? '';
        $res = $this->client->call('GetFinaReceiverList', ['companyID' => $oib], 1, 30);
        $list = $res['response']['result']['GetFinaReceiverListMsg'] ?? null;
        if (!is_array($list)) {
            return null;
        }
        foreach ($list as $row) {
            if ((string) ($row['companyID'] ?? '') === $oib) {
                return ($row['status'] ?? '') === 'active';
            }
        }
        return false;
    }

    public function sendFina(string $documentId, string $oib): DeliveryResult
    {
        $party = '9934:' . (preg_replace('/\D/', '', $oib) ?? '');
        $res = $this->client->call('SendDocumentToFina', ['documentID' => $documentId, 'partyIdentificationID' => $party], 1, 45);
        $msg = $res['response']['result']['SendDocumentToFinaMsg'] ?? [];
        $log = $msg['DocumentSendingLog'] ?? [];
        $ok = ($res['response']['status'] ?? '') === 'ok' && !empty($log);
        $cls = DeliveryStatus::classify(['status' => $log['StatusCode'] ?? ($ok ? 'documentSent' : 'error'), 'description' => $msg['MsgText'] ?? ($res['response']['description'] ?? '')]);
        return new DeliveryResult($ok, self::CHANNEL_FINA, isset($log['SendingTransactionID']) ? (string) $log['SendingTransactionID'] : null, $ok ? $cls['bucket'] : DeliveryStatus::BUCKET_FAILED, $cls['status'], $cls['message']);
    }

    /** @return array{bucket:string, terminal:bool, status:?string, message:string} */
    public function status(string $sendingTransactionId): array
    {
        $res = $this->client->call('GetDocumentSendingStatus', ['sendingTransactionID' => $sendingTransactionId], 1, 8);
        $raw = $res['response']['result'] ?? $res['response'] ?? $res;
        return DeliveryStatus::classify(is_array($raw) ? $raw : []);
    }
}
