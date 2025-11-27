<?php

namespace OCA\AutoArchiver\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class RestoreController extends Controller {

    private $rootFolder;
    private $logger;
    private $userManager;

    public function __construct($AppName, IRequest $request, IRootFolder $rootFolder, IUserManager $userManager, LoggerInterface $logger) {
        parent::__construct($AppName, $request);
        $this->rootFolder = $rootFolder;
        $this->userManager = $userManager;
        $this->logger = $logger;
    }

    /**
     * @NoAdminRequired
     */
    public function restore(int $fileId) {
        try {
            $this->logger->warning("🔄 [Restore] Restore request for file ID: $fileId");
            
            // 1. 獲取占位符文件
            $nodes = $this->rootFolder->getById($fileId);
            if (empty($nodes)) {
                return new DataResponse([
                    'success' => false,
                    'error' => 'Placeholder file not found'
                ], 404);
            }
            
            $placeholderNode = $nodes[0];
            $placeholderPath = $placeholderNode->getPath();
            
            // 2. 讀取占位符內容
            $placeholderContent = $placeholderNode->getContent();
            $metadata = json_decode($placeholderContent, true);
            
            if (!$metadata || !isset($metadata['archived_file_id'])) {
                return new DataResponse([
                    'success' => false,
                    'error' => 'Invalid placeholder file'
                ], 400);
            }
            
            $this->logger->warning("📋 [Restore] Metadata: " . json_encode($metadata));
            
            // 3. 獲取壓縮文件
            $archivedFileId = $metadata['archived_file_id'];
            $archivedNodes = $this->rootFolder->getById($archivedFileId);
            
            if (empty($archivedNodes)) {
                return new DataResponse([
                    'success' => false,
                    'error' => 'Archived file not found'
                ], 404);
            }
            
            $archivedFile = $archivedNodes[0];
            $originalName = $metadata['original_name'];
            $ownerId = $metadata['owner'];
            
            // 4. 檢查用戶存儲配額（在解壓前檢查，只讀取 zip 信息，不解壓）
            $user = $this->userManager->get($ownerId);
            if ($user) {
                // 獲取壓縮文件內容（用於讀取 zip 信息）
                $zipContent = $archivedFile->getContent();
                $compressedSize = strlen($zipContent);
                
                // 使用 ZipArchive 讀取 zip 文件信息（不需要實際解壓）
                $tempZipPath = sys_get_temp_dir() . '/nc_restore_check_' . uniqid() . '.zip';
                file_put_contents($tempZipPath, $zipContent);
                
                $zip = new \ZipArchive();
                if ($zip->open($tempZipPath, \ZipArchive::RDONLY) === TRUE) {
                    // 獲取解壓後的文件大小（從 zip 文件頭讀取，不需要實際解壓）
                    $extractedSize = 0;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        if ($stat && isset($stat['size'])) {
                            $extractedSize += $stat['size'];
                        }
                    }
                    $zip->close();
                    
                    // 清理臨時文件
                    unlink($tempZipPath);
                    
                    $this->logger->warning("📊 [Restore] Compressed size: " . $this->formatBytes($compressedSize) . ", Extracted size: " . $this->formatBytes($extractedSize));
                    
                    // 獲取用戶配額和已用空間
                    $quota = $user->getQuota();
                    $userFolder = $this->rootFolder->getUserFolder($ownerId);
                    $usedSpace = $userFolder->getSize();
                    
                    $this->logger->warning("📊 [Restore] User quota: " . ($quota === 'none' || $quota === null || $quota === '' ? 'unlimited' : $quota) . ", Used: " . $this->formatBytes($usedSpace));
                    
                    // 檢查是否有配額限制
                    if ($quota !== 'none' && $quota !== null && $quota !== '') {
                        $quotaBytes = $this->parseQuota($quota);
                        $availableSpace = $quotaBytes - $usedSpace;
                        
                        $this->logger->warning("📊 [Restore] Available space: " . $this->formatBytes($availableSpace) . ", Required: " . $this->formatBytes($extractedSize));
                        
                        // 檢查空間是否足夠（允許 1% 的緩衝，因為 Nextcloud 可能允許稍微超過）
                        if ($extractedSize > $availableSpace * 1.01) {
                            $this->logger->warning("❌ [Restore] Insufficient storage space!");
                            return new DataResponse([
                                'success' => false,
                                'error' => 'storage_quota_exceeded',
                                'message' => '存儲空間不足！恢復此檔案需要 ' . $this->formatBytes($extractedSize) . 
                                           '，但您只有 ' . $this->formatBytes($availableSpace) . ' 可用空間。' .
                                           '請先刪除一些檔案或聯繫管理員增加配額。',
                                'required' => $extractedSize,
                                'available' => $availableSpace,
                                'quota' => $quotaBytes,
                                'used' => $usedSpace
                            ], 400);
                        }
                    }
                } else {
                    // 如果無法讀取 zip 信息，記錄警告但繼續（讓實際解壓時處理錯誤）
                    $this->logger->warning("⚠️ [Restore] Cannot read zip file info for quota check, proceeding anyway");
                }
            }
            
            // 5. 創建臨時目錄並解壓（實際恢復）
            $tempDir = sys_get_temp_dir() . '/nc_restore_' . uniqid();
            mkdir($tempDir, 0700, true);
            
            $tempZipPath = $tempDir . '/archive.zip';
            file_put_contents($tempZipPath, $archivedFile->getContent());
            
            $zip = new \ZipArchive();
            if ($zip->open($tempZipPath) !== TRUE) {
                return new DataResponse([
                    'success' => false,
                    'error' => 'Cannot open archive file'
                ], 500);
            }
            
            // 6. 解壓到臨時目錄
            $zip->extractTo($tempDir);
            $zip->close();
            
            // 7. 獲取解壓後的文件
            $extractedFilePath = $tempDir . '/' . $originalName;
            if (!file_exists($extractedFilePath)) {
                return new DataResponse([
                    'success' => false,
                    'error' => 'File not found in archive'
                ], 500);
            }
            
            // 8. 恢復文件到原位置
            $userFolder = $this->rootFolder->getUserFolder($ownerId);
            
            // 從占位符路徑中提取相對路徑
            // 格式: /username/files/path/to/file.ncarchive
            $relativePath = str_replace('/' . $ownerId . '/files/', '', $placeholderPath);
            $relativePath = dirname($relativePath);
            
            if ($relativePath === '.' || $relativePath === '') {
                $parentFolder = $userFolder;
            } else {
                $parentFolder = $userFolder->get($relativePath);
            }
            
            // 創建恢復的文件
            $restoredFile = $parentFolder->newFile($originalName, file_get_contents($extractedFilePath));
            
            // 9. 刪除占位符文件
            $placeholderNode->delete();
            
            // 10. 刪除 Archive 資料夾中的壓縮文件
            try {
                $archivedFile->delete();
                $this->logger->warning("🗑️ [Restore] Archived zip file deleted: " . $archivedFile->getPath());
            } catch (\Exception $e) {
                $this->logger->error("⚠️ [Restore] Failed to delete archived file: " . $e->getMessage());
                // 即使刪除失敗，恢復仍然成功
            }
            
            // 11. 清理臨時文件
            unlink($tempZipPath);
            unlink($extractedFilePath);
            rmdir($tempDir);
            
            $this->logger->warning("✅ [Restore] File restored successfully: " . $restoredFile->getPath());
            
            return new DataResponse([
                'success' => true,
                'fileId' => $restoredFile->getId(),
                'path' => $restoredFile->getPath()
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error("❌ [Restore] Error: " . $e->getMessage());
            $this->logger->error($e->getTraceAsString());
            
            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
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
     * 支持格式: "10 GB", "unlimited", "none", 數字（字節）
     */
    private function parseQuota($quota) {
        if ($quota === 'none' || $quota === 'unlimited' || $quota === null || $quota === '') {
            return PHP_INT_MAX; // 無限制
        }
        
        // 如果是數字字符串，直接返回
        if (is_numeric($quota)) {
            return (int)$quota;
        }
        
        // 解析帶單位的字符串，如 "10 GB"
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
        
        // 如果無法解析，返回無限制
        return PHP_INT_MAX;
    }
}

