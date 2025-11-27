<?php

namespace OCA\AutoArchiver\Cron;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

class ArchiveOldFiles extends TimedJob {

    protected $db;
    protected $rootFolder;
    protected $logger;

    public function __construct(ITimeFactory $time, IDBConnection $db, IRootFolder $rootFolder, LoggerInterface $logger) {
        parent::__construct($time);
        
        // 設定為每天執行一次 (正式環境)
        $this->setInterval(24 * 60 * 60);
        
        $this->db = $db;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
    }

    public function run($argument) {
        $this->logger->warning("\n🚀 [AutoArchiver] Job Started... Checking for old files...");

        // 設定 30 天
        $days = 30;
        $threshold = time() - ($days * 24 * 60 * 60);

        // 查詢需要封存的文件，同時聯表查詢文件信息和存儲信息
        // 注意：Nextcloud 的表名可能帶有前綴，但查詢構建器會自動處理
        $qb = $this->db->getQueryBuilder();
        $qb->select('aa.file_id', 'fc.path', 'fc.storage', 'st.id as storage_string_id')
           ->from('auto_archiver_access', 'aa')
           ->leftJoin('aa', 'filecache', 'fc', $qb->expr()->eq('aa.file_id', 'fc.fileid'))
           ->leftJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'))
           ->where($qb->expr()->lt('aa.last_accessed', $qb->createNamedParameter($threshold)))
           ->andWhere($qb->expr()->isNotNull('fc.path')); // 只處理存在於 filecache 中的文件
        
        $result = $qb->executeQuery();
        
        $count = 0;
        $movedCount = 0;
        $skippedCount = 0;
        while ($row = $result->fetch()) {
            $fileId = $row['file_id'];
            $filePath = $row['path'] ?? null;
            $storageNumericId = $row['storage'] ?? null;
            $storageStringId = $row['storage_string_id'] ?? null;
            
            // 【關鍵點】這裡一定要呼叫 archiveFile，而且要有 Log
            $this->logger->warning("⚡ [Debug] Processing Loop: Found ID $fileId");
            if ($filePath) {
                $this->logger->warning("   File path from DB: $filePath");
            } else {
                $this->logger->warning("   ⚠️ No path found in filecache for file ID $fileId");
            }
            if ($storageNumericId) {
                $this->logger->warning("   Storage numeric ID: $storageNumericId");
            } else {
                $this->logger->warning("   ⚠️ No storage numeric ID found");
            }
            if ($storageStringId) {
                $this->logger->warning("   Storage string ID: $storageStringId");
            } else {
                $this->logger->warning("   ⚠️ No storage string ID found (leftJoin may have failed)");
            }
            
            $archiveResult = $this->archiveFile($fileId, $filePath, $storageNumericId, $storageStringId);
            if ($archiveResult === true) {
                $movedCount++;
            } elseif ($archiveResult === 'skipped') {
                $skippedCount++;
            }
            $count++;
        }
        
        $msg = "\n" .
               "🏁 [AutoArchiver] Job Finished.\n" .
               "📊 Total Processed: $count items.\n" .
               "✅ Successfully Archived: $movedCount files.\n" .
               "⏭️  Skipped (folders): $skippedCount items.";
        $this->logger->warning($msg);
    }

    private function archiveFile($fileId, $filePath = null, $storageNumericId = null, $storageStringId = null) {
        $this->logger->warning("🔍 [Debug] archiveFile() called for ID: $fileId");

        try {
            // 1. 嘗試抓取檔案節點 - 先嘗試 getById
            $nodes = $this->rootFolder->getById($fileId);
            
            // 如果 getById 失敗，且我們有文件路徑，嘗試通過路徑查找
            if (empty($nodes) && $filePath) {
                $this->logger->warning("⚠️ [Debug] getById failed, trying to find file by path: $filePath");
                
                // 從路徑中提取用戶名（格式可能是: username/files/... 或只是 files/...）
                $username = null;
                $relativePath = null;
                
                if (preg_match('#^([^/]+)/files/(.+)$#', $filePath, $matches)) {
                    // 格式: username/files/path
                    $username = $matches[1];
                    $relativePath = $matches[2];
                    $this->logger->warning("   Extracted username: $username, relative path: $relativePath");
                } elseif (preg_match('#^files/(.+)$#', $filePath, $matches)) {
                    // 格式: files/path (沒有用戶名前綴)
                    $relativePath = $matches[1];
                    $this->logger->warning("   Path format: files/... (no username prefix), relative path: $relativePath");
                    
                    // 嘗試通過存儲ID查找用戶
                    if ($storageStringId) {
                        $this->logger->warning("   Attempting to extract username from storage ID: $storageStringId");
                        $username = $this->getUsernameFromStorage($storageStringId);
                        if ($username) {
                            $this->logger->warning("   ✅ Found username from storage: $username");
                        } else {
                            $this->logger->warning("   ❌ Could not extract username from storage ID");
                        }
                    } else {
                        $this->logger->warning("   ⚠️ No storage string ID available, cannot extract username");
                        // 如果沒有存儲ID，嘗試通過存儲數字ID查詢
                        if ($storageNumericId) {
                            $this->logger->warning("   Attempting to query storage string ID from numeric ID: $storageNumericId");
                            $storageStringId = $this->getStorageStringId($storageNumericId);
                            if ($storageStringId) {
                                $this->logger->warning("   Found storage string ID: $storageStringId");
                                $username = $this->getUsernameFromStorage($storageStringId);
                                if ($username) {
                                    $this->logger->warning("   ✅ Found username from storage: $username");
                                }
                            }
                        }
                    }
                }
                
                // 如果我們有用戶名和相對路徑，嘗試獲取文件
                if ($username && $relativePath) {
                    try {
                        $userFolder = $this->rootFolder->getUserFolder($username);
                        if ($userFolder->nodeExists($relativePath)) {
                            $node = $userFolder->get($relativePath);
                            $nodes = [$node];
                            $this->logger->warning("✅ [Debug] Found file by path!");
                        } else {
                            $this->logger->warning("   File does not exist at path: $relativePath for user: $username");
                        }
                    } catch (\Exception $e) {
                        $this->logger->error("   Error getting user folder: " . $e->getMessage());
                    }
                } else {
                    $this->logger->warning("   ⚠️ Cannot determine username or relative path from: $filePath");
                }
            }
            
            if (empty($nodes)) {
                $this->logger->warning("❌ [Debug] File ID $fileId not found. File may have been deleted.");
                // 刪除無效的數據庫記錄
                $this->deleteDbRecord($fileId);
                return false;
            }

            $node = $nodes[0];
            $path = $node->getPath();
            $this->logger->warning("✅ [Debug] Node found: $path");

            // 1.5. 檢查是否為文件（跳過資料夾）
            if (!($node instanceof \OCP\Files\File)) {
                $this->logger->warning("📁 [Debug] Node is a folder, skipping archive. Path: $path");
                // 刪除資料夾的數據庫記錄（因為我們不封存資料夾）
                $this->deleteDbRecord($fileId);
                return 'skipped'; // 返回 'skipped' 表示跳過，不計入成功數量
            }
            
            $this->logger->warning("📄 [Debug] Node is a file, proceeding with archive");

            // 2. 抓取擁有者
            $owner = $node->getOwner();
            if (!$owner) {
                 $this->logger->warning("❌ [Debug] Node has no owner.");
                 return false;
            }
            $ownerId = $owner->getUID();
            $this->logger->warning("👤 [Debug] Owner: $ownerId");

            // 3. 準備封存資料夾 - getUserFolder 已經處理了用戶上下文
            $userFolder = $this->rootFolder->getUserFolder($ownerId);
            
            // 檢查是否已經在 Archive 裡面
            if (strpos($path, "/Archive/") !== false || strpos($path, "Archive/") !== false) {
                $this->logger->warning("⚠️ [Debug] File is already in Archive. Skipping move.");
                $this->deleteDbRecord($fileId);
                return true; // 已經在 Archive 中，視為成功
            }

            // 確保 Archive 資料夾存在
            if (!$userFolder->nodeExists('Archive')) {
                $this->logger->warning("📂 [Debug] Creating 'Archive' folder...");
                $userFolder->newFolder('Archive');
                $this->logger->warning("✅ [Debug] Archive folder created successfully.");
            }
            $archiveFolder = $userFolder->get('Archive');
            $this->logger->warning("📁 [Debug] Archive folder path: " . $archiveFolder->getPath());
            
            // 檢查目標位置是否已存在同名檔案
            $fileName = $node->getName();
            $originalPath = $path;
            $originalParent = $node->getParent();
            
            // 檢查是否已經被壓縮過（避免重複處理）
            $compressedFileName = $fileName . '.zip';
            if ($archiveFolder->nodeExists($compressedFileName)) {
                $this->logger->warning("⚠️ [Debug] Compressed file already exists in Archive: " . $compressedFileName . ". Skipping.");
                $this->deleteDbRecord($fileId);
                return true;
            }

            // 4. 壓縮並移動文件
            $this->logger->warning("🚀 [Debug] Attempting to compress and archive file:");
            $this->logger->warning("   Source: $path");
            $this->logger->warning("   Archive folder path: " . $archiveFolder->getPath());
            
            // 4.1 創建臨時壓縮文件
            $tempZipPath = sys_get_temp_dir() . '/nc_archive_' . uniqid() . '.zip';
            $zip = new \ZipArchive();
            
            if ($zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
                $this->logger->error("❌ [Debug] Cannot create zip file: $tempZipPath");
                return false;
            }
            
            // 獲取文件的實際路徑
            $storage = $node->getStorage();
            $internalPath = $node->getInternalPath();
            $realPath = $storage->getLocalFile($internalPath);
            
            if ($realPath && file_exists($realPath)) {
                $zip->addFile($realPath, $fileName);
                $zip->close();
                
                $this->logger->warning("✅ [Debug] File compressed successfully: $tempZipPath");
                
                // 4.2 將壓縮文件上傳到 Archive 資料夾
                $compressedFile = $archiveFolder->newFile($compressedFileName, file_get_contents($tempZipPath));
                unlink($tempZipPath); // 刪除臨時文件
                
                $this->logger->warning("✅ [Debug] Compressed file uploaded to Archive: " . $compressedFile->getPath());
                
                // 4.3 刪除原始文件
                $node->delete();
                $this->logger->warning("🗑️ [Debug] Original file deleted");
                
                // 4.4 在原位置創建占位符文件
                $placeholderName = $fileName . '.ncarchive';
                $placeholderContent = json_encode([
                    'original_name' => $fileName,
                    'archived_at' => time(),
                    'archived_file_id' => $compressedFile->getId(),
                    'archived_path' => $compressedFile->getPath(),
                    'original_path' => $originalPath,
                    'owner' => $ownerId
                ], JSON_PRETTY_PRINT);
                
                $placeholder = $originalParent->newFile($placeholderName, $placeholderContent);
                $this->logger->warning("📝 [Debug] Placeholder file created: " . $placeholder->getPath());
                
                // 5. 刪除 DB 紀錄
                $this->deleteDbRecord($fileId);
                
                return true;
            } else {
                $zip->close();
                unlink($tempZipPath);
                $this->logger->error("❌ [Debug] Cannot access file for compression: $realPath");
                return false;
            }

        } catch (\Exception $e) {
            $this->logger->error("❌ [AutoArchiver] Error archiving file ID $fileId:");
            $this->logger->error("   Error message: " . $e->getMessage());
            $this->logger->error("   Error code: " . $e->getCode());
            $this->logger->error("   File: " . $e->getFile() . " Line: " . $e->getLine());
            $this->logger->error("   Stack trace:\n" . $e->getTraceAsString());
            return false;
        } catch (\Throwable $e) {
            $this->logger->error("❌ [AutoArchiver] Fatal error archiving file ID $fileId:");
            $this->logger->error("   Error message: " . $e->getMessage());
            $this->logger->error("   Stack trace:\n" . $e->getTraceAsString());
            return false;
        }
    }

    private function getStorageStringId($storageNumericId) {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('storages')
               ->where($qb->expr()->eq('numeric_id', $qb->createNamedParameter($storageNumericId)));
            
            $result = $qb->executeQuery();
            $row = $result->fetch();
            
            if ($row && isset($row['id'])) {
                return $row['id'];
            }
        } catch (\Exception $e) {
            $this->logger->error("   Error getting storage string ID: " . $e->getMessage());
        }
        
        return null;
    }

    private function getUsernameFromStorage($storageStringId) {
        try {
            // 從存儲ID字符串提取用戶名
            // 存儲ID格式通常是: home::username 或 local::/path/to/data/username
            if (is_string($storageStringId)) {
                // 存儲ID格式: home::username
                if (preg_match('#^home::(.+)$#', $storageStringId, $matches)) {
                    return $matches[1];
                }
                // 存儲ID格式: local::/path/to/data/username
                if (preg_match('#^local::/.+/([^/]+)$#', $storageStringId, $matches)) {
                    return $matches[1];
                }
                $this->logger->warning("   Storage ID format not recognized: $storageStringId");
            }
        } catch (\Exception $e) {
            $this->logger->error("   Error getting username from storage: " . $e->getMessage());
        }
        
        return null;
    }

    private function deleteDbRecord($fileId) {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('auto_archiver_access')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
           ->execute();
        $this->logger->warning("🗑️ [Debug] DB Record deleted for ID: $fileId");
    }
}