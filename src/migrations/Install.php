<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public const TABLE = '{{%commerce_eracuni_documents}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'kind' => $this->string(16)->notNull()->defaultValue('invoice'),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'documentId' => $this->string(64)->null(),
            'number' => $this->string(64)->null(),
            'pdfPath' => $this->string(255)->null(),
            'treatment' => $this->string(32)->null(),
            'fiscalised' => $this->boolean()->notNull()->defaultValue(false),
            'method' => $this->string(32)->null(),
            'deliveryChannel' => $this->string(8)->null(),
            'deliveryTxnId' => $this->string(64)->null(),
            'deliveryStatus' => $this->string(64)->null(),
            'deliveryBucket' => $this->string(16)->null(),
            'payload' => $this->mediumText()->null(),
            'response' => $this->mediumText()->null(),
            'error' => $this->text()->null(),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, self::TABLE, ['orderId', 'kind'], true);
        $this->createIndex(null, self::TABLE, ['status']);
        $this->createIndex(null, self::TABLE, ['deliveryBucket']);
        $this->addForeignKey(null, self::TABLE, ['orderId'], '{{%commerce_orders}}', ['id'], 'CASCADE');
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        return true;
    }
}
