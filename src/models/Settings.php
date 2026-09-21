<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\models;

use craft\base\Model;

class Settings extends Model
{
    public string $apiUrl = 'https://e-racuni.com/H7i/API-CLI';
    public string $username = '';
    public string $authToken = '';

    public function rules(): array
    {
        return [
            [['apiUrl', 'username', 'authToken'], 'string'],
        ];
    }
}
