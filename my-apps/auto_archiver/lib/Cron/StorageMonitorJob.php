<?php

namespace OCA\AutoArchiver\Cron;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

class StorageMonitorJob extends TimedJob {

    protected $db;
    protected $rootFolder;
    protected $userManager;
    protected $notificationManager;
    protected $logger;
    
    // 存储空间使用率阈值（80%）
    // 测试时临时改为 50%，便于测试
    private const STORAGE_THRESHOLD = 0.80; // 临时测试值：50%

    public function __construct(
        ITimeFactory $time,
        IDBConnection $db,
        IRootFolder $rootFolder,
        IUserManager $userManager,
        INotificationManager $notificationManager,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        
        // 設定為每小時執行一次（可以根據需要調整）
        $this->setInterval(60 * 60);
        
        $this->db = $db;
        $this->rootFolder = $rootFolder;
        $this->userManager = $userManager;
        $this->notificationManager = $notificationManager;
        $this->logger = $logger;
    }

    public function run($argument) {
        $this->logger->warning("\n🔍 [StorageMonitor] Job Started... Checking storage usage...");

        // 獲取所有用戶
        $users = $this->userManager->search('');
        
        $totalUsersChecked = 0;
        $usersOverThreshold = 0;
        $totalFilesArchived = 0;

        foreach ($users as $user) {
            $totalUsersChecked++;
            $usageInfo = $this->checkUserStorageUsage($user);
            
            if ($usageInfo['overThreshold']) {
                $usersOverThreshold++;
                $thresholdPercent = self::STORAGE_THRESHOLD * 100;
                $this->logger->warning("⚠️ [StorageMonitor] User '{$user->getUID()}' storage usage: {$usageInfo['usagePercent']}% (Threshold: {$thresholdPercent}%)");
                $this->logger->warning("   Used: {$usageInfo['usedFormatted']} / {$usageInfo['quotaFormatted']}");
                
                // 發送儲存空間警告通知（24小時內只發送一次）
                $this->sendStorageWarningNotification($user, $usageInfo);
                
                // 檢查用戶是否選擇不要封存
                $userDecision = $this->getUserStorageDecision($user->getUID());
                
                if ($userDecision === 'skip_archive') {
                    $this->logger->warning("   ℹ️  User chose 'skip_archive', will not automatically archive files");
                    $this->logger->warning("   💡 User needs to manually free up space or increase quota");
                } else {
                    // 開始封存最久未使用的檔案
                    $archivedCount = $this->archiveUntilBelowThreshold($user, $usageInfo);
                    $totalFilesArchived += $archivedCount;
                    
                    $this->logger->warning("   ✅ Archived {$archivedCount} files to reduce storage usage");
                }
            }
        }

        $thresholdPercent = self::STORAGE_THRESHOLD * 100;
        $msg = "\n" .
               "🏁 [StorageMonitor] Job Finished.\n" .
               "📊 Total Users Checked: $totalUsersChecked\n" .
               "⚠️  Users Over Threshold ({$thresholdPercent}%): $usersOverThreshold\n" .
               "📦 Total Files Archived: $totalFilesArchived";
        $this->logger->warning($msg);
    }

    /**
     * 檢查用戶存儲使用率
     */
    private function checkUserStorageUsage(IUser $user) {
        $userId = $user->getUID();
        $quota = $user->getQuota();
        
        // 獲取用戶資料夾
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $usedSpace = $userFolder->getSize();
        
        // 解析配額
        $quotaBytes = $this->parseQuota($quota);
        
        // 計算使用率
        $usagePercent = 0;
        if ($quotaBytes > 0 && $quotaBytes !== PHP_INT_MAX) {
            $usagePercent = ($usedSpace / $quotaBytes) * 100;
        } else {
            // 無限制配額，無法計算使用率，視為未超過閾值
            return [
                'overThreshold' => false,
                'usagePercent' => 0,
                'used' => $usedSpace,
                'quota' => $quotaBytes,
                'usedFormatted' => $this->formatBytes($usedSpace),
                'quotaFormatted' => 'unlimited'
            ];
        }
        
        $overThreshold = $usagePercent >= (self::STORAGE_THRESHOLD * 100);
        
        return [
            'overThreshold' => $overThreshold,
            'usagePercent' => round($usagePercent, 2),
            'used' => $usedSpace,
            'quota' => $quotaBytes,
            'usedFormatted' => $this->formatBytes($usedSpace),
            'quotaFormatted' => $this->formatBytes($quotaBytes)
        ];
    }

    /**
     * 持續封存檔案直到使用率降到閾值以下
     */
    private function archiveUntilBelowThreshold(IUser $user, array $initialUsageInfo) {
        $userId = $user->getUID();
        $archivedCount = 0;
        $maxIterations = 20; // 減少最大迭代次數，防止無限循環
        $iteration = 0;
        $processedFileIds = []; // 追蹤已處理的文件ID，避免重複處理
        $consecutiveFailures = 0; // 連續失敗次數
        $maxConsecutiveFailures = 5; // 最大連續失敗次數
        
        while ($iteration < $maxIterations) {
            $iteration++;
            
            $this->logger->warning("🔄 [StorageMonitor] Iteration $iteration/$maxIterations for user '{$userId}'");
            
            // 重新檢查使用率
            $usageInfo = $this->checkUserStorageUsage($user);
            
            // 如果已經降到閾值以下，停止封存
            if (!$usageInfo['overThreshold']) {
                $thresholdPercent = self::STORAGE_THRESHOLD * 100;
                $this->logger->warning("✅ [StorageMonitor] User '{$userId}' storage usage now at {$usageInfo['usagePercent']}% (below {$thresholdPercent}%)");
                break;
            }
            
            // 查詢最久未使用的檔案（按 last_accessed 升序排序）
            $files = $this->getOldestUnusedFiles($userId, 10); // 每次處理 10 個檔案
            
            if (empty($files)) {
                $this->logger->warning("⚠️ [StorageMonitor] No more files to archive for user '{$userId}'");
                break;
            }
            
            // 過濾掉已處理的文件
            $newFiles = [];
            foreach ($files as $file) {
                $fileId = $file['file_id'];
                if (!in_array($fileId, $processedFileIds)) {
                    $newFiles[] = $file;
                } else {
                    $this->logger->warning("   ⏭️  File ID $fileId already processed, skipping");
                }
            }
            
            if (empty($newFiles)) {
                $this->logger->warning("⚠️ [StorageMonitor] All files have been processed, but usage still above threshold. Stopping.");
                break;
            }
            
            $iterationArchived = 0;
            $iterationSkipped = 0;
            $iterationFailed = 0;
            
            // 封存這些檔案
            foreach ($newFiles as $file) {
                $fileId = $file['file_id'];
                $filePath = $file['path'] ?? null;
                $storageNumericId = $file['storage'] ?? null;
                $storageStringId = $file['storage_string_id'] ?? null;
                
                // 標記為已處理
                $processedFileIds[] = $fileId;
                
                $this->logger->warning("📦 [StorageMonitor] Archiving file ID $fileId for user '{$userId}'");
                
                $result = $this->archiveFile($fileId, $filePath, $storageNumericId, $storageStringId);
                
                if ($result === true) {
                    $archivedCount++;
                    $iterationArchived++;
                    $consecutiveFailures = 0; // 重置連續失敗計數
                    $this->logger->warning("   ✅ File archived successfully");
                } elseif ($result === 'skipped') {
                    $iterationSkipped++;
                    $this->logger->warning("   ⏭️  File skipped (folder)");
                } else {
                    $iterationFailed++;
                    $consecutiveFailures++;
                    $this->logger->warning("   ❌ Failed to archive file");
                }
            }
            
            $this->logger->warning("📊 [StorageMonitor] Iteration $iteration results: {$iterationArchived} archived, {$iterationSkipped} skipped, {$iterationFailed} failed");
            
            // 如果連續失敗次數過多，停止處理
            if ($consecutiveFailures >= $maxConsecutiveFailures) {
                $this->logger->warning("⚠️ [StorageMonitor] Too many consecutive failures ($consecutiveFailures), stopping for user '{$userId}'");
                break;
            }
            
            // 如果這輪沒有成功封存任何文件，停止處理
            if ($iterationArchived === 0 && $iterationSkipped === 0) {
                $this->logger->warning("⚠️ [StorageMonitor] No files were processed in this iteration, stopping");
                break;
            }
            
            // 短暫延遲，避免過度負載
            usleep(200000); // 0.2 秒
        }
        
        if ($iteration >= $maxIterations) {
            $this->logger->warning("⚠️ [StorageMonitor] Reached max iterations ($maxIterations) for user '{$userId}'");
        }
        
        $this->logger->warning("📊 [StorageMonitor] Total archived for user '{$userId}': $archivedCount files in $iteration iterations");
        
        return $archivedCount;
    }

    /**
     * 獲取最久未使用的檔案列表
     */
    private function getOldestUnusedFiles($userId, $limit = 10) {
        $qb = $this->db->getQueryBuilder();
        $qb->select('aa.file_id', 'fc.path', 'fc.storage', 'st.id as storage_string_id', 'aa.last_accessed')
           ->from('auto_archiver_access', 'aa')
           ->leftJoin('aa', 'filecache', 'fc', $qb->expr()->eq('aa.file_id', 'fc.fileid'))
           ->leftJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'))
           ->where($qb->expr()->isNotNull('fc.path'))
           ->andWhere($qb->expr()->orX(
               $qb->expr()->eq('aa.is_pinned', $qb->createNamedParameter(0)),
               $qb->expr()->isNull('aa.is_pinned')
           )) // 排除已釘選的檔案
           ->orderBy('aa.last_accessed', 'ASC') // 最久未使用的在前
           ->setMaxResults($limit);
        
        $result = $qb->executeQuery();
        $files = [];
        $totalFound = 0;
        
        while ($row = $result->fetch()) {
            $totalFound++;
            $filePath = $row['path'] ?? '';
            $storageStringId = $row['storage_string_id'] ?? null;
            
            // 調試：記錄找到的文件路徑
            $this->logger->warning("🔍 [StorageMonitor] Found file ID {$row['file_id']}, path: $filePath, storage: $storageStringId");
            
            // 驗證檔案屬於該用戶
            // 路徑格式可能是：
            // 1. username/files/path/to/file
            // 2. /username/files/path/to/file
            // 3. files/path/to/file (需要通過 storage 驗證)
            $belongsToUser = false;
            
            if (strpos($filePath, '/' . $userId . '/files/') !== false || 
                strpos($filePath, $userId . '/files/') === 0) {
                $belongsToUser = true;
            } elseif (preg_match('#^files/(.+)$#', $filePath)) {
                // 如果路徑格式是 files/...，通過 storage ID 驗證
                if ($storageStringId) {
                    $extractedUsername = $this->getUsernameFromStorage($storageStringId);
                    if ($extractedUsername === $userId) {
                        $belongsToUser = true;
                    }
                }
            }
            
            if ($belongsToUser) {
                $files[] = $row;
                $this->logger->warning("   ✅ File belongs to user '{$userId}'");
            } else {
                $this->logger->warning("   ❌ File does not belong to user '{$userId}' (skipped)");
            }
        }
        
        $filesCount = count($files);
        $this->logger->warning("📊 [StorageMonitor] Found $totalFound files in DB, $filesCount belong to user '{$userId}'");
        
        return $files;
    }

    /**
     * 封存檔案（重用 ArchiveOldFiles 的邏輯）
     */
    private function archiveFile($fileId, $filePath = null, $storageNumericId = null, $storageStringId = null) {
        try {
            // 1. 嘗試抓取檔案節點
            $nodes = $this->rootFolder->getById($fileId);
            
            // 如果 getById 失敗，且我們有文件路徑，嘗試通過路徑查找
            if (empty($nodes) && $filePath) {
                $username = null;
                $relativePath = null;
                
                if (preg_match('#^([^/]+)/files/(.+)$#', $filePath, $matches)) {
                    $username = $matches[1];
                    $relativePath = $matches[2];
                } elseif (preg_match('#^files/(.+)$#', $filePath, $matches)) {
                    $relativePath = $matches[1];
                    if ($storageStringId) {
                        $username = $this->getUsernameFromStorage($storageStringId);
                    }
                }
                
                if ($username && $relativePath) {
                    try {
                        $userFolder = $this->rootFolder->getUserFolder($username);
                        if ($userFolder->nodeExists($relativePath)) {
                            $node = $userFolder->get($relativePath);
                            $nodes = [$node];
                        }
                    } catch (\Exception $e) {
                        $this->logger->error("Error getting user folder: " . $e->getMessage());
                    }
                }
            }
            
            if (empty($nodes)) {
                $this->logger->warning("❌ File ID $fileId not found. Deleting DB record.");
                $this->deleteDbRecord($fileId);
                return false;
            }

            $node = $nodes[0];
            $path = $node->getPath();

            // 檢查是否為文件（跳過資料夾）
            if (!($node instanceof \OCP\Files\File)) {
                $this->logger->warning("📁 Node is a folder, skipping archive. Path: $path");
                $this->deleteDbRecord($fileId);
                return 'skipped';
            }
            
            // 檢查是否已經在 Archive 裡面
            if (strpos($path, "/Archive/") !== false || strpos($path, "Archive/") !== false) {
                $this->logger->warning("⚠️ File is already in Archive. Skipping move.");
                $this->deleteDbRecord($fileId);
                return true;
            }

            // 獲取擁有者
            $owner = $node->getOwner();
            if (!$owner) {
                $this->logger->warning("❌ Node has no owner.");
                return false;
            }
            $ownerId = $owner->getUID();

            // 準備封存資料夾
            $userFolder = $this->rootFolder->getUserFolder($ownerId);
            
            // 確保 Archive 資料夾存在
            if (!$userFolder->nodeExists('Archive')) {
                try {
                    $this->logger->warning("📂 [StorageMonitor] Creating 'Archive' folder for user '{$ownerId}'...");
                    $userFolder->newFolder('Archive');
                    $this->logger->warning("✅ [StorageMonitor] Archive folder created successfully.");
                } catch (\Exception $e) {
                    $this->logger->error("❌ [StorageMonitor] Failed to create Archive folder: " . $e->getMessage());
                    return false;
                }
            }
            
            try {
                $archiveFolder = $userFolder->get('Archive');
            } catch (\Exception $e) {
                $this->logger->error("❌ [StorageMonitor] Failed to get Archive folder: " . $e->getMessage());
                return false;
            }
            
            // 檢查是否已經被壓縮過
            $fileName = $node->getName();
            $compressedFileName = $fileName . '.zip';
            if ($archiveFolder->nodeExists($compressedFileName)) {
                $this->logger->warning("⚠️ Compressed file already exists in Archive. Skipping.");
                $this->deleteDbRecord($fileId);
                return true;
            }

            // 壓縮並移動文件
            $tempZipPath = sys_get_temp_dir() . '/nc_archive_' . uniqid() . '.zip';
            $zip = new \ZipArchive();
            
            if ($zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
                $this->logger->error("❌ Cannot create zip file: $tempZipPath");
                return false;
            }
            
            $storage = $node->getStorage();
            $internalPath = $node->getInternalPath();
            $realPath = $storage->getLocalFile($internalPath);
            
            if ($realPath && file_exists($realPath)) {
                $fileSize = filesize($realPath);
                $this->logger->warning("📏 [StorageMonitor] Original file size: " . $this->formatBytes($fileSize));
                
                // 先創建 ZIP 文件（在臨時目錄）
                $zip->addFile($realPath, $fileName);
                $zip->close();
                
                $actualZipSize = filesize($tempZipPath);
                $compressionRatio = $fileSize > 0 ? round(($actualZipSize / $fileSize) * 100, 1) : 0;
                $this->logger->warning("📦 [StorageMonitor] ZIP file created, size: " . $this->formatBytes($actualZipSize) . " (compression ratio: {$compressionRatio}%)");
                
                // 保存原文件的父目錄引用（在刪除前）
                $originalParent = $node->getParent();
                
                // 檢查是否有足夠空間
                $user = $this->userManager->get($ownerId);
                $needToDeleteFirst = false;
                
                if ($user) {
                    $quota = $user->getQuota();
                    $quotaBytes = $this->parseQuota($quota);
                    $currentUsed = $userFolder->getSize();
                    $availableSpace = $quotaBytes - $currentUsed;
                    
                    $this->logger->warning("📊 [StorageMonitor] Available space: " . $this->formatBytes($availableSpace) . ", ZIP size: " . $this->formatBytes($actualZipSize));
                    
                    // 如果可用空間不足，需要先刪除原文件
                    if ($quotaBytes !== PHP_INT_MAX && $availableSpace < $actualZipSize) {
                        $this->logger->warning("⚠️ [StorageMonitor] Not enough space. Will delete original file first to free up space.");
                        $needToDeleteFirst = true;
                    }
                }
                
                // 如果需要，先刪除原文件釋放空間
                if ($needToDeleteFirst) {
                    // 重要：如果 ZIP 文件比原文件大，不應該刪除原文件（這不應該發生，但作為安全措施）
                    if ($actualZipSize > $fileSize) {
                        $this->logger->error("❌ [StorageMonitor] ZIP file is larger than original! Skipping archive to prevent data loss.");
                        $this->logger->error("   Original: " . $this->formatBytes($fileSize) . ", ZIP: " . $this->formatBytes($actualZipSize));
                        unlink($tempZipPath);
                        return false;
                    }
                    
                    $node->delete();
                    $this->logger->warning("🗑️ [StorageMonitor] Original file deleted to free up space");
                    
                    // 手動計算可用空間：刪除原文件後，空間應該增加（原文件大小 - ZIP 文件大小）
                    // 因為 ZIP 文件比原文件小，所以刪除原文件後，可用空間應該增加
                    $spaceFreed = $fileSize - $actualZipSize; // 釋放的空間
                    $estimatedAvailableSpace = $availableSpace + $spaceFreed;
                    
                    $this->logger->warning("📊 [StorageMonitor] Space calculation after deletion:");
                    $this->logger->warning("   Original file size: " . $this->formatBytes($fileSize));
                    $this->logger->warning("   ZIP file size: " . $this->formatBytes($actualZipSize));
                    $this->logger->warning("   Space freed: " . $this->formatBytes($spaceFreed));
                    $this->logger->warning("   Estimated available: " . $this->formatBytes($estimatedAvailableSpace));
                    
                    // 因為 ZIP 文件比原文件小，刪除原文件後應該總是有足夠空間
                    // 但我們還是檢查一下，以防萬一
                    if ($estimatedAvailableSpace < $actualZipSize) {
                        $this->logger->error("❌ [StorageMonitor] Unexpected: Still not enough space after deletion. This should not happen!");
                        $this->logger->error("   Required: " . $this->formatBytes($actualZipSize) . ", Available: " . $this->formatBytes($estimatedAvailableSpace));
                        unlink($tempZipPath);
                        return false;
                    }
                    
                    $availableSpace = $estimatedAvailableSpace;
                }
                
                // 將壓縮文件上傳到 Archive 資料夾
                try {
                    $zipContent = file_get_contents($tempZipPath);
                    if ($zipContent === false) {
                        throw new \Exception("Failed to read temporary zip file");
                    }
                    $this->logger->warning("📤 [StorageMonitor] Uploading compressed file to Archive: $compressedFileName");
                    $this->logger->warning("   File size: " . $this->formatBytes(strlen($zipContent)));
                    $compressedFile = $archiveFolder->newFile($compressedFileName, $zipContent);
                    $this->logger->warning("✅ [StorageMonitor] Compressed file uploaded successfully: " . $compressedFile->getPath());
                    unlink($tempZipPath);
                } catch (\Exception $e) {
                    $this->logger->error("❌ [StorageMonitor] Failed to upload compressed file: " . $e->getMessage());
                    $this->logger->error("   Archive folder path: " . $archiveFolder->getPath());
                    $this->logger->error("   Compressed file name: $compressedFileName");
                    $this->logger->error("   ZIP file size: " . (file_exists($tempZipPath) ? $this->formatBytes(filesize($tempZipPath)) : 'N/A'));
                    unlink($tempZipPath);
                    // 如果原文件已經被刪除，無法恢復
                    if ($needToDeleteFirst) {
                        $this->logger->error("⚠️ [StorageMonitor] Original file was already deleted, cannot restore");
                    }
                    return false;
                }
                
                // 如果原文件還沒被刪除，現在刪除它
                if (!$needToDeleteFirst) {
                    $node->delete();
                    $this->logger->warning("🗑️ [StorageMonitor] Original file deleted");
                }
                
                // 在原位置創建占位符文件
                try {
                    $placeholderName = $fileName . '.ncarchive';
                    $placeholderContent = json_encode([
                        'original_name' => $fileName,
                        'archived_at' => time(),
                        'archived_file_id' => $compressedFile->getId(),
                        'archived_path' => $compressedFile->getPath(),
                        'original_path' => $path,
                        'owner' => $ownerId
                    ], JSON_PRETTY_PRINT);
                    
                    $this->logger->warning("📝 [StorageMonitor] Creating placeholder file: $placeholderName");
                    $placeholder = $originalParent->newFile($placeholderName, $placeholderContent);
                    $this->logger->warning("✅ [StorageMonitor] Placeholder file created successfully: " . $placeholder->getPath());
                } catch (\Exception $e) {
                    $this->logger->error("❌ [StorageMonitor] Failed to create placeholder file: " . $e->getMessage());
                    // 即使占位符創建失敗，封存仍然成功（ZIP 文件已創建）
                }
                
                // 刪除 DB 紀錄
                $this->deleteDbRecord($fileId);
                
                return true;
            } else {
                $zip->close();
                unlink($tempZipPath);
                $this->logger->error("❌ Cannot access file for compression: $realPath");
                return false;
            }

        } catch (\Exception $e) {
            $this->logger->error("❌ Error archiving file ID $fileId: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 從存儲ID提取用戶名
     */
    private function getUsernameFromStorage($storageStringId) {
        if (is_string($storageStringId)) {
            if (preg_match('#^home::(.+)$#', $storageStringId, $matches)) {
                return $matches[1];
            }
            if (preg_match('#^local::/.+/([^/]+)$#', $storageStringId, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    /**
     * 刪除資料庫記錄
     */
    private function deleteDbRecord($fileId) {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('auto_archiver_access')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
           ->execute();
    }

    /**
     * 格式化字節數為可讀格式
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        if ($bytes < 0) {
            return '0 B';
        }
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * 解析配額字符串為字節數
     */
    private function parseQuota($quota) {
        if ($quota === 'none' || $quota === 'unlimited' || $quota === null || $quota === '') {
            return PHP_INT_MAX;
        }
        
        if (is_numeric($quota)) {
            return (int)$quota;
        }
        
        $quota = trim($quota);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(B|KB|MB|GB|TB)$/i', $quota, $matches)) {
            $value = (float)$matches[1];
            $unit = strtoupper($matches[2]);
            
            $multipliers = [
                'B' => 1,
                'KB' => 1024,
                'MB' => 1024 * 1024,
                'GB' => 1024 * 1024 * 1024,
                'TB' => 1024 * 1024 * 1024 * 1024
            ];
            
            return (int)($value * $multipliers[$unit]);
        }
        
        return PHP_INT_MAX;
    }
    
    /**
     * 發送儲存空間警告通知
     */
    private function sendStorageWarningNotification(IUser $user, array $usageInfo): void {
        $userId = $user->getUID();
        
        // 檢查是否在 24 小時內已發送過通知
        if ($this->hasRecentStorageNotification($userId)) {
            $this->logger->info('[StorageMonitor] Storage warning notification already sent in last 24 hours for user: ' . $userId);
            return;
        }
        
        $usagePercent = round($usageInfo['usagePercent'], 1);
        $usedFormatted = $usageInfo['usedFormatted'];
        $quotaFormatted = $usageInfo['quotaFormatted'];
        
        $this->logger->info('[StorageMonitor] Sending storage warning notification', [
            'user_id' => $userId,
            'usage_percent' => $usagePercent,
            'used' => $usedFormatted,
            'quota' => $quotaFormatted
        ]);
        
        try {
            // 創建通知
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('auto_archiver')
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject('storage', $userId) // 使用 'storage' 作為 object_type，userId 作為 object_id
                ->setSubject('storage_warning', [
                    'usage_percent' => $usagePercent,
                    'used' => $usedFormatted,
                    'quota' => $quotaFormatted
                ])
                ->setMessage('storage_warning_message', [
                    'usage_percent' => $usagePercent,
                    'used' => $usedFormatted,
                    'quota' => $quotaFormatted
                ]);
            
            $this->notificationManager->notify($notification);
            
            // 記錄通知已發送
            $this->recordStorageNotificationSent($userId);
            
            $this->logger->info('[StorageMonitor] Storage warning notification sent successfully', [
                'user_id' => $userId
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[StorageMonitor] Failed to send storage warning notification', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 檢查是否在 24 小時內已發送過儲存空間通知
     */
    private function hasRecentStorageNotification(string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('archiver_decisions')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('decision', $qb->createNamedParameter('storage_warning_pending')))
            ->andWhere($qb->expr()->gt('notified_at', $qb->createNamedParameter(time() - 86400))) // 24小時內
            ->setMaxResults(1);
        
        $result = $qb->executeQuery();
        $hasNotification = $result->fetch() !== false;
        $result->closeCursor();
        
        return $hasNotification;
    }
    
    /**
     * 記錄儲存空間通知已發送
     */
    private function recordStorageNotificationSent(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('archiver_decisions')
            ->values([
                'file_id' => $qb->createNamedParameter(0), // 儲存空間通知沒有 file_id，使用 0
                'user_id' => $qb->createNamedParameter($userId),
                'decision' => $qb->createNamedParameter('storage_warning_pending'),
                'notified_at' => $qb->createNamedParameter(time()),
                'decided_at' => $qb->createNamedParameter(0),
                'file_path' => $qb->createNamedParameter('storage_warning'),
            ]);
        $qb->executeStatement();
    }
    
    /**
     * 獲取用戶的儲存空間決策
     * 返回 'skip_archive' 表示用戶選擇不要封存
     * 返回 null 表示用戶未做決策或決策已過期（24小時）
     */
    private function getUserStorageDecision(string $userId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('decision', 'decided_at')
            ->from('archiver_decisions')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_path', $qb->createNamedParameter('storage_warning')))
            ->andWhere($qb->expr()->eq('decision', $qb->createNamedParameter('skip_archive')))
            ->andWhere($qb->expr()->gt('decided_at', $qb->createNamedParameter(time() - 86400))) // 24小時內有效
            ->orderBy('decided_at', 'DESC')
            ->setMaxResults(1);
        
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        
        if ($row) {
            $this->logger->info('[StorageMonitor] Found user decision: skip_archive (decided at ' . date('Y-m-d H:i:s', $row['decided_at']) . ')');
            return 'skip_archive';
        }
        
        return null;
    }
}

