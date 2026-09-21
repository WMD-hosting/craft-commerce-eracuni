<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\migrations;

use craft\db\Migration;

/**
 * Adds `paymentRecorded` so a resumed send cannot post a second payment record
 * against an invoice that was already settled on an earlier attempt.
 */
class m260921_130000_add_payment_recorded extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Install::TABLE, 'paymentRecorded')) {
            $this->addColumn(Install::TABLE, 'paymentRecorded', $this->boolean()->notNull()->defaultValue(false)->after('fiscalised'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(Install::TABLE, 'paymentRecorded')) {
            $this->dropColumn(Install::TABLE, 'paymentRecorded');
        }
        return true;
    }
}
