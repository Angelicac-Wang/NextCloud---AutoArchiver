<?php

namespace OCA\AutoArchiver\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add is_pinned column to auto_archiver_access table
 * This allows files to be pinned and excluded from archiving
 */
class Version000300Date20250101000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Check if table exists
        if ($schema->hasTable('auto_archiver_access')) {
            $table = $schema->getTable('auto_archiver_access');
            
            // Add is_pinned column if it doesn't exist
            if (!$table->hasColumn('is_pinned')) {
                $table->addColumn('is_pinned', 'smallint', [
                    'notnull' => true,
                    'default' => 0,
                    'length' => 1,
                    'comment' => 'Whether the file is pinned (1) or not (0). Pinned files are excluded from archiving.',
                ]);
                
                $output->info('Added is_pinned column to auto_archiver_access table');
            }
        }

        return $schema;
    }
}

