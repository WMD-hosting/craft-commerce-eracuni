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

    public function rules(): array
    {
        return [
            [['apiUrl', 'username', 'authToken'], 'string'],
            [['kpdFieldHandle', 'phoneFieldHandle'], 'string'],
            [['kpdDefaults'], 'safe'],
        ];
    }

    /**
     * Known Croatian VAT rates, used to snap computed line rates to a canonical value.
     * TODO(Task 15): make configurable; the full settings model lands there.
     *
     * @return float[]
     */
    public function knownRates(): array
    {
        return [25.0, 13.0, 5.0, 0.0];
    }
}
