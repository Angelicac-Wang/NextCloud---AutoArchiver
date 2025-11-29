<?php

namespace OCA\AutoArchiver\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Controller for pinning/unpinning files
 * Pinned files are excluded from automatic archiving
 */
class PinController extends Controller {
    
    private $db;
    private $userSession;
    private $rootFolder;
    private $logger;
    
    public function __construct(
        string $appName,
        IRequest $request,
        IDBConnection $db,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->db = $db;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
    }
    
    /**
     * Pin one or multiple files
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @return JSONResponse
     */
    public function pin(): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'User not authenticated'], 401);
        }
        
        $userId = $user->getUID();
        $body = $this->request->getParams();
        
        // Support both single fileId and array of fileIds
        $fileIds = [];
        if (isset($body['fileIds']) && is_array($body['fileIds'])) {
            $fileIds = $body['fileIds'];
        } elseif (isset($body['fileId'])) {
            $fileIds = [$body['fileId']];
        } else {
            return new JSONResponse(['error' => 'No file IDs provided'], 400);
        }
        
        $this->logger->info('[AutoArchiver] User pinning files', [
            'file_ids' => $fileIds,
            'user_id' => $userId
        ]);
        
        $pinned = [];
        $failed = [];
        
        foreach ($fileIds as $fileId) {
            $fileId = (int)$fileId;
            
            try {
                // Verify file exists and belongs to user
                if (!$this->verifyFileAccess($fileId, $userId)) {
                    $failed[] = $fileId;
                    continue;
                }
                
                // Check if record exists
                $qb = $this->db->getQueryBuilder();
                $qb->select('file_id')
                   ->from('auto_archiver_access')
                   ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
                   ->setMaxResults(1);
                
                $result = $qb->executeQuery();
                $exists = $result->fetch() !== false;
                $result->closeCursor();
                
                if ($exists) {
                    // Update existing record
                    $qb = $this->db->getQueryBuilder();
                    $qb->update('auto_archiver_access')
                       ->set('is_pinned', $qb->createNamedParameter(1))
                       ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
                    $qb->execute();
                } else {
                    // Create new record
                    $qb = $this->db->getQueryBuilder();
                    $qb->insert('auto_archiver_access')
                       ->values([
                           'file_id' => $qb->createNamedParameter($fileId),
                           'last_accessed' => $qb->createNamedParameter(time()),
                           'is_pinned' => $qb->createNamedParameter(1),
                       ]);
                    $qb->execute();
                }
                
                $pinned[] = $fileId;
                
            } catch (\Exception $e) {
                $this->logger->error('[AutoArchiver] Failed to pin file', [
                    'file_id' => $fileId,
                    'user_id' => $userId,
                    'error' => $e->getMessage()
                ]);
                $failed[] = $fileId;
            }
        }
        
        $this->logger->info('[AutoArchiver] Pin operation completed', [
            'pinned' => count($pinned),
            'failed' => count($failed),
            'user_id' => $userId
        ]);
        
        return new JSONResponse([
            'success' => true,
            'pinned' => $pinned,
            'failed' => $failed,
            'message' => count($pinned) > 0 
                ? sprintf('Successfully pinned %d file(s)', count($pinned))
                : 'Failed to pin files'
        ]);
    }
    
    /**
     * Unpin one or multiple files
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @return JSONResponse
     */
    public function unpin(): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'User not authenticated'], 401);
        }
        
        $userId = $user->getUID();
        $body = $this->request->getParams();
        
        // Support both single fileId and array of fileIds
        $fileIds = [];
        if (isset($body['fileIds']) && is_array($body['fileIds'])) {
            $fileIds = $body['fileIds'];
        } elseif (isset($body['fileId'])) {
            $fileIds = [$body['fileId']];
        } else {
            return new JSONResponse(['error' => 'No file IDs provided'], 400);
        }
        
        $this->logger->info('[AutoArchiver] User unpinning files', [
            'file_ids' => $fileIds,
            'user_id' => $userId
        ]);
        
        $unpinned = [];
        $failed = [];
        
        foreach ($fileIds as $fileId) {
            $fileId = (int)$fileId;
            
            try {
                // Verify file exists and belongs to user
                if (!$this->verifyFileAccess($fileId, $userId)) {
                    $failed[] = $fileId;
                    continue;
                }
                
                // Update is_pinned to 0
                $qb = $this->db->getQueryBuilder();
                $qb->update('auto_archiver_access')
                   ->set('is_pinned', $qb->createNamedParameter(0))
                   ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
                
                $updated = $qb->execute();
                
                if ($updated > 0) {
                    $unpinned[] = $fileId;
                } else {
                    // Record doesn't exist, which is fine - treat as success
                    $unpinned[] = $fileId;
                }
                
            } catch (\Exception $e) {
                $this->logger->error('[AutoArchiver] Failed to unpin file', [
                    'file_id' => $fileId,
                    'user_id' => $userId,
                    'error' => $e->getMessage()
                ]);
                $failed[] = $fileId;
            }
        }
        
        $this->logger->info('[AutoArchiver] Unpin operation completed', [
            'unpinned' => count($unpinned),
            'failed' => count($failed),
            'user_id' => $userId
        ]);
        
        return new JSONResponse([
            'success' => true,
            'unpinned' => $unpinned,
            'failed' => $failed,
            'message' => count($unpinned) > 0 
                ? sprintf('Successfully unpinned %d file(s)', count($unpinned))
                : 'Failed to unpin files'
        ]);
    }
    
    /**
     * Get pin status for a file
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param int $fileId
     * @return JSONResponse
     */
    public function getStatus(int $fileId): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'User not authenticated'], 401);
        }
        
        $userId = $user->getUID();
        
        try {
            // Verify file exists and belongs to user
            if (!$this->verifyFileAccess($fileId, $userId)) {
                return new JSONResponse(['error' => 'File not found or access denied'], 404);
            }
            
            // Query pin status
            $qb = $this->db->getQueryBuilder();
            $qb->select('is_pinned')
               ->from('auto_archiver_access')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
               ->setMaxResults(1);
            
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            
            $isPinned = false;
            if ($row && isset($row['is_pinned'])) {
                $isPinned = (bool)$row['is_pinned'];
            }
            
            return new JSONResponse([
                'success' => true,
                'isPinned' => $isPinned
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('[AutoArchiver] Failed to get pin status', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return new JSONResponse([
                'error' => 'Failed to get pin status: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Verify that file exists and user has access to it
     * 
     * @param int $fileId
     * @param string $userId
     * @return bool
     */
    private function verifyFileAccess(int $fileId, string $userId): bool {
        try {
            $nodes = $this->rootFolder->getById($fileId);
            
            if (empty($nodes)) {
                return false;
            }
            
            $node = $nodes[0];
            $owner = $node->getOwner();
            
            if (!$owner) {
                return false;
            }
            
            // Check if user is the owner
            return $owner->getUID() === $userId;
            
        } catch (\Exception $e) {
            $this->logger->error('[AutoArchiver] File access verification failed', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}

