<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

/**
 * Records the payment of a paid order on its invoice.
 *
 * e-računi answers a repeated apiTransactionId with its first result and records nothing again.
 * An invoice gets one payment record, so its document id is the transaction id: a send that
 * resumes after the record went out (a lost answer, a worker that died before saving) cannot
 * record the payment twice.
 */
final class PaymentRecorder
{
    private const METHOD = 'SalesInvoicePaymentRecordAdd';

    public function __construct(private ClientInterface $client)
    {
    }

    /** @throws EracuniException unless e-računi answered ok */
    public function record(string $documentId, string $amount, string $currency, string $paymentDate, string $paymentMethodForInvoice, string $description): void
    {
        $res = $this->client->call(self::METHOD, [
            'apiTransactionId' => 'payment:' . $documentId,
            'documentID' => $documentId,
            'paymentAmount' => $amount,
            'paymentCurrency' => $currency,
            'paymentDate' => $paymentDate,
            'paymentMethodForInvoice' => $paymentMethodForInvoice,
            'description' => $description,
        ]);
        if (($res['response']['status'] ?? '') !== 'ok') {
            throw EracuniException::domain(self::METHOD . ' failed: ' . ($res['response']['description'] ?? json_encode($res)));
        }
    }
}
