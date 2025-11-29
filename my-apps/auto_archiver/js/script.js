// Global debug flag
window.AUTO_ARCHIVER_DEBUG = true;

document.addEventListener('DOMContentLoaded', function() {
    console.log('🕵️ AutoArchiver v0.2.0 Loaded (with restore support, quota checking, storage monitoring, and pinning)');
    console.log('[AutoArchiver] Script loaded successfully');

    // Pin functionality
    const pinManager = {
        // Cache for pin status
        pinStatusCache: new Map(),
        // Track pending queries to avoid duplicate requests
        pendingQueries: null,
        
        // Initialize pin icons for all files in the list
        initPinIcons: function() {
            console.log('[AutoArchiver] Initializing pin icons...');
            
            // Try multiple selectors to find file rows
            const selectors = [
                'tr[data-cy-files-list-row-fileid]',
                'tr[data-file-id]',
                'tr.files-file-list-row',
                'tr.file-row',
                'tbody tr',
                'table.files-fileList tbody tr'
            ];
            
            let fileRows = [];
            for (const selector of selectors) {
                fileRows = document.querySelectorAll(selector);
                if (fileRows.length > 0) {
                    console.log(`[AutoArchiver] Found ${fileRows.length} file rows using selector: ${selector}`);
                    break;
                }
            }
            
            if (fileRows.length === 0) {
                console.warn('[AutoArchiver] No file rows found. Trying alternative approach...');
                // Try to find any table rows that might be files
                const allRows = document.querySelectorAll('table tbody tr, .files-list tr');
                console.log(`[AutoArchiver] Found ${allRows.length} total rows, checking for file data...`);
                allRows.forEach(row => {
                    // Check if row has file ID in any form
                    const fileId = row.dataset.cyFilesListRowFileid || 
                                  row.dataset.fileId || 
                                  row.getAttribute('data-file-id') ||
                                  row.getAttribute('data-cy-files-list-row-fileid');
                    if (fileId) {
                        fileRows.push(row);
                    }
                });
                console.log(`[AutoArchiver] Found ${fileRows.length} rows with file IDs`);
            }
            
            fileRows.forEach((row, index) => {
                console.log(`[AutoArchiver] Processing row ${index + 1}/${fileRows.length}`);
                this.addPinIconToRow(row);
            });
            
            console.log(`[AutoArchiver] Pin icon initialization complete. Processed ${fileRows.length} rows.`);
        },
        
        // Add pin icon to a file row
        addPinIconToRow: function(row) {
            // Skip if pin icon already exists
            if (row.querySelector('.auto-archiver-pin-icon')) {
                return;
            }
            
            // Try multiple ways to get file ID
            const fileIdRaw = row.dataset.cyFilesListRowFileid || 
                          row.dataset.fileId || 
                          row.getAttribute('data-file-id') ||
                          row.getAttribute('data-cy-files-list-row-fileid');
            
            if (!fileIdRaw) {
                console.log('[AutoArchiver] Row has no file ID, skipping:', row);
                return;
            }
            
            // Normalize fileId to number for consistent cache key
            const fileId = parseInt(fileIdRaw, 10);
            if (isNaN(fileId)) {
                console.warn('[AutoArchiver] Invalid file ID:', fileIdRaw);
                return;
            }
            
            // Try multiple ways to get file name
            const fileName = row.dataset.cyFilesListRowName || 
                            row.dataset.fileName ||
                            row.getAttribute('data-cy-files-list-row-name') ||
                            row.getAttribute('data-file-name') ||
                            '';
            
            // Skip placeholder files (.ncarchive)
            if (fileName && fileName.endsWith('.ncarchive')) {
                console.log('[AutoArchiver] Skipping placeholder file:', fileName);
                return;
            }
            
            console.log(`[AutoArchiver] Adding pin icon to file ID: ${fileId}, name: ${fileName}`);
            
            // Find the file name cell or action area - try multiple selectors
            const fileNameCell = row.querySelector('td.filename') ||
                                row.querySelector('td[data-cy-files-list-row-name]') ||
                                row.querySelector('td[class*="filename"]') ||
                                row.querySelector('td:first-child') ||
                                row.querySelector('.filename') ||
                                row.querySelector('[class*="name"]');
            
            if (!fileNameCell) {
                console.warn('[AutoArchiver] Could not find file name cell for row:', row);
                return;
            }
            
            console.log('[AutoArchiver] Found file name cell:', fileNameCell);
            
            // Create pin icon button
            const pinButton = document.createElement('span');
            pinButton.className = 'auto-archiver-pin-icon';
            pinButton.setAttribute('data-file-id', fileId);
            pinButton.setAttribute('role', 'button');
            pinButton.setAttribute('tabindex', '0');
            pinButton.setAttribute('aria-label', 'Pin/unpin file');
            pinButton.style.cssText = 'cursor: pointer; margin-left: 8px; font-size: 16px; display: inline-block; vertical-align: middle; user-select: none;';
            pinButton.innerHTML = '📍'; // Default: unpinned (hollow)
            
            // Add click handler
            pinButton.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.handlePinClick(fileId, pinButton);
            });
            
            // Add keyboard support
            pinButton.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    e.stopPropagation();
                    this.handlePinClick(fileId, pinButton);
                }
            });
            
            // Insert pin icon after file name or in action area
            const fileLink = fileNameCell.querySelector('a') ||
                            fileNameCell.querySelector('.name') ||
                            fileNameCell.querySelector('[class*="name"]') ||
                            fileNameCell.querySelector('span');
            
            if (fileLink && fileLink.parentNode) {
                try {
                    fileLink.parentNode.insertBefore(pinButton, fileLink.nextSibling);
                    console.log('[AutoArchiver] Pin icon inserted after file link');
                } catch (e) {
                    console.warn('[AutoArchiver] Failed to insert after link, appending to cell:', e);
                    fileNameCell.appendChild(pinButton);
                }
            } else {
                fileNameCell.appendChild(pinButton);
                console.log('[AutoArchiver] Pin icon appended to file name cell');
            }
            
            // Query pin status
            this.queryPinStatus(fileId, pinButton);
        },
        
        // Query pin status for a file
        queryPinStatus: function(fileId, pinButton) {
            // Normalize fileId to number for consistent cache key
            const fileIdNum = typeof fileId === 'string' ? parseInt(fileId, 10) : fileId;
            if (isNaN(fileIdNum)) {
                console.error('[AutoArchiver] Invalid fileId in queryPinStatus:', fileId);
                this.updatePinIcon(pinButton, false);
                return;
            }
            
            // Check cache first
            if (this.pinStatusCache.has(fileIdNum)) {
                const cachedStatus = this.pinStatusCache.get(fileIdNum);
                this.updatePinIcon(pinButton, cachedStatus);
                return;
            }
            
            // Track pending requests to avoid duplicate queries
            if (this.pendingQueries && this.pendingQueries.has(fileIdNum)) {
                // Query already in progress, wait for it
                const pendingPromise = this.pendingQueries.get(fileIdNum);
                pendingPromise.then(isPinned => {
                    this.updatePinIcon(pinButton, isPinned);
                }).catch(() => {
                    this.updatePinIcon(pinButton, false);
                });
                return;
            }
            
            // Initialize pending queries map if not exists
            if (!this.pendingQueries) {
                this.pendingQueries = new Map();
            }
            
            const url = OC.generateUrl('/apps/auto_archiver/pin/{fileId}/status', { fileId: fileIdNum });
            
            const queryPromise = fetch(url, {
                method: 'GET',
                headers: { 'requesttoken': OC.requestToken }
            })
            .then(res => {
                if (!res.ok) {
                    // If response is not OK, treat as unpinned
                    console.warn('[AutoArchiver] Failed to get pin status for file', fileIdNum, 'Status:', res.status);
                    this.pinStatusCache.set(fileIdNum, false);
                    return false;
                }
                return res.json();
            })
            .then(data => {
                // Remove from pending queries
                if (this.pendingQueries) {
                    this.pendingQueries.delete(fileIdNum);
                }
                
                if (data && data.success !== undefined) {
                    const isPinned = data.isPinned || false;
                    this.pinStatusCache.set(fileIdNum, isPinned);
                    this.updatePinIcon(pinButton, isPinned);
                    console.log('[AutoArchiver] Pin status for file', fileIdNum, ':', isPinned ? 'pinned' : 'unpinned');
                    return isPinned;
                } else if (data && data.error) {
                    // Handle error response
                    console.error('[AutoArchiver] Error getting pin status:', data.error);
                    this.pinStatusCache.set(fileIdNum, false);
                    this.updatePinIcon(pinButton, false);
                    return false;
                } else {
                    // Unknown response format, default to unpinned
                    console.warn('[AutoArchiver] Unknown response format for pin status:', data);
                    this.pinStatusCache.set(fileIdNum, false);
                    this.updatePinIcon(pinButton, false);
                    return false;
                }
            })
            .catch(error => {
                // Remove from pending queries
                if (this.pendingQueries) {
                    this.pendingQueries.delete(fileIdNum);
                }
                console.error('[AutoArchiver] Failed to query pin status for file', fileIdNum, ':', error);
                // On error, default to unpinned
                this.pinStatusCache.set(fileIdNum, false);
                this.updatePinIcon(pinButton, false);
                return false;
            });
            
            // Store pending query
            this.pendingQueries.set(fileIdNum, queryPromise);
        },
        
        // Update pin icon based on status
        updatePinIcon: function(pinButton, isPinned) {
            if (isPinned) {
                pinButton.innerHTML = '📌'; // Pinned (solid)
                pinButton.setAttribute('aria-label', 'Unpin file');
            } else {
                pinButton.innerHTML = '📍'; // Unpinned (hollow)
                pinButton.setAttribute('aria-label', 'Pin file');
            }
        },
        
        // Handle pin icon click
        handlePinClick: function(fileId, pinButton) {
            const isCurrentlyPinned = pinButton.innerHTML === '📌';
            
            if (isCurrentlyPinned) {
                this.unpinFile(fileId, pinButton);
            } else {
                this.pinFile(fileId, pinButton);
            }
        },
        
        // Pin a file
        pinFile: function(fileId, pinButton) {
            // Normalize fileId to number
            const fileIdNum = typeof fileId === 'string' ? parseInt(fileId, 10) : fileId;
            if (isNaN(fileIdNum)) {
                console.error('[AutoArchiver] Invalid fileId in pinFile:', fileId);
                OC.Notification.showTemporary('Invalid file ID', { type: 'error' });
                return;
            }
            
            const url = OC.generateUrl('/apps/auto_archiver/pin');
            
            fetch(url, {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ fileId: fileIdNum })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.pinStatusCache.set(fileIdNum, true);
                    this.updatePinIcon(pinButton, true);
                    OC.Notification.showTemporary('File pinned successfully', { type: 'success', timeout: 2000 });
                } else {
                    OC.Notification.showTemporary('Failed to pin file: ' + (data.error || 'Unknown error'), { type: 'error' });
                }
            })
            .catch(error => {
                console.error('Pin error:', error);
                OC.Notification.showTemporary('Failed to pin file: ' + error.message, { type: 'error' });
            });
        },
        
        // Unpin a file
        unpinFile: function(fileId, pinButton) {
            // Normalize fileId to number
            const fileIdNum = typeof fileId === 'string' ? parseInt(fileId, 10) : fileId;
            if (isNaN(fileIdNum)) {
                console.error('[AutoArchiver] Invalid fileId in unpinFile:', fileId);
                OC.Notification.showTemporary('Invalid file ID', { type: 'error' });
                return;
            }
            
            const url = OC.generateUrl('/apps/auto_archiver/pin');
            
            fetch(url, {
                method: 'DELETE',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ fileId: fileIdNum })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.pinStatusCache.set(fileIdNum, false);
                    this.updatePinIcon(pinButton, false);
                    OC.Notification.showTemporary('File unpinned successfully', { type: 'success', timeout: 2000 });
                } else {
                    OC.Notification.showTemporary('Failed to unpin file: ' + (data.error || 'Unknown error'), { type: 'error' });
                }
            })
            .catch(error => {
                console.error('Unpin error:', error);
                OC.Notification.showTemporary('Failed to unpin file: ' + error.message, { type: 'error' });
            });
        },
        
        // Batch pin/unpin files
        batchPinFiles: function(fileIds, pin) {
            const url = OC.generateUrl('/apps/auto_archiver/pin');
            const method = pin ? 'POST' : 'DELETE';
            
            const loadingMsg = OC.Notification.showTemporary(pin ? 'Pinning files...' : 'Unpinning files...', { timeout: 0 });
            
            fetch(url, {
                method: method,
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ fileIds: fileIds.map(id => parseInt(id)) })
            })
            .then(res => res.json())
            .then(data => {
                OC.Notification.hide(loadingMsg);
                
                if (data.success) {
                    // Update cache and icons
                    const successIds = pin ? data.pinned : data.unpinned;
                    successIds.forEach(fileId => {
                        const fileIdNum = typeof fileId === 'string' ? parseInt(fileId, 10) : fileId;
                        if (!isNaN(fileIdNum)) {
                            this.pinStatusCache.set(fileIdNum, pin);
                            // Try both string and number formats for selector
                            const pinButton = document.querySelector(`.auto-archiver-pin-icon[data-file-id="${fileId}"]`) ||
                                            document.querySelector(`.auto-archiver-pin-icon[data-file-id="${fileIdNum}"]`);
                            if (pinButton) {
                                this.updatePinIcon(pinButton, pin);
                            }
                        }
                    });
                    
                    const successCount = successIds.length;
                    const failedCount = data.failed ? data.failed.length : 0;
                    
                    if (failedCount === 0) {
                        OC.Notification.showTemporary(
                            `Successfully ${pin ? 'pinned' : 'unpinned'} ${successCount} file(s)`,
                            { type: 'success', timeout: 3000 }
                        );
                    } else {
                        OC.Notification.showTemporary(
                            `${pin ? 'Pinned' : 'Unpinned'} ${successCount} file(s), ${failedCount} failed`,
                            { type: 'warning', timeout: 5000 }
                        );
                    }
                } else {
                    OC.Notification.showTemporary('Operation failed: ' + (data.error || 'Unknown error'), { type: 'error' });
                }
            })
            .catch(error => {
                OC.Notification.hide(loadingMsg);
                console.error('Batch pin error:', error);
                OC.Notification.showTemporary('Operation failed: ' + error.message, { type: 'error' });
            });
        }
    };
    
    // Initialize pin icons when page loads
    // Use multiple strategies to ensure initialization
    function initializePins() {
        console.log('[AutoArchiver] Attempting to initialize pin icons...');
        console.log('[AutoArchiver] Document ready state:', document.readyState);
        console.log('[AutoArchiver] Current URL:', window.location.href);
        
        // Wait a bit for Nextcloud to render the file list
        setTimeout(function() {
            pinManager.initPinIcons();
        }, 1000);
        
        // Also try after a longer delay
        setTimeout(function() {
            console.log('[AutoArchiver] Second initialization attempt...');
            pinManager.initPinIcons();
        }, 3000);
    }
    
    // Initialize immediately if DOM is ready
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        initializePins();
    } else {
        document.addEventListener('DOMContentLoaded', initializePins);
    }
    
    // Also initialize when window loads
    window.addEventListener('load', function() {
        console.log('[AutoArchiver] Window loaded, initializing pins...');
        setTimeout(function() {
            pinManager.initPinIcons();
        }, 2000);
    });
    
    // Watch for new files added to the list (e.g., after navigation or file operations)
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) { // Element node
                    // Check if it's a file row - try multiple ways
                    const fileId = node.dataset?.cyFilesListRowFileid || 
                                  node.dataset?.fileId ||
                                  node.getAttribute('data-file-id') ||
                                  node.getAttribute('data-cy-files-list-row-fileid');
                    
                    if (node.tagName === 'TR' && fileId) {
                        console.log('[AutoArchiver] New file row detected via observer:', fileId);
                        pinManager.addPinIconToRow(node);
                    }
                    
                    // Check if file rows were added inside this node
                    const selectors = [
                        'tr[data-cy-files-list-row-fileid]',
                        'tr[data-file-id]',
                        'tr.files-file-list-row',
                        'tbody tr'
                    ];
                    
                    for (const selector of selectors) {
                        const fileRows = node.querySelectorAll && node.querySelectorAll(selector);
                        if (fileRows && fileRows.length > 0) {
                            console.log(`[AutoArchiver] Found ${fileRows.length} file rows inside node using: ${selector}`);
                            fileRows.forEach(row => {
                                pinManager.addPinIconToRow(row);
                            });
                            break;
                        }
                    }
                }
            });
        });
    });
    
    // Start observing the file list container - try multiple selectors
    const containerSelectors = [
        '#app-content',
        '.files-list',
        'table.files-fileList',
        'table[class*="files"]',
        '#app-content-vue',
        '.app-content',
        'main'
    ];
    
    let fileListContainer = null;
    for (const selector of containerSelectors) {
        fileListContainer = document.querySelector(selector);
        if (fileListContainer) {
            console.log(`[AutoArchiver] Found file list container using: ${selector}`);
            break;
        }
    }
    
    if (fileListContainer) {
        observer.observe(fileListContainer, {
            childList: true,
            subtree: true
        });
        console.log('[AutoArchiver] MutationObserver started');
    } else {
        console.warn('[AutoArchiver] Could not find file list container, observing body instead');
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
    
    // Also re-initialize when navigating (for SPA-like behavior)
    let lastUrl = location.href;
    setInterval(function() {
        if (location.href !== lastUrl) {
            lastUrl = location.href;
            console.log('[AutoArchiver] URL changed, re-initializing pin icons');
            setTimeout(function() {
                pinManager.initPinIcons();
            }, 1000);
        }
    }, 1000);
    
    // Handle batch operations from Nextcloud's selection menu
    // Listen for custom events or check for selection changes
    let lastSelectedFiles = new Set();
    setInterval(function() {
        const selectedRows = document.querySelectorAll('tr.files-selected, tr.selected');
        const currentSelectedFiles = new Set();
        
        selectedRows.forEach(row => {
            const fileId = row.dataset.cyFilesListRowFileid;
            if (fileId) {
                currentSelectedFiles.add(fileId);
            }
        });
        
        // Check if selection changed
        if (currentSelectedFiles.size !== lastSelectedFiles.size || 
            ![...currentSelectedFiles].every(id => lastSelectedFiles.has(id))) {
            lastSelectedFiles = currentSelectedFiles;
            
            // Add batch pin/unpin options to action menu if files are selected
            if (currentSelectedFiles.size > 0) {
                addBatchPinOptions(Array.from(currentSelectedFiles));
            }
        }
    }, 500);
    
    // Add batch pin/unpin options to the action menu
    function addBatchPinOptions(fileIds) {
        // Find the action menu or toolbar
        const actionMenu = document.querySelector('.files-controls, .files-selected-actions, .app-sidebar-header__menu');
        if (!actionMenu || actionMenu.querySelector('.auto-archiver-batch-pin')) {
            return; // Already added or menu not found
        }
        
        // Create batch pin button
        const batchPinButton = document.createElement('button');
        batchPinButton.className = 'auto-archiver-batch-pin';
        batchPinButton.textContent = '📌 Pin Selected';
        batchPinButton.style.cssText = 'margin-left: 8px; padding: 6px 12px;';
        batchPinButton.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            pinManager.batchPinFiles(fileIds, true);
        };
        
        // Create batch unpin button
        const batchUnpinButton = document.createElement('button');
        batchUnpinButton.className = 'auto-archiver-batch-unpin';
        batchUnpinButton.textContent = '📍 Unpin Selected';
        batchUnpinButton.style.cssText = 'margin-left: 8px; padding: 6px 12px;';
        batchUnpinButton.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            pinManager.batchPinFiles(fileIds, false);
        };
        
        actionMenu.appendChild(batchPinButton);
        actionMenu.appendChild(batchUnpinButton);
        
        // Remove buttons when selection is cleared
        setTimeout(function() {
            const selectedRows = document.querySelectorAll('tr.files-selected, tr.selected');
            if (selectedRows.length === 0) {
                batchPinButton.remove();
                batchUnpinButton.remove();
            }
        }, 100);
    }

    document.body.addEventListener('click', function(event) {
        
        // Skip if clicking on pin icon
        if (event.target.closest('.auto-archiver-pin-icon')) {
            return;
        }
        
        // 1. 尋找被點擊元素所在的 "表格行 (tr)"
        let row = event.target.closest('tr');

        if (!row) {
            return;
        }

        // 2. 從 dataset 中抓取文件信息
        let dataset = row.dataset;
        let fileId = dataset.cyFilesListRowFileid;
        let fileName = dataset.cyFilesListRowName;

        if (!fileId) {
            return;
        }
        
        // 3. 檢查是否為占位符文件 (.ncarchive)
        if (fileName && fileName.endsWith('.ncarchive')) {
            event.preventDefault();
            event.stopPropagation();
            
            console.log(`📦 Placeholder file detected: ${fileName}, ID: ${fileId}`);
            
            // 顯示確認對話框
            const originalName = fileName.replace('.ncarchive', '');
            const message = `此檔案已被封存以節省儲存空間。\n\n原始檔案名稱: ${originalName}\n\n是否要恢復此檔案？恢復後檔案會自動解壓縮並回到原位置。`;
            
            if (confirm(message)) {
                // 顯示載入提示
                const loadingMsg = OC.Notification.showTemporary('正在恢復檔案...', { timeout: 0 });
                
                // 調用恢復 API
                let url = OC.generateUrl('/apps/auto_archiver/restore/{fileId}', { fileId: fileId });
                
                fetch(url, {
                    method: 'POST',
                    headers: { 'requesttoken': OC.requestToken }
                })
                .then(res => res.json())
                .then(data => {
                    OC.Notification.hide(loadingMsg);
                    
                    if (data.success) {
                        OC.Notification.showTemporary('檔案恢復成功！正在刷新...', { type: 'success', timeout: 2000 });
                        // 快速刷新頁面（強制從服務器重新加載，跳過緩存）
                        // 使用最短延遲，確保服務器端操作完成即可
                        setTimeout(() => {
                            // 方法1: 使用 reload() - 現代瀏覽器會自動跳過緩存
                            // 方法2: 如果方法1不工作，使用 href 重新賦值（更快）
                            if (window.location.reload) {
                                window.location.reload();
                            } else {
                                window.location.href = window.location.href;
                            }
                        }, 50); // 最小延遲，加速刷新
                    } else {
                        // 檢查是否為存儲空間不足錯誤
                        if (data.error === 'storage_quota_exceeded' && data.message) {
                            // 顯示詳細的錯誤消息
                            OC.Notification.showTemporary(data.message, { 
                                type: 'error', 
                                timeout: 10000 // 顯示更長時間，讓用戶有時間閱讀
                            });
                            console.error('Storage quota exceeded:', {
                                required: data.required,
                                available: data.available,
                                quota: data.quota,
                                used: data.used
                            });
                        } else {
                            // 其他錯誤
                            OC.Notification.showTemporary('恢復失敗: ' + (data.message || data.error || '未知錯誤'), { 
                                type: 'error',
                                timeout: 5000
                            });
                        }
                    }
                })
                .catch(error => {
                    OC.Notification.hide(loadingMsg);
                    OC.Notification.showTemporary('恢復失敗: ' + error.message, { type: 'error' });
                    console.error('Restore error:', error);
                });
            }
            
            return;
        }
        
        // 4. 普通文件點擊 - 發送 Ping
        console.log(`✅ File Click Detected! Name: ${fileName}, ID: ${fileId}`);

        let url = OC.generateUrl('/apps/auto_archiver/ping/{fileId}', { fileId: fileId });

        fetch(url, {
            method: 'POST',
            headers: { 'requesttoken': OC.requestToken },
            keepalive: true
        }).then(res => {
            if (res.ok) console.log('   📡 Ping sent successfully');
        });

    }, true); // 開啟捕獲模式 Capture Mode
});