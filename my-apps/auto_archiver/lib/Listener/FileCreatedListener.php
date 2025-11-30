<?php

namespace OCA\AutoArchiver\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCP\Files\File;

/**
 * Listener for file creation events
 * This ensures that all uploaded files are tracked in the database,
 * regardless of whether they are accessed or not
 */
class FileCreatedListener implements IEventListener {

    protected $db;
    protected $logger;

    public function __construct(IDBConnection $db, LoggerInterface $logger) {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function handle(Event $event): void {
        
        if (!($event instanceof NodeCreatedEvent)) {
            return;
        }

        $node = $event->getNode();
        
        // Only process files, skip folders
        if (!($node instanceof File)) {
            return;
        }

        $fileId = $node->getId();
        $path = $node->getPath();
        
        // Skip placeholder files (.ncarchive)
        if (strpos($path, '.ncarchive') !== false) {
            return;
        }

        // Skip files in Archive folder
        if (strpos($path, '/Archive/') !== false || strpos($path, 'Archive/') !== false) {
            return;
        }

        // Skip files in trashbin
        if (strpos($path, 'trashbin') !== false) {
            return;
        }

        // 使用 warning 級別以確保日誌可見
        $msg = "\n" .
               "╔═══════════════════════════════════════════════════════════════╗\n" .
               "║  📤 [AutoArchiver] FILE UPLOAD DETECTED                       ║\n" .
               "╠═══════════════════════════════════════════════════════════════╣\n" .
               "║  📂 File ID : " . str_pad($fileId, 45) . " ║\n" .
               "║  📍 Path    : " . str_pad(substr($path, 0, 45), 45) . " ║\n" .
               "╚═══════════════════════════════════════════════════════════════╝";
        
        $this->logger->warning($msg);

        try {
            $this->upsertAccessTime($fileId, time());
            $this->logger->warning("[AutoArchiver] ✅ Access record created for file ID {$fileId}");
        } catch (\Exception $e) {
            $this->logger->error('[AutoArchiver] ❌ Failed to track created file', [
                'file_id' => $fileId,
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Insert or update access time for a file
     * Preserves is_pinned status if record already exists
     */
    private function upsertAccessTime($fileId, $time) {
        // Check if record exists
        $check = $this->db->getQueryBuilder();
        $check->select('id', 'is_pinned')
              ->from('auto_archiver_access')
              ->where($check->expr()->eq('file_id', $check->createNamedParameter($fileId)));
        $checkResult = $check->executeQuery();
        $existing = $checkResult->fetch();
        $checkResult->closeCursor();

        if ($existing) {
            // Record exists, update last_accessed but preserve is_pinned
            $qb = $this->db->getQueryBuilder();
            $qb->update('auto_archiver_access')
               ->set('last_accessed', $qb->createNamedParameter($time))
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
            $qb->execute();
        } else {
            // Insert new record with default is_pinned = 0
            $qbInsert = $this->db->getQueryBuilder();
            $qbInsert->insert('auto_archiver_access')
                     ->setValue('file_id', $qbInsert->createNamedParameter($fileId))
                     ->setValue('last_accessed', $qbInsert->createNamedParameter($time))
                     ->setValue('is_pinned', $qbInsert->createNamedParameter(0));
            $qbInsert->execute();
        }
    }
}

