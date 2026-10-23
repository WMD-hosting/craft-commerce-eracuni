<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

/**
 * Decides whether a send may take over an existing invoice row. Every path that sends (auto-send,
 * the order panel, CP and console retries, the queue job) goes through Documents::claim(), so
 * this is the one place that keeps a booked invoice from being created twice.
 */
final class ClaimPolicy
{
    /**
     * @param string $status the row's status (pending, sent, failed)
     * @param bool $outcomeUnknown the last create may have been booked in e-računi
     * @param bool $stale a pending row older than the job's TTR, presumed abandoned
     * @param bool $force take over a pending row another caller may still own
     * @param bool $verifiedNotBooked staff checked e-računi and found no invoice for this attempt
     */
    public static function mayTakeOver(string $status, bool $outcomeUnknown, bool $stale, bool $force, bool $verifiedNotBooked): bool
    {
        if ($status === 'sent') {
            return false;
        }
        if ($outcomeUnknown) {
            // A pending row that is not stale may have a create in flight right now, so nobody
            // can have verified it yet.
            return $verifiedNotBooked && ($status === 'failed' || $stale);
        }
        if ($status === 'pending') {
            return $force || $stale;
        }
        return true;
    }
}
