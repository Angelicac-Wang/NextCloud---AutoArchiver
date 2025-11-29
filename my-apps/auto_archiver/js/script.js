// 立即執行：偵測並設定 data-app 屬性（在 CSS 載入前執行）
(function() {
    const setDataApp = function() {
        if (!document.body) {
            // 如果 body 還沒準備好，稍後再試
            setTimeout(setDataApp, 10);
            return;
        }

        const path = window.location.pathname;
        const search = window.location.search;
        const bodyId = document.body.id;
        let newDataApp = null;

        // 判斷應該設定哪個 data-app
        if (bodyId === 'body-user' && document.body.classList.contains('dashboard')) {
            newDataApp = 'dashboard';
        } else if (path.includes('/apps/auto_archiver')) {
            newDataApp = 'cold_palace';
        } else if (path.includes('/apps/files') && (search.includes('view=cold_palace') || search.includes('dir=%2Farchive') || search.includes('dir=/archive'))) {
            // Files app 且在冷宮區視圖或 archive 資料夾 -> 冷宮主題
            newDataApp = 'cold_palace';
        } else if (path.includes('/apps/files')) {
            newDataApp = 'files';
        } else if (path.includes('/apps/photos')) {
            newDataApp = 'photos';
        } else if (path.includes('/settings')) {
            newDataApp = 'settings';
        } else if (path === '/' || path === '/index.php' || path.includes('/apps/dashboard')) {
            newDataApp = 'dashboard';
        }

        // 只有當 data-app 需要改變時才更新
        const currentDataApp = document.body.getAttribute('data-app');
        if (newDataApp && currentDataApp !== newDataApp) {
            document.body.setAttribute('data-app', newDataApp);
            const icons = {
                'dashboard': '🏠',
                'cold_palace': '❄️',
                'files': '📁',
                'photos': '📷',
                'settings': '⚙️'
            };
            console.log(`${icons[newDataApp] || '📄'} Set data-app="${newDataApp}" for background`);
        }
    };

    setDataApp();

    // 監聽 URL 變化（用於 Files app 內的資料夾切換）
    // 當切換資料夾時，URL 的 query string 會改變，但不會觸發頁面重載
    let lastUrl = location.href;
    const checkUrlChange = function() {
        const currentUrl = location.href;
        if (currentUrl !== lastUrl) {
            console.log('🔄 URL changed from', lastUrl, 'to', currentUrl);
            lastUrl = currentUrl;
            // URL 改變時重新檢查 data-app
            setDataApp();
        }
    };

    // 使用 MutationObserver 監聽 history API
    const originalPushState = history.pushState;
    const originalReplaceState = history.replaceState;

    history.pushState = function() {
        originalPushState.apply(this, arguments);
        checkUrlChange();
    };

    history.replaceState = function() {
        originalReplaceState.apply(this, arguments);
        checkUrlChange();
    };

    // 監聽 popstate（瀏覽器前進/後退）
    window.addEventListener('popstate', checkUrlChange);

    // 定期檢查（備用方案，以防某些情況下事件未觸發）
    setInterval(checkUrlChange, 500);
})();

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

            // 顯示自訂對話框（宮廷風格）
            const originalName = fileName.replace('.ncarchive', '');
            const message = `愛妃 ${originalName} 昔日被打入冷宮，如今久未蒙召。

皇上是否要召回此愛妃？

召回後她將解開枷鎖，重返後宮侍寢。`;

            // 建立自訂對話框
            const showCustomDialog = (message, onConfirm, onCancel) => {
                // 建立遮罩層
                const overlay = document.createElement('div');
                overlay.className = 'custom-dialog-overlay';

                // 建立對話框
                const dialog = document.createElement('div');
                dialog.className = 'custom-dialog';

                // 建立標題
                const title = document.createElement('div');
                title.className = 'custom-dialog-title';
                title.textContent = '皇上，冷宮捎來消息';

                // 建立內容
                const content = document.createElement('div');
                content.className = 'custom-dialog-content';
                content.textContent = message;

                // 建立按鈕列
                const actions = document.createElement('div');
                actions.className = 'custom-dialog-actions';

                // 建立「朕再想想」按鈕
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'custom-dialog-btn custom-dialog-btn-secondary';
                cancelBtn.textContent = '朕再想想';
                cancelBtn.onclick = () => {
                    overlay.remove();
                    if (onCancel) onCancel();
                };

                // 建立「傳召回宮」按鈕
                const confirmBtn = document.createElement('button');
                confirmBtn.className = 'custom-dialog-btn custom-dialog-btn-primary';
                confirmBtn.textContent = '傳召回宮';
                confirmBtn.onclick = () => {
                    overlay.remove();
                    if (onConfirm) onConfirm();
                };

                // 組裝對話框
                actions.appendChild(cancelBtn);
                actions.appendChild(confirmBtn);
                dialog.appendChild(title);
                dialog.appendChild(content);
                dialog.appendChild(actions);
                overlay.appendChild(dialog);

                // 添加到 body
                document.body.appendChild(overlay);

                console.log('✅ Custom dialog created');
            };

            // 顯示對話框
            showCustomDialog(
                message,
                function() {
                    // 確認回調

                    // 顯示載入提示
                    const loadingMsg = OC.Notification.showTemporary('正在召回愛妃...', { timeout: 0 });
                
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
                        OC.Notification.showTemporary('愛妃已召回，重返後宮！', { type: 'success', timeout: 2000 });
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
                },
                null  // 取消回調
            );

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