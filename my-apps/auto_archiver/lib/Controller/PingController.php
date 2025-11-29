<?php

namespace OCA\AutoArchiver\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IDBConnection;

class PingController extends Controller {

    private $db;

    public function __construct($AppName, IRequest $request, IDBConnection $db) {
        parent::__construct($AppName, $request);
        $this->db = $db;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function touch(int $fileId) {
        // 這是前端戳過來時會執行的函式
        $time = time();

        // 直接執行資料庫更新 (跟 Listener 邏輯一樣)
        $this->upsertAccessTime($fileId, $time);

        return new DataResponse(['status' => 'success', 'fileId' => $fileId]);
    }

    private function upsertAccessTime($fileId, $time) {
        // Update existing record's last_accessed time
        // This preserves is_pinned status if the record already exists
        $qb = $this->db->getQueryBuilder();
        $qb->update('auto_archiver_access')
           ->set('last_accessed', $qb->createNamedParameter($time))
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
        $result = $qb->execute();

        // If no record was updated, check if it exists and insert if needed
        if ($result === 0) {
            $check = $this->db->getQueryBuilder();
            $check->select('id', 'is_pinned')
                  ->from('auto_archiver_access')
                  ->where($check->expr()->eq('file_id', $check->createNamedParameter($fileId)));
            $existing = $check->executeQuery()->fetch();
            $check->closeCursor();

            if (!$existing) {
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
}