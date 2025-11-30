<?php
namespace OCA\AutoArchiver\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * 处理通知相关的操作：延长期限、忽略通知
 */
class NotificationController extends Controller {
    
    private $db;
    private $userSession;
    private $logger;
    
    public function __construct(
        string $appName,
        IRequest $request,
        IDBConnection $db,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->db = $db;
        $this->userSession = $userSession;
        $this->logger = $logger;
    }
    
    /**
     * 延长文件保留期限 7 天
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param int $fileId
     * @return JSONResponse
     */
    public function extend7Days(int $fileId): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'User not authenticated'], 401);
        }
        
        $userId = $user->getUID();
        
        $this->logger->info('[AutoArchiver] User extending file retention by 7 days', [
            'file_id' => $fileId,
            'user_id' => $userId
        ]);
        
        try {
            // 设置 last_accessed 为 16 天前 (23-7=16)
            // 这样文件还有 30-16=14 天才会被封存
            $newAccessTime = time() - (16 * 24 * 3600);
            
            $qb = $this->db->getQueryBuilder();
            $qb->update('auto_archiver_access')
                ->set('last_accessed', $qb->createNamedParameter($newAccessTime))
                ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
            $updated = $qb->execute();
            
            if ($updated === 0) {
                // 如果记录不存在，创建新记录
                $qb = $this->db->getQueryBuilder();
                $qb->insert('auto_archiver_access')
                    ->values([
                        'file_id' => $qb->createNamedParameter($fileId),
                        'last_accessed' => $qb->createNamedParameter($newAccessTime),
                    ]);
                $qb->execute();
            }
            
            // 记录用户决策
            $this->recordDecision($fileId, $userId, 'extend_7days');
            
            // 删除该文件的通知
            $this->deleteNotification($fileId, $userId);
            
            $this->logger->info('[AutoArchiver] File retention extended by 7 days successfully', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'new_access_time' => date('Y-m-d H:i:s', $newAccessTime)
            ]);
            
            return new JSONResponse([
                'success' => true,
                'message' => '文件保留期限已延長 7 天'
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('[AutoArchiver] Failed to extend file retention by 7 days', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return new JSONResponse([
                'error' => '操作失敗：' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 延长文件保留期限（"留宿宫中"）
     * 
     * @NoAdminRequired
     * @param int $fileId
     * @return JSONResponse
     */
    public function extend(int $fileId): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'User not authenticated'], 401);
        }
        
        $userId = $user->getUID();
        
        $this->logger->info('[AutoArchiver] User extending file retention', [
            'file_id' => $fileId,
            'user_id' => $userId
        ]);
        
        try {
            // 更新文件的最后访问时间为当前时间
            $qb = $this->db->getQueryBuilder();
            $qb->update('auto_archiver_access')
                ->set('last_accessed', $qb->createNamedParameter(time()))
                ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
            $updated = $qb->execute();
            
            if ($updated === 0) {
                // 如果记录不存在，创建新记录
                $qb = $this->db->getQueryBuilder();
                $qb->insert('auto_archiver_access')
                    ->values([
                        'file_id' => $qb->createNamedParameter($fileId),
                        'last_accessed' => $qb->createNamedParameter(time()),
                    ]);
                $qb->execute();
            }
            
            // 记录用户决策
            $this->recordDecision($fileId, $userId, 'extend');
            
            $this->logger->info('[AutoArchiver] File retention extended successfully', [
                'file_id' => $fileId,
                'user_id' => $userId
            ]);
            
            return new JSONResponse([
                'success' => true,
                'message' => '文件保留期限已延长30天'
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('[AutoArchiver] Failed to extend file retention', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return new JSONResponse([
                'error' => '操作失败：' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 忽略通知（用户选择不做任何操作）
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param int $fileId
     * @return JSONResponse
     */
    public function dismiss(int $fileId): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'User not authenticated'], 401);
        }
        
        $userId = $user->getUID();
        
        $this->logger->info('[AutoArchiver] User dismissed notification', [
            'file_id' => $fileId,
            'user_id' => $userId
        ]);
        
        try {
            // 记录用户决策
            $this->recordDecision($fileId, $userId, 'ignore');
            
            // 删除该文件的通知
            $this->deleteNotification($fileId, $userId);
            
            return new JSONResponse([
                'success' => true,
                'message' => '已忽略通知'
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('[AutoArchiver] Failed to dismiss notification', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return new JSONResponse([
                'error' => '操作失败：' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 获取用户决策统计
     * 
     * @NoAdminRequired
     * @return JSONResponse
     */
    public function getStatistics(): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'User not authenticated'], 401);
        }
        
        $userId = $user->getUID();
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('decision', $qb->createFunction('COUNT(*) as count'))
                ->from('archiver_decisions')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->neq('decision', $qb->createNamedParameter('pending')))
                ->groupBy('decision');
            
            $result = $qb->execute();
            $stats = $result->fetchAll();
            $result->closeCursor();
            
            $statistics = [
                'extend' => 0,
                'ignore' => 0,
                'archive' => 0,
            ];
            
            foreach ($stats as $stat) {
                $statistics[$stat['decision']] = (int)$stat['count'];
            }
            
            return new JSONResponse([
                'success' => true,
                'statistics' => $statistics
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('[AutoArchiver] Failed to get statistics', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return new JSONResponse([
                'error' => '获取统计数据失败：' . $e->getMessage()
            ], 500);
        }
    }
    
    private function recordDecision(int $fileId, string $userId, string $decision): void {
        // 先更新现有的 pending 记录
        $qb = $this->db->getQueryBuilder();
        $qb->update('archiver_decisions')
            ->set('decision', $qb->createNamedParameter($decision))
            ->set('decided_at', $qb->createNamedParameter(time()))
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('decision', $qb->createNamedParameter('pending')));
        
        $updated = $qb->execute();
        
        // 如果没有 pending 记录，创建新记录
        if ($updated === 0) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archiver_decisions')
                ->values([
                    'file_id' => $qb->createNamedParameter($fileId),
                    'user_id' => $qb->createNamedParameter($userId),
                    'decision' => $qb->createNamedParameter($decision),
                    'notified_at' => $qb->createNamedParameter(time()),
                    'decided_at' => $qb->createNamedParameter(time()),
                    'file_path' => $qb->createNamedParameter(''),
                ]);
            $qb->execute();
        }
    }
    
    private function deleteNotification(int $fileId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('notifications')
            ->where($qb->expr()->eq('app', $qb->createNamedParameter('auto_archiver')))
            ->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('file')))
            ->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$fileId)));
        $qb->execute();
    }
    
    /**
     * 幫我封存（用戶同意自動封存以釋放空間）
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @return JSONResponse
     */
    public function ignoreStorageWarning(): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'User not authenticated'], 401);
        }
        
        $userId = $user->getUID();
        
        $this->logger->info('[AutoArchiver] User chose to archive files for storage', [
            'user_id' => $userId
        ]);
        
        try {
            // 記錄用戶決策：選擇封存
            $this->recordStorageDecision($userId, 'archive_now');
            
            // 刪除儲存空間警告通知
            $this->deleteStorageNotification($userId);
            
            // 立即觸發封存操作（通過後台任務）
            $this->triggerStorageArchive($userId);
            
            $this->logger->info('[AutoArchiver] Storage archive triggered successfully', [
                'user_id' => $userId
            ]);
            
            return new JSONResponse([
                'success' => true,
                'message' => '系統將自動封存檔案以釋放空間'
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('[AutoArchiver] Failed to trigger storage archive', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return new JSONResponse([
                'error' => '操作失敗：' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 立即觸發儲存空間封存操作
     */
    private function triggerStorageArchive(string $userId): void {
        // 通過 OCC 命令觸發 StorageMonitorJob
        $jobId = $this->getStorageMonitorJobId();
        
        if ($jobId) {
            $this->logger->info('[AutoArchiver] Triggering StorageMonitorJob', [
                'job_id' => $jobId,
                'user_id' => $userId
            ]);
            
            // 使用 shell_exec 執行背景任務（異步執行，不阻塞響應）
            $command = "php /var/www/html/occ background-job:execute {$jobId} --force-execute > /dev/null 2>&1 &";
            shell_exec($command);
            
            $this->logger->info('[AutoArchiver] StorageMonitorJob execution triggered');
        } else {
            $this->logger->warning('[AutoArchiver] StorageMonitorJob not found, will execute on next scheduled run');
        }
    }
    
    /**
     * 獲取 StorageMonitorJob 的 Job ID
     */
    private function getStorageMonitorJobId(): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('jobs')
            ->where($qb->expr()->like('class', $qb->createNamedParameter('%StorageMonitorJob%')))
            ->setMaxResults(1);
        
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        
        return $row ? (int)$row['id'] : null;
    }
    
    /**
     * 不要封存（用戶選擇忽略儲存空間警告）
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @return JSONResponse
     */
    public function skipStorageArchive(): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'User not authenticated'], 401);
        }
        
        $userId = $user->getUID();
        
        $this->logger->info('[AutoArchiver] User skipping storage archive', [
            'user_id' => $userId
        ]);
        
        try {
            // 記錄用戶決策：選擇不封存
            $this->recordStorageDecision($userId, 'skip_archive');
            
            // 刪除儲存空間警告通知
            $this->deleteStorageNotification($userId);
            
            $this->logger->info('[AutoArchiver] Storage archive skipped successfully', [
                'user_id' => $userId
            ]);
            
            return new JSONResponse([
                'success' => true,
                'message' => '已選擇不封存檔案'
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('[AutoArchiver] Failed to skip storage archive', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return new JSONResponse([
                'error' => '操作失敗：' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 記錄儲存空間決策
     */
    private function recordStorageDecision(string $userId, string $decision): void {
        $qb = $this->db->getQueryBuilder();
        
        // 檢查是否已有記錄
        $qb->select('id')
            ->from('archiver_decisions')
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter(0)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('decision', $qb->createNamedParameter('storage_warning_pending')));
        $result = $qb->executeQuery();
        $existing = $result->fetch();
        $result->closeCursor();
        
        if ($existing) {
            // 更新現有記錄
            $qb = $this->db->getQueryBuilder();
            $qb->update('archiver_decisions')
                ->set('decision', $qb->createNamedParameter($decision))
                ->set('decided_at', $qb->createNamedParameter(time()))
                ->where($qb->expr()->eq('file_id', $qb->createNamedParameter(0)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            $qb->execute();
        } else {
            // 創建新記錄
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archiver_decisions')
                ->values([
                    'file_id' => $qb->createNamedParameter(0),
                    'user_id' => $qb->createNamedParameter($userId),
                    'decision' => $qb->createNamedParameter($decision),
                    'notified_at' => $qb->createNamedParameter(time()),
                    'decided_at' => $qb->createNamedParameter(time()),
                    'file_path' => $qb->createNamedParameter('storage_warning'),
                ]);
            $qb->execute();
        }
    }
    
    /**
     * 刪除儲存空間警告通知
     */
    private function deleteStorageNotification(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('notifications')
            ->where($qb->expr()->eq('app', $qb->createNamedParameter('auto_archiver')))
            ->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('storage')))
            ->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($userId)));
        $qb->execute();
    }
}

