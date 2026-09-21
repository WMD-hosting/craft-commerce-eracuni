<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testSettingsModelAutoloads(): void
    {
        self::assertTrue(class_exists(\wmd\commerceeracuni\models\Settings::class));
    }
}
