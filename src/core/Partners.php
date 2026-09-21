<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\core;

/** Port of mojwmd eracuni_rest_partner_get_or_create_by_oib(). */
final class Partners
{
    public function __construct(private ClientInterface $client, private string $codePrefix = 'WEB')
    {
    }

    public static function partnerCode(Buyer $b, VatTreatment $t, string $customerKey, string $prefix, bool $isB2G): string
    {
        if ($t->isBusiness && $t->taxIdValue !== null) {
            return ($isB2G ? 'B2G-' : 'B2B-') . $t->taxIdValue;
        }
        return $prefix . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $customerKey);
    }

    public function getOrCreate(Buyer $b, VatTreatment $t, string $customerKey, bool $isB2G = false): PartnerRef
    {
        $code = self::partnerCode($b, $t, $customerKey, $this->codePrefix, $isB2G);

        $found = $this->client->get('PartnerList', ['partnerCode' => $code]);
        $row = $found['response']['result'][0] ?? null;
        if (is_array($row) && !empty($row['documentID'])) {
            $existingCode = (string) ($row['BuyerData']['buyerCode'] ?? $row['buyerData']['buyerCode'] ?? $code);
            return new PartnerRef((string) $row['documentID'], $existingCode !== '' ? $existingCode : $code, false);
        }

        $first = trim((string) $b->firstName);
        $last = trim((string) $b->lastName);
        $payload = [
            'partnerCode' => $code,
            'firstName' => $first !== '' ? $first : ($last !== '' ? $last : ($b->isBusiness() ? (string) $b->organization : 'Kupac')),
            'lastName' => $last !== '' ? $last : 'Kupac',
            'eMail' => (string) $b->email,
            'Addresses' => [[
                'type' => 'Primary',
                'street' => trim((string) $b->street) !== '' ? $b->street : '-',
                'city' => (string) $b->city,
                'postalCode' => (string) $b->postalCode,
                'country' => strtoupper($b->countryCode),
                'telephone' => (string) $b->phone,
            ]],
            'BuyerData' => ['buyerCode' => $code],
        ];

        if ($t->isBusiness) {
            $payload['companyName'] = (string) $b->organization;
            $payload['companyType'] = 'Ltd';
            $payload['vatRegistration'] = $t->taxIdField !== null ? 'Registered' : 'None';
            if ($t->taxIdField !== null) {
                $payload[$t->taxIdField] = $t->taxIdValue;
            }
        } else {
            $payload['vatRegistration'] = 'None';
            if ($b->isBusiness()) {
                // Foreign company without a usable id is billed as B2C but keeps its name (mojwmd rule).
                $payload['companyName'] = (string) $b->organization;
            }
        }

        $res = $this->client->call('PartnerCreate', ['partner' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        $status = $res['response']['status'] ?? 'error';
        if ($status !== 'ok') {
            throw EracuniException::domain('PartnerCreate failed: ' . ($res['response']['description'] ?? json_encode($res)));
        }
        $id = $res['response']['result']['documentID'] ?? null;
        if (!$id && $t->taxIdField !== null) {
            $again = $this->client->get('PartnerList', [$t->taxIdField => $t->taxIdValue]);
            $id = $again['response']['result'][0]['documentID'] ?? null;
        }
        if (!$id) {
            throw EracuniException::domain('PartnerCreate returned no documentID.');
        }
        return new PartnerRef((string) $id, $code, true);
    }
}
