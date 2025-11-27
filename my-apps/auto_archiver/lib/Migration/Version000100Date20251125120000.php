<?php

namespace OCA\AutoArchiver\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000100Date20251125120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // 如果資料表不存在，就建立它
        if (!$schema->hasTable('auto_archiver_access')) {
            $table = $schema->createTable('auto_archiver_access');
            
            // ID 主鍵
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            
            // 對應 Nextcloud 的 file_id
            $table->addColumn('file_id', 'integer', [
                'notnull' => true,
            ]);
            
            // 最後讀取時間 (Unix Timestamp)
            $table->addColumn('last_accessed', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);

            // 設定主鍵與索引
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['file_id'], 'aa_file_id_index'); // 設為 Unique，每個檔案只記一筆
        }

        return $schema;
    }
}