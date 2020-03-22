<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%document_rel}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%document}}`
 * - `{{%document_rel_type}}`
 */
class m202001_000002_create_document_rel_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            // http://stackoverflow.com/questions/766809/whats-the-difference-between-utf8-general-ci-and-utf8-unicode-ci
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable('{{%document_rel}}', [
            'id' => $this->primaryKey(),
            'document_id' => $this->integer()->notNull(),
            'rel_id' => $this->integer()->notNull(),
            'rel_type' => $this->string(64)->notNull(),
            'rel_type_tag' => $this->string(64),
            'rank' => $this->integer()->notNull()->defaultValue(0),
            'created_at' => $this->integer(),
            'created_by' => $this->integer(),
            'updated_at' => $this->integer(),
            'updated_by' => $this->integer(),
        ], $tableOptions);

        // creates index for column `document_id`
        $this->createIndex(
            '{{%idx-document_rel-document_id}}',
            '{{%document_rel}}',
            'document_id'
        );

        // add foreign key for table `{{%document}}`
        $this->addForeignKey(
            '{{%fk-document_rel-document_id}}',
            '{{%document_rel}}',
            'document_id',
            '{{%document}}',
            'id',
            'CASCADE'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        // drops foreign key for table `{{%document}}`
        $this->dropForeignKey(
            '{{%fk-document_rel-document_id}}',
            '{{%document_rel}}'
        );

        // drops index for column `document_id`
        $this->dropIndex(
            '{{%idx-document_rel-document_id}}',
            '{{%document_rel}}'
        );

        $this->dropTable('{{%document_rel}}');
    }
}
