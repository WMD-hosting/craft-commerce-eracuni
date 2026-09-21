<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

/**
 * Creates one sales invoice, walking the Retail payment-method fallback chain (spec §2.2).
 *
 * A create whose outcome is unknown must never be retried automatically: e-računi may have
 * booked (and fiscalised) the invoice before the connection broke, so a retry would issue a
 * duplicate. Only two transport failures are known to have created nothing — rate-limit
 * exhaustion (HTTP 429, the request never reached the booking step) and session-lock
 * exhaustion (the concurrent-request guard rejected it) — and those stay retryable. Every
 * other transport failure is converted to a domain error that stops the job and asks an
 * operator to verify in e-računi first.
 */
final class InvoiceCreator
{
    private const METHOD = 'SalesInvoiceCreate';

    public function __construct(private ClientInterface $client)
    {
    }

    /**
     * @param array $payload the SalesInvoice payload as built by InvoiceBuilder
     * @param bool $isRetail whether the treatment produces a Retail (fiscalised B2C) invoice
     * @param string $method the methodOfPayment already set on $payload
     * @throws EracuniException
     */
    public function create(array $payload, bool $isRetail, string $method): CreateResult
    {
        $fiscalised = PaymentMethodMap::entry($method)['fiscalised'];
        $res = $this->call($payload);

        if ($isRetail && ($res['response']['status'] ?? '') === 'error' && self::isMethodRejection($res)) {
            foreach (PaymentMethodMap::retailFallbacks($method) as $fallback) {
                $payload['methodOfPayment'] = $fallback;
                $res = $this->call($payload);
                if (($res['response']['status'] ?? '') === 'ok') {
                    $method = $fallback;
                    $fiscalised = PaymentMethodMap::entry($fallback)['fiscalised'];
                    break;
                }
                if (!self::isMethodRejection($res)) {
                    break;
                }
            }
        }

        if (($res['response']['status'] ?? '') !== 'ok') {
            throw EracuniException::domain('SalesInvoiceCreate failed: ' . ($res['response']['description'] ?? json_encode($res)));
        }
        $documentId = (string) ($res['response']['result']['documentID'] ?? '');
        if ($documentId === '') {
            throw EracuniException::domain('SalesInvoiceCreate returned no documentID.');
        }

        return new CreateResult($payload, $res, $method, $fiscalised, $documentId, (string) ($res['response']['result']['number'] ?? ''));
    }

    /** @throws EracuniException */
    private function call(array $payload): array
    {
        try {
            return $this->client->call(self::METHOD, ['SalesInvoice' => json_encode($payload, JSON_UNESCAPED_UNICODE)], 3, 120);
        } catch (EracuniException $e) {
            if (!$e->isTransport() || $e->httpStatus === 429 || stripos($e->getMessage(), 'session lock') !== false) {
                throw $e;
            }
            throw EracuniException::domain(self::METHOD . ' outcome unknown (' . $e->getMessage() . '). Verify in e-računi before retrying this order.');
        }
    }

    private static function isMethodRejection(array $res): bool
    {
        $d = strtolower((string) ($res['response']['description'] ?? ''));
        return str_contains($d, 'methodofpayment') || str_contains($d, 'not allowed');
    }
}
