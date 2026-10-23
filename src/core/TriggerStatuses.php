<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

/**
 * Resolves the trigger status handles from settings to order status ids. Commerce drops a handle
 * it does not know from an `orderStatus()` query and, with none left, applies no status filter at
 * all, so callers must query by these ids and do nothing when the list is empty.
 */
final class TriggerStatuses
{
    /**
     * @param string[] $handles trigger status handles from settings
     * @param array<array{id:int|string, handle:string}> $statuses every order status in every store
     * @return array{ids:int[], unknown:string[]}
     */
    public static function resolve(array $handles, array $statuses): array
    {
        $ids = [];
        $found = [];
        foreach ($statuses as $status) {
            if (in_array($status['handle'], $handles, true)) {
                $ids[] = (int) $status['id'];
                $found[$status['handle']] = true;
            }
        }
        $unknown = array_values(array_filter($handles, static fn($h) => !isset($found[$h])));
        return ['ids' => array_values(array_unique($ids)), 'unknown' => $unknown];
    }
}
