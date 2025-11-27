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
        // 複製之前 Listener 寫過的那個 upsert 邏輯放這裡
        // 為了簡潔，這裡只寫簡單版，建議把這段邏輯抽成 Service 比較乾淨
        // 但直接寫在這裡也會動：
        $qb = $this->db->getQueryBuilder();
        $qb->update('auto_archiver_access')
           ->set('last_accessed', $qb->createNamedParameter($time))
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
        $result = $qb->execute();

        if ($result === 0) {
            // ... (插入邏輯，同之前的 code) ...
             $qbInsert = $this->db->getQueryBuilder();
             $qbInsert->insert('auto_archiver_access')
                      ->setValue('file_id', $qbInsert->createNamedParameter($fileId))
                      ->setValue('last_accessed', $qbInsert->createNamedParameter($time));
             $qbInsert->execute();
        }
    }
}