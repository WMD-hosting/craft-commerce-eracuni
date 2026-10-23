<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\migrations;

use craft\db\Migration;
use craft\helpers\Db;
use wmd\commerceeracuni\jobs\SendInvoiceJob;

/**
 * Adds `outcomeUnknown` so a create that may have been booked in e-računi is never sent again
 * until staff verify it was not. Rows that failed that way before 1.0.2 carry only the error text,
 * so they are flagged from it. 1.0.1 also re-sent such rows on the next order save, and a later
 * failure could overwrite that text, so a failed row with no documentID that was tried more than
 * once is flagged too, as is a pending row with no documentID left past the job's TTR by a worker
 * that died: a needless check in e-računi costs less than a second fiscal invoice.
 */
class m261023_000000_add_outcome_unknown extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Install::TABLE, 'outcomeUnknown')) {
            $this->addColumn(Install::TABLE, 'outcomeUnknown', $this->boolean()->notNull()->defaultValue(false)->after('paymentRecorded'));
        }
        $this->update(Install::TABLE, ['outcomeUnknown' => true], [
            'and',
            ['status' => 'failed'],
            ['or', ['like', 'error', 'outcome unknown'], ['and', ['>', 'attempts', 1], ['or', ['documentId' => null], ['documentId' => '']]]],
        ], [], false);
        $this->update(Install::TABLE, ['outcomeUnknown' => true], [
            'and',
            ['status' => 'pending'],
            ['or', ['documentId' => null], ['documentId' => '']],
            ['<', 'dateUpdated', Db::prepareDateForDb(new \DateTime('-' . SendInvoiceJob::TTR . ' seconds'))],
        ], [], false);
        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(Install::TABLE, 'outcomeUnknown')) {
            $this->dropColumn(Install::TABLE, 'outcomeUnknown');
        }
        return true;
    }
}
