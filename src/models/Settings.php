<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\models;

use craft\base\Model;

class Settings extends Model
{
    public string $apiUrl = 'https://e-racuni.com/H7i/API-CLI';
    public string $username = '';
    public string $authToken = '';
    public string $kpdFieldHandle = 'kpd';
    /** @var array<string, string> product type handle → KPD */
    public array $kpdDefaults = [];
    public string $phoneFieldHandle = '';
    public string $sellerCountry = 'HR';

    public bool $autoSend = true;
    /** @var string[] order status handles that trigger auto-send */
    public array $triggerStatuses = ['confirmed'];
    public bool $requirePaid = false;
    public string $businessUnit = '';
    public string $cashRegisterCode = '1';
    public int $dueDays = 8;
    public string $invoiceDateSource = 'now';
    /** @var array<string, array{method:string, fiscalised?:bool, paymentMethodForInvoice?:string}> gateway handle → mapping */
    public array $paymentMap = [];
    public string $knownRatesCsv = '25,13,5,0';
    public string $shippingKpd = '532000';
    public string $defaultKpd = '';
    public string $partnerCodePrefix = 'WEB';
    public bool $syncPayments = true;
    public bool $deliverAs4 = true;
    public bool $deliverFina = false;
    /** @var string[] OIBs treated as B2G (public sector) buyers */
    public array $b2gTaxIds = [];

    public function rules(): array
    {
        return [
            [['apiUrl', 'username', 'authToken'], 'string'],
            [['kpdFieldHandle', 'phoneFieldHandle'], 'string'],
            [['kpdDefaults'], 'safe'],
            [['sellerCountry', 'businessUnit', 'cashRegisterCode', 'invoiceDateSource', 'knownRatesCsv', 'shippingKpd', 'defaultKpd', 'partnerCodePrefix'], 'string'],
            [['dueDays'], 'integer'],
            [['autoSend', 'requirePaid', 'syncPayments', 'deliverAs4', 'deliverFina'], 'boolean'],
            [['triggerStatuses', 'paymentMap', 'b2gTaxIds'], 'safe'],
        ];
    }

    /**
     * Known Croatian VAT rates, used to snap computed line rates to a canonical value.
     *
     * @return float[]
     */
    public function knownRates(): array
    {
        return array_values(array_filter(array_map('floatval', explode(',', $this->knownRatesCsv)), fn($v) => $v >= 0));
    }
}
