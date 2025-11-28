<?php
namespace OCA\AutoArchiver\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 创建通知决策记录表
 */
class Version000200Date20251127000000 extends SimpleMigrationStep {
    
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // 创建通知决策记录表
        if (!$schema->hasTable('archiver_decisions')) {
            $table = $schema->createTable('archiver_decisions');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('file_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('decision', 'string', [
                'notnull' => true,
                'length' => 32,
                'comment' => 'extend, archive, ignore',
            ]);
            $table->addColumn('notified_at', 'bigint', [
                'notnull' => true,
                'comment' => 'When notification was sent',
            ]);
            $table->addColumn('decided_at', 'bigint', [
                'notnull' => true,
                'comment' => 'When user made decision',
            ]);
            $table->addColumn('file_path', 'string', [
                'notnull' => false,
                'length' => 4000,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['file_id'], 'arc_dec_fid');
            $table->addIndex(['user_id'], 'arc_dec_uid');
            $table->addIndex(['decided_at'], 'arc_dec_dat');
        }

        return $schema;
    }
}

