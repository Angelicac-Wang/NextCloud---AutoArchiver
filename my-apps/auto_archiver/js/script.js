document.addEventListener('DOMContentLoaded', function() {
    console.log('🕵️ AutoArchiver v0.1.3 Loaded (with restore support, quota checking, and storage monitoring)');

    document.body.addEventListener('click', function(event) {
        
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