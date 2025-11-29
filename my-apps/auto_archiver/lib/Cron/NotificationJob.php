<?php
namespace OCA\AutoArchiver\Cron;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * 通知任务：检查即将被封存的文件（7天内），发送通知给用户
 */
class NotificationJob extends TimedJob {
    
    private $db;
    private $notificationManager;
    private $logger;
    
    // 7天后将被封存
    const DAYS_BEFORE_ARCHIVE = 7;
    // 30天未访问会被封存
    const ARCHIVE_THRESHOLD_DAYS = 30;
    
    public function __construct(
        ITimeFactory $time,
        IDBConnection $db,
        INotificationManager $notificationManager,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->db = $db;
        $this->notificationManager = $notificationManager;
        $this->logger = $logger;
        
        // 每小时检查一次
        $this->setInterval(3600);
    }
    
    protected function run($argument): void {
        $this->logger->info('[AutoArchiver] NotificationJob started');
        
        try {
            $this->checkAndNotifyFiles();
        } catch (\Exception $e) {
            $this->logger->error('[AutoArchiver] NotificationJob error: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
        
        $this->logger->info('[AutoArchiver] NotificationJob completed');
    }
    
    private function checkAndNotifyFiles(): void {
        // 计算时间阈值
        // 即将被封存的文件：23天前访问的（30-7=23天）
        $notifyThreshold = time() - ((self::ARCHIVE_THRESHOLD_DAYS - self::DAYS_BEFORE_ARCHIVE) * 24 * 3600);
        $archiveThreshold = time() - (self::ARCHIVE_THRESHOLD_DAYS * 24 * 3600);
        
        $this->logger->debug('[AutoArchiver] Checking files to notify', [
            'notify_threshold' => date('Y-m-d H:i:s', $notifyThreshold),
            'archive_threshold' => date('Y-m-d H:i:s', $archiveThreshold)
        ]);
        
        // 查询即将被封存的文件
        $qb = $this->db->getQueryBuilder();
        $qb->select('a.file_id', 'a.last_accessed', 'f.path', 's.id as storage_id')
            ->from('auto_archiver_access', 'a')
            ->innerJoin('a', 'filecache', 'f', 'a.file_id = f.fileid')
            ->innerJoin('f', 'storages', 's', 'f.storage = s.numeric_id')
            ->where($qb->expr()->lte('a.last_accessed', $qb->createNamedParameter($notifyThreshold)))
            ->andWhere($qb->expr()->gt('a.last_accessed', $qb->createNamedParameter($archiveThreshold)))
            ->andWhere($qb->expr()->like('s.id', $qb->createNamedParameter('home::%')))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('a.is_pinned', $qb->createNamedParameter(0)),
                $qb->expr()->isNull('a.is_pinned')
            )); // 排除已釘選的檔案
        
        $result = $qb->execute();
        $filesToNotify = $result->fetchAll();
        $result->closeCursor();
        
        $this->logger->info('[AutoArchiver] Found ' . count($filesToNotify) . ' files to notify');
        
        foreach ($filesToNotify as $file) {
            $this->sendNotificationForFile($file);
        }
    }
    
    private function sendNotificationForFile(array $file): void {
        $fileId = (int)$file['file_id'];
        $filePath = $file['path'];
        $storageId = $file['storage_id'];
        
        // 从 storage_id 提取用户名 (home::username)
        if (!preg_match('/^home::(.+)$/', $storageId, $matches)) {
            return;
        }
        $userId = $matches[1];
        
        // 检查是否已经发送过通知（24小时内）
        if ($this->hasRecentNotification($fileId, $userId)) {
            return;
        }
        
        // 提取实际文件路径
        $actualPath = preg_replace('/^files\//', '', $filePath);
        
        // 计算剩余天数 (至少1天，最多7天)
        $lastAccessed = (int)$file['last_accessed'];
        $secondsUntilArchive = ($lastAccessed + (self::ARCHIVE_THRESHOLD_DAYS * 24 * 3600)) - time();
        $daysUntilArchive = (int)max(1, min(
            self::DAYS_BEFORE_ARCHIVE,
            ceil($secondsUntilArchive / (24 * 3600))
        ));
        
        $this->logger->info('[AutoArchiver] Sending notification', [
            'file_id' => $fileId,
            'user_id' => $userId,
            'path' => $actualPath,
            'days_until_archive' => $daysUntilArchive
        ]);
        
        // 创建通知
        $notification = $this->notificationManager->createNotification();
        $notification->setApp('auto_archiver')
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('file', (string)$fileId)
            ->setSubject('file_will_archive', [
                'file' => $actualPath,
                'days' => $daysUntilArchive
            ])
            ->setMessage('file_will_archive_message', [
                'file' => $actualPath,
                'days' => $daysUntilArchive
            ]);
        
        try {
            $this->notificationManager->notify($notification);
            
            // 记录通知已发送（避免重复发送）
            $this->recordNotificationSent($fileId, $userId, $actualPath);
            
            $this->logger->info('[AutoArchiver] Notification sent successfully', [
                'file_id' => $fileId,
                'user_id' => $userId
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[AutoArchiver] Failed to send notification', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    
    private function hasRecentNotification(int $fileId, string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('archiver_decisions')
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gt('notified_at', $qb->createNamedParameter(time() - 86400))) // 24小时内
            ->setMaxResults(1);
        
        $result = $qb->execute();
        $hasNotification = $result->fetch() !== false;
        $result->closeCursor();
        
        return $hasNotification;
    }
    
    private function recordNotificationSent(int $fileId, string $userId, string $filePath): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('archiver_decisions')
            ->values([
                'file_id' => $qb->createNamedParameter($fileId),
                'user_id' => $qb->createNamedParameter($userId),
                'decision' => $qb->createNamedParameter('pending'),
                'notified_at' => $qb->createNamedParameter(time()),
                'decided_at' => $qb->createNamedParameter(0),
                'file_path' => $qb->createNamedParameter($filePath),
            ]);
        $qb->execute();
    }
}

