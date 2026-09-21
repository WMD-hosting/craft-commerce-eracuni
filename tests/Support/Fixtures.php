<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\Support;

final class Fixtures
{
    public static function invoice(string $name): array
    {
        return self::load("invoices/$name.json");
    }

    public static function partner(string $name): array
    {
        return self::load("partners/$name.json");
    }

    private static function load(string $rel): array
    {
        $path = dirname(__DIR__) . '/fixtures/' . $rel;
        if (!is_file($path)) {
            throw new \RuntimeException("Missing fixture $rel");
        }
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
