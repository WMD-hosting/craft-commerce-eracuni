<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\models;

use craft\base\Model;

class Settings extends Model
{
    // Connection (env-var capable)
    public string $apiUrl = 'https://e-racuni.com/H7i/API-CLI';
    public string $username = '';
    public string $authToken = '';
    public string $businessUnit = '';
    public string $cashRegisterCode = '1';
    public string $partnerCodePrefix = 'WEB';
    public string $sellerCountry = 'HR';

    // Documents
    public bool $autoSend = true;
    /** @var string[] order status handles */
    public array $triggerStatuses = ['confirmed'];
    public bool $requirePaid = false;
    public string $invoiceDateSource = 'now'; // now | orderDate
    public int $dueDays = 8;
    public bool $syncPayments = true;

    // VAT
    public string $knownRatesCsv = '25,13,5,0';

    // Payments: gateway handle → ['method','fiscalised','paymentMethodForInvoice']
    /** @var array<string, array{method:string, fiscalised?:bool, paymentMethodForInvoice?:string}> */
    public array $paymentMap = [];

    // KPD
    public string $kpdFieldHandle = 'kpd';
    /** @var array<string,string> product type handle → KPD */
    public array $kpdDefaults = [];
    public string $defaultKpd = '';
    public string $shippingKpd = '532000';
    public string $phoneFieldHandle = '';

    // Delivery
    public bool $deliverAs4 = true;
    public bool $deliverFina = false;
    /**
     * OIBs treated as B2G (FINA). The steady-state (post-validation) shape is
     * a plain string list; the settings form posts it as editable-table rows
     * (`[['oib' => '...'], ...]`), which beforeValidate() flattens.
     *
     * @var array<int, string|array{oib?: string}>
     */
    public array $b2gTaxIds = [];

    public function rules(): array
    {
        return [
            [['apiUrl', 'username', 'authToken', 'businessUnit', 'cashRegisterCode', 'partnerCodePrefix', 'sellerCountry', 'invoiceDateSource', 'knownRatesCsv', 'kpdFieldHandle', 'defaultKpd', 'shippingKpd', 'phoneFieldHandle'], 'string'],
            [['apiUrl', 'username', 'authToken', 'businessUnit', 'cashRegisterCode', 'sellerCountry'], 'required'],
            [['sellerCountry'], 'match', 'pattern' => '/^[A-Z]{2}$/'],
            [['cashRegisterCode'], 'match', 'pattern' => '/^\d+$/', 'message' => 'Cash register code must be numeric.'],
            [['invoiceDateSource'], 'in', 'range' => ['now', 'orderDate']],
            [['dueDays'], 'integer', 'min' => 0, 'max' => 365],
            [['autoSend', 'requirePaid', 'syncPayments', 'deliverAs4', 'deliverFina'], 'boolean'],
            [['triggerStatuses', 'paymentMap', 'kpdDefaults', 'b2gTaxIds'], 'safe'],
        ];
    }

    public function beforeValidate(): bool
    {
        // The editable table field posts b2gTaxIds as [['oib' => '...'], ...]; flatten to plain strings first.
        $this->b2gTaxIds = array_map(fn($r) => is_array($r) ? ($r['oib'] ?? '') : $r, (array) $this->b2gTaxIds);
        $this->b2gTaxIds = array_values(array_filter(array_map(fn($v) => preg_replace('/\D/', '', (string) $v), (array) $this->b2gTaxIds)));
        $this->triggerStatuses = array_values(array_filter((array) $this->triggerStatuses));
        $this->kpdDefaults = array_filter(array_map('trim', (array) $this->kpdDefaults));
        return parent::beforeValidate();
    }

    /** @return float[] */
    public function knownRates(): array
    {
        return array_values(array_filter(array_map('floatval', explode(',', $this->knownRatesCsv)), fn($v) => $v >= 0));
    }
}
