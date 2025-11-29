/**
 * Auto Archiver - 通知按鈕處理
 * 支援 Nextcloud 32 的兩種通知模式：
 * 1. Toast Notification (彈出式通知)
 * 2. Notification List (通知列表頁面)
 */

document.addEventListener('DOMContentLoaded', function() {
	console.log('[AutoArchiver] Notification handler loaded');
	
	// 使用事件委派，監聽整個文檔的點擊
	document.addEventListener('click', function(event) {
		// 支援通知元素（使用實際的 DOM 屬性）
		const notification = event.target.closest('.notification[data-app="auto_archiver"], .toast');
		if (!notification) {
			return;
		}
		
		// 確認是否為 auto_archiver 的通知
		const app = notification.getAttribute('data-app');
		const notificationText = notification.textContent || '';
		
		// 判斷是否為我們的通知
		if (app !== 'auto_archiver' && !notificationText.includes('will be archived')) {
			return;
		}
		
		console.log('[AutoArchiver] Auto Archiver notification detected:', notification.className);
		
		// 檢查是否已經添加過按鈕
		if (notification.querySelector('.auto-archiver-buttons')) {
			console.log('[AutoArchiver] Buttons already added, skipping');
			return;
		}
		
		// 獲取通知 ID（實際屬性名是 data-id，不是 data-notification-id）
		const notificationId = notification.getAttribute('data-id');
		console.log('[AutoArchiver] Notification ID:', notificationId);
		
		// 獲取 object type
		const objectType = notification.getAttribute('data-object-type');
		console.log('[AutoArchiver] Object Type:', objectType);
		
		// 通知沒有直接包含 fileId (object_id)，需要透過 API 查詢
		if (notificationId) {
			fetchFileIdFromNotification(notificationId, notification);
		} else {
			console.error('[AutoArchiver] No notification ID found');
		}
		
	}, true); // 使用捕獲模式確保早期攔截
	
	// 從通知 API 獲取 fileId
	function fetchFileIdFromNotification(notificationId, notification) {
		fetch('/ocs/v2.php/apps/notifications/api/v2/notifications/' + notificationId + '?format=json', {
			headers: {
				'requesttoken': OC.requestToken,
				'OCS-APIRequest': 'true'
			}
		})
		.then(res => res.json())
		.then(data => {
			console.log('[AutoArchiver] Notification details:', data);
			if (data.ocs && data.ocs.data && data.ocs.data.object_id) {
				const fileId = data.ocs.data.object_id;
				console.log('[AutoArchiver] Got fileId from API:', fileId);
				addButtonsToNotification(notification, fileId);
			} else {
				console.error('[AutoArchiver] Failed to get fileId from API');
			}
		})
		.catch(error => {
			console.error('[AutoArchiver] Error fetching notification details:', error);
		});
	}
	
	// 添加按鈕到通知
	function addButtonsToNotification(notification, fileId) {
		// 找到通知的內容區域（支援多種 DOM 結構）
		const messageElement = notification.querySelector('.notification__message') || 
		                       notification.querySelector('.toast__message') ||
		                       notification.querySelector('.notification__content') ||
		                       notification.querySelector('.toast__content') ||
		                       notification.querySelector('.notification-content') ||
		                       notification.querySelector('.toast-content') ||
		                       notification;
		
		console.log('[AutoArchiver] Message element found:', messageElement.className || 'root element');
		
		// 創建按鈕容器
		const buttonContainer = document.createElement('div');
		buttonContainer.className = 'auto-archiver-buttons';
		buttonContainer.style.cssText = 'margin-top: 10px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;';
		
		// 創建「延長 7 天」按鈕
		const extendButton = document.createElement('button');
		extendButton.textContent = '延長 7 天';
		extendButton.className = 'primary';
		extendButton.style.cssText = 'padding: 6px 14px; background-color: #0082c9; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 13px;';
		
		extendButton.onclick = function(e) {
			e.preventDefault();
			e.stopPropagation();
			handleExtend7Days(fileId, notification);
		};
		
		// 創建「忽略」按鈕
		const dismissButton = document.createElement('button');
		dismissButton.textContent = '忽略';
		dismissButton.style.cssText = 'padding: 6px 14px; background-color: #f0f0f0; color: #333; border: none; border-radius: 3px; cursor: pointer; font-size: 13px;';
		
		dismissButton.onclick = function(e) {
			e.preventDefault();
			e.stopPropagation();
			handleDismiss(fileId, notification);
		};
		
		// 添加按鈕到容器
		buttonContainer.appendChild(extendButton);
		buttonContainer.appendChild(dismissButton);
		
		// 添加容器到通知
		messageElement.appendChild(buttonContainer);
		
		console.log('[AutoArchiver] Buttons added successfully');
	}
	
	// 處理「延長 7 天」
	function handleExtend7Days(fileId, notification) {
		console.log('[AutoArchiver] Extending file:', fileId);
		
		const buttons = notification.querySelectorAll('.auto-archiver-buttons button');
		buttons.forEach(btn => btn.disabled = true);
		
		// 使用標準路由（不是 OCS 路由）
		const url = OC.generateUrl('/apps/auto_archiver/extend7days/{fileId}', { fileId: fileId });
		console.log('[AutoArchiver] API URL:', url);
		
		fetch(url, {
			method: 'POST',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/json'
			}
		})
		.then(res => {
			console.log('[AutoArchiver] Response status:', res.status);
			return res.json();
		})
		.then(data => {
			console.log('[AutoArchiver] Extend response:', data);
			
			if (data.success) {
				OC.Notification.showTemporary('文件保留期限已延長 7 天');
				removeNotification(notification);
			} else {
				OC.Notification.showTemporary('操作失敗：' + (data.message || data.error || '未知錯誤'));
				buttons.forEach(btn => btn.disabled = false);
			}
		})
		.catch(error => {
			console.error('[AutoArchiver] Extend error:', error);
			OC.Notification.showTemporary('操作失敗：' + error.message);
			buttons.forEach(btn => btn.disabled = false);
		});
	}
	
	// 處理「忽略」
	function handleDismiss(fileId, notification) {
		console.log('[AutoArchiver] Dismissing notification:', fileId);
		
		const buttons = notification.querySelectorAll('.auto-archiver-buttons button');
		buttons.forEach(btn => btn.disabled = true);
		
		// 使用標準路由（不是 OCS 路由）
		const url = OC.generateUrl('/apps/auto_archiver/dismiss/{fileId}', { fileId: fileId });
		console.log('[AutoArchiver] API URL:', url);
		
		fetch(url, {
			method: 'DELETE',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/json'
			}
		})
		.then(res => {
			console.log('[AutoArchiver] Response status:', res.status);
			return res.json();
		})
		.then(data => {
			console.log('[AutoArchiver] Dismiss response:', data);
			
			if (data.success) {
				OC.Notification.showTemporary('已忽略通知');
				removeNotification(notification);
			} else {
				OC.Notification.showTemporary('操作失敗：' + (data.message || data.error || '未知錯誤'));
				buttons.forEach(btn => btn.disabled = false);
			}
		})
		.catch(error => {
			console.error('[AutoArchiver] Dismiss error:', error);
			OC.Notification.showTemporary('操作失敗：' + error.message);
			buttons.forEach(btn => btn.disabled = false);
		});
	}
	
	// 移除通知
	function removeNotification(notification) {
		// 使用正確的屬性名：data-id（不是 data-notification-id）
		const notificationId = notification.getAttribute('data-id');
		
		if (notificationId) {
			fetch('/ocs/v2.php/apps/notifications/api/v2/notifications/' + notificationId, {
				method: 'DELETE',
				headers: {
					'requesttoken': OC.requestToken,
					'OCS-APIRequest': 'true'
				}
			})
			.then(() => {
				console.log('[AutoArchiver] Notification removed');
				notification.remove();
			})
			.catch(error => {
				console.error('[AutoArchiver] Remove error:', error);
				notification.remove();
			});
		} else {
			notification.remove();
		}
	}
});
