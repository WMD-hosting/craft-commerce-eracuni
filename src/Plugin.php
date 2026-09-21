<?php
declare(strict_types=1);

namespace wmd\commerceeracuni;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use verbb\base\helpers\Plugin as VerbbPlugin;
use verbb\base\LogTrait;
use wmd\commerceeracuni\models\Settings;

/**
 * e-Računi for Commerce.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read services\Documents $documents
 * @property-read services\OrderSnapshotFactory $snapshots
 */
class Plugin extends BasePlugin
{
    use LogTrait;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();
        VerbbPlugin::bootstrapPlugin('commerce-eracuni');
    }

    public static function config(): array
    {
        return [
            'components' => [
                'documents' => \wmd\commerceeracuni\services\Documents::class,
                'snapshots' => \wmd\commerceeracuni\services\OrderSnapshotFactory::class,
            ],
        ];
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }
}
