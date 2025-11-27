<?php

namespace OCA\AutoArchiver\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCP\IRequest; // <--- 新增這個：用來抓取現在的 HTTP 請求資訊

class FileReadListener implements IEventListener {

    protected $db;
    protected $logger;
    protected $request; // <--- 新增屬性

    // 注入 IRequest
    public function __construct(IDBConnection $db, LoggerInterface $logger, IRequest $request) {
        $this->db = $db;
        $this->logger = $logger;
        $this->request = $request;
    }

    public function handle(Event $event): void {
        
        if (!($event instanceof BeforeNodeReadEvent)) {
            return;
        }

        $method = $this->request->getMethod();
        $requestUri = $this->request->getRequestUri();

        if ($method !== 'GET') { return; }
        
        // 過濾預覽
        if (strpos($requestUri, '/preview') !== false || 
            strpos($requestUri, '/thumbnail') !== false ||
            strpos($requestUri, '/avatar') !== false) {
            return;
        }

        $node = $event->getNode();
        $fileId = $node->getId();
        $path = $node->getPath();

        // ==========================================
        //  🎨 美化 Log 輸出
        // ==========================================
        $msg = "\n" .
               "╔═══════════════════════════════════════════════════════════════╗\n" .
               "║  🕵️  [AutoArchiver] REAL ACCESS DETECTED                      ║\n" .
               "╠═══════════════════════════════════════════════════════════════╣\n" .
               "║  📂 File ID : " . str_pad($fileId, 45) . " ║\n" .
               "║  📍 Path    : " . str_pad(substr($path, 0, 45), 45) . " ║\n" .
               "║  🔗 Method  : " . str_pad($method, 45) . " ║\n" .
               "╚═══════════════════════════════════════════════════════════════╝";

        // 注意：這裡改成 info 等級，比較乾淨
        $this->logger->warning($msg);

        try {
            $this->upsertAccessTime($fileId, time());
        } catch (\Exception $e) {
            $this->logger->error("\n❌ [AutoArchiver] DB Error:\n" . $e->getMessage());
        }
    }

    private function upsertAccessTime($fileId, $time) {
        // (這部分資料庫邏輯不用變，維持原樣)
        $qb = $this->db->getQueryBuilder();
        $qb->update('auto_archiver_access')
           ->set('last_accessed', $qb->createNamedParameter($time))
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
        $result = $qb->execute();

        if ($result === 0) {
            $check = $this->db->getQueryBuilder();
            $check->select('id')
                  ->from('auto_archiver_access')
                  ->where($check->expr()->eq('file_id', $check->createNamedParameter($fileId)));
            $exists = $check->executeQuery()->fetch();

            if (!$exists) {
                $qbInsert = $this->db->getQueryBuilder();
                $qbInsert->insert('auto_archiver_access')
                         ->setValue('file_id', $qbInsert->createNamedParameter($fileId))
                         ->setValue('last_accessed', $qbInsert->createNamedParameter($time));
                $qbInsert->execute();
            }
        }
    }
}