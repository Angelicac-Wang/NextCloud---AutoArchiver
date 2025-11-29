# Auto Archiver - 開發者完全指南

> 📘 本手冊專為開發者和測試人員設計，提供最友善的新手指引，涵蓋環境設置、功能測試、問題排查的完整流程。

**文檔版本**：v2.0.0  
**最後更新**：2025-11-28

---

## 📑 目錄

1. [🚀 快速開始（10分鐘入門）](#-快速開始10分鐘入門)
2. [🛠️ 環境設置](#-環境設置)
3. [🎯 核心功能測試](#-核心功能測試)
   - [測試 1：自動封存舊檔案](#測試-1自動封存舊檔案)
   - [測試 2：檔案恢復功能](#測試-2檔案恢復功能)
   - [測試 3：儲存空間監控](#測試-3儲存空間監控)
   - [測試 4：通知系統與「留宿宮中」功能](#測試-4通知系統與留宿宮中功能)
   - [測試 5：資料夾過濾](#測試-5資料夾過濾)
4. [💡 功能詳解](#-功能詳解)
5. [🔍 調試與排查](#-調試與排查)
6. [📚 快速參考手冊](#-快速參考手冊)

---

## 🚀 快速開始（10分鐘入門）

### 第一次使用？跟著這些步驟立即體驗！

```bash
# 步驟 1：啟動 Nextcloud 環境
cd /path/to/your/project
docker compose up -d

# 步驟 2：等待容器啟動（約30秒），然後開啟瀏覽器
# 訪問 http://localhost:8080
# 創建管理員帳號：admin / admin

# 步驟 3：啟用 Auto Archiver 應用
docker compose exec app php occ app:enable auto_archiver

# 步驟 4：驗證安裝
docker compose exec app php occ app:list | grep auto_archiver
# 應該顯示：auto_archiver    0.1.9      enabled

# 🎉 完成！現在可以開始測試功能了
```

### 你的第一個測試：模擬檔案封存

```bash
# 1. 在 Nextcloud Web UI 中上傳一個測試檔案（例如：test.txt）

# 2. 查詢檔案 ID
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT fileid, path FROM oc_filecache WHERE path LIKE '%test.txt%';"
# 記下 fileid（假設是 123）

# 3. 模擬這個檔案 31 天前被訪問（超過封存閾值）
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 31 DAY)) WHERE file_id = 123;"

# 4. 找到封存任務的 Job ID
docker compose exec app php occ background-job:list | grep ArchiveOldFiles
# 記下 ID（假設是 117）

# 5. 執行封存任務
docker compose exec app php occ background-job:execute 117 --force-execute

# 6. 檢查結果
# - 在 Web UI 的 Archive 資料夾中應該能看到 test.txt.zip
# - 原位置會出現 test.txt.ncarchive 占位符
```

**✅ 恭喜！你已經完成第一個測試。** 繼續閱讀了解更多功能。

---

## 🛠️ 環境設置

### 前置需求

| 工具 | 版本需求 | 檢查指令 |
|------|----------|----------|
| Docker | 20.10+ | `docker --version` |
| Docker Compose | 2.0+ | `docker compose version` |
| Git | 2.0+ | `git --version` |
| 瀏覽器 | Chrome/Edge/Firefox 最新版 | - |

### 專案結構說明

```
NextCloud---AutoArchiver/
├── docker-compose.yml          # Docker 服務配置
├── my-apps/
│   └── auto_archiver/          # 應用程式主目錄
│       ├── appinfo/
│       │   ├── info.xml        # 應用程式資訊（版本、作者等）
│       │   └── routes.php      # API 路由定義
│       ├── lib/
│       │   ├── Cron/           # 背景任務（封存、監控、通知）
│       │   ├── Controller/     # API 控制器
│       │   ├── Migration/      # 資料庫遷移
│       │   ├── Notification/   # 通知系統
│       │   └── AppInfo/        # 應用程式註冊
│       └── js/
│           ├── script.js       # 檔案恢復 UI
│           └── notification.js # 通知按鈕 UI
└── DEVELOPER_GUIDE joe.md      # 本文檔
```

### 完整安裝步驟

#### 步驟 1：克隆專案（如果還沒有）

```bash
git clone <your-repository-url>
cd NextCloud---AutoArchiver
```

#### 步驟 2：啟動 Docker 容器

```bash
# 啟動服務（包括 Nextcloud 和 MariaDB）
docker compose up -d

# 查看容器狀態
docker compose ps
# 應該看到：
# NAME     IMAGE            STATUS
# app      nextcloud:latest Up
# db       mariadb:latest   Up

# 查看啟動日誌（確保沒有錯誤）
docker compose logs -f app
# 按 Ctrl+C 退出日誌查看
```

#### 步驟 3：初始化 Nextcloud（僅首次）

1. 打開瀏覽器，訪問 `http://localhost:8080`
2. 等待初始化頁面載入（約 10-30 秒）
3. 創建管理員帳號：
   - **使用者名稱**：`admin`
   - **密碼**：`admin`（測試環境用，生產環境請使用強密碼）
4. 資料庫配置會自動從 `docker-compose.yml` 讀取，無需手動配置
5. 點擊「完成設置」，等待初始化完成（約 1-2 分鐘）

#### 步驟 4：啟用 Auto Archiver 應用

```bash
# 確認應用程式已掛載到容器
docker compose exec app ls -la /var/www/html/custom_apps/ | grep auto_archiver
# 應該看到 auto_archiver 目錄

# 啟用應用程式
docker compose exec app php occ app:enable auto_archiver

# 驗證應用已啟用
docker compose exec app php occ app:list | grep auto_archiver
# 應該顯示：auto_archiver    0.1.9      enabled（版本號可能不同）
```

#### 步驟 5：驗證環境

```bash
# 檢查 Nextcloud 狀態
docker compose exec app php occ status
# 應該顯示：
#   - installed: true
#   - version: ...
#   - versionstring: ...

# 檢查資料庫連接
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SHOW TABLES LIKE 'oc_auto_archiver%';"
# 應該看到：
#   oc_auto_archiver_access

docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SHOW TABLES LIKE 'oc_archiver%';"
# 應該看到：
#   oc_archiver_decisions

# 檢查背景任務是否已註冊
docker compose exec app php occ background-job:list | grep -i archiver
# 應該看到：
#   - OCA\AutoArchiver\Cron\ArchiveOldFiles (ID: 117 或其他)
#   - OCA\AutoArchiver\Cron\StorageMonitorJob (ID: 118 或其他)
#   - OCA\AutoArchiver\Cron\NotificationJob (ID: 125 或其他)
```

✅ **環境設置完成！** 現在可以開始測試功能了。

---

## 🎯 核心功能測試

> 💡 **測試建議**：每個測試都是獨立的，可以按任意順序執行。每個測試包含完整的準備、執行、驗證、清理步驟。

---

### 測試 1：自動封存舊檔案

#### 🎯 測試目標

驗證系統能自動封存超過 30 天未存取的檔案，並在原位置創建占位符。

#### 📋 前置準備

**步驟 1.1：上傳測試檔案**

```bash
# 方法 A：通過 Web UI 上傳
# 1. 打開 http://localhost:8080
# 2. 登入（admin / admin）
# 3. 上傳一個檔案（例如：old_file.txt）

# 方法 B：通過命令行創建
docker compose exec app bash -c "echo 'This is a test file' > /var/www/html/data/admin/files/old_file.txt"
docker compose exec app php occ files:scan admin
```

**步驟 1.2：查詢檔案 ID**

```bash
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT fileid, path FROM oc_filecache WHERE path LIKE '%old_file.txt%';"

# 輸出示例：
# +--------+----------------------------+
# | fileid | path                       |
# +--------+----------------------------+
# |    512 | files/old_file.txt         |
# +--------+----------------------------+

# 記下 fileid（假設是 512）
```

**步驟 1.3：模擬舊檔案（修改最後訪問時間為 31 天前）**

```bash
# 將 file_id=512 的最後訪問時間設為 31 天前
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 31 DAY)) WHERE file_id = 512;"

# 驗證修改成功
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT file_id, FROM_UNIXTIME(last_accessed) as last_accessed FROM oc_auto_archiver_access WHERE file_id = 512;"

# 輸出示例：
# +---------+---------------------+
# | file_id | last_accessed       |
# +---------+---------------------+
# |     512 | 2024-10-28 10:00:00 | （31天前的時間）
# +---------+---------------------+
```

#### ▶️ 執行測試

**步驟 2.1：找到封存任務的 Job ID**

```bash
docker compose exec app php occ background-job:list | grep ArchiveOldFiles

# 輸出示例：
#   - OCA\AutoArchiver\Cron\ArchiveOldFiles (ID: 117, last run: ...)

# 記下 Job ID（假設是 117）
```

**步驟 2.2：執行封存任務**

```bash
# 使用 --force-execute 強制立即執行
docker compose exec app php occ background-job:execute 117 --force-execute
```

#### ✅ 驗證結果

**步驟 3.1：查看執行日誌**

```bash
# 查看最近的封存日誌
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'archiv'"

# 應該看到類似的日誌：
# Archiving file: old_file.txt (file_id: 512)
# File archived successfully: old_file.txt.zip
# Placeholder created: old_file.txt.ncarchive
```

**步驟 3.2：檢查 Web UI**

1. 打開 Nextcloud Web UI (`http://localhost:8080`)
2. 在根目錄應該能看到：
   - 📁 **Archive** 資料夾
   - 📄 **old_file.txt.ncarchive**（占位符）
3. 進入 **Archive** 資料夾，應該能看到：
   - 🗜️ **old_file.txt.zip**

**步驟 3.3：檢查資料庫（訪問記錄已刪除）**

```bash
# 封存後的檔案應該從 oc_auto_archiver_access 中移除
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT * FROM oc_auto_archiver_access WHERE file_id = 512;"

# 應該顯示：Empty set（因為檔案已封存，記錄已刪除）
```

#### 🧹 清理測試資料

```bash
# 刪除測試檔案和資料夾（重新開始測試前執行）
# 在 Web UI 中手動刪除：
# - Archive 資料夾
# - old_file.txt.ncarchive

# 或使用命令行：
docker compose exec app bash -c "rm -rf /var/www/html/data/admin/files/Archive"
docker compose exec app bash -c "rm -f /var/www/html/data/admin/files/old_file.txt.ncarchive"
docker compose exec app php occ files:scan admin
```

#### ✅ 預期結果總結

- ✅ 超過 30 天未訪問的檔案被自動封存
- ✅ 原檔案被壓縮為 `.zip` 並移動到 `Archive` 資料夾
- ✅ 原位置創建 `.ncarchive` 占位符
- ✅ 資料庫中的訪問記錄被刪除
- ✅ 日誌中有完整的封存過程記錄

---

### 測試 2：檔案恢復功能

#### 🎯 測試目標

驗證使用者可以透過點擊占位符檔案來恢復已封存的檔案。

#### 📋 前置準備

**步驟 1：確保有已封存的檔案**

```bash
# 如果尚未執行「測試 1」，請先完成測試 1
# 確認以下檔案存在：
# - Archive/old_file.txt.zip
# - old_file.txt.ncarchive
```

#### ▶️ 執行測試

**步驟 2.1：在 Web UI 中點擊占位符**

1. 打開 Nextcloud Web UI (`http://localhost:8080`)
2. 找到 `old_file.txt.ncarchive` 檔案
3. **點擊** 該檔案
4. 應該彈出確認對話框：「是否恢復資料？」
5. 點擊「**確定**」

**步驟 2.2：使用瀏覽器開發者工具監控（可選）**

```javascript
// 按 F12 打開開發者工具，切換到 Console 標籤
// 點擊占位符後，應該看到：
// 🕵️ AutoArchiver v0.1.9 Loaded
// Restoring file: <file_id>
// File restored successfully
```

#### ✅ 驗證結果

**步驟 3.1：檢查 Web UI**

1. 原位置應該出現 `old_file.txt`（原始檔案已恢復）
2. 占位符 `old_file.txt.ncarchive` 已消失
3. `Archive` 資料夾中的 `old_file.txt.zip` 已消失

**步驟 3.2：查看恢復日誌**

```bash
docker compose exec app bash -c "tail -n 100 data/nextcloud.log | grep -i 'restore'"

# 應該看到：
# Restoring file from archive: old_file.txt.zip
# File restored successfully: old_file.txt
# Placeholder deleted: old_file.txt.ncarchive
```

**步驟 3.3：驗證檔案內容**

```bash
# 檢查恢復的檔案內容是否正確
docker compose exec app cat /var/www/html/data/admin/files/old_file.txt

# 應該顯示：This is a test file
```

#### ✅ 預期結果總結

- ✅ 點擊占位符彈出確認對話框
- ✅ 原始檔案從 ZIP 中恢復到原位置
- ✅ 占位符檔案自動刪除
- ✅ Archive 資料夾中的 ZIP 檔案自動刪除
- ✅ 恢復的檔案內容完整無損

---

### 測試 3：儲存空間監控

#### 🎯 測試目標

驗證系統能在儲存空間使用率超過 80% 時自動封存檔案以釋放空間。

#### 📋 前置準備

**步驟 1.1：檢查當前儲存使用率**

```bash
docker compose exec app php occ user:info admin

# 輸出示例：
# user_id: admin
# display_name: admin
# ...
# quota: 10 MB
# used: 2 MB (20%)  ← 當前使用率
```

**步驟 1.2：降低配額以便觸發閾值**

```bash
# 將配額設為 10MB（方便測試）
docker compose exec app php occ user:setting admin files quota "10 MB"

# 驗證配額已更改
docker compose exec app php occ user:info admin | grep -i quota
# 應該顯示：quota: 10 MB
```

**步驟 1.3：上傳大檔案使使用率超過 80%**

```bash
# 創建 9MB 的測試檔案（90% 使用率）
docker compose exec app bash -c "dd if=/dev/zero of=/var/www/html/data/admin/files/large_file_1.bin bs=1M count=3"
docker compose exec app bash -c "dd if=/dev/zero of=/var/www/html/data/admin/files/large_file_2.bin bs=1M count=3"
docker compose exec app bash -c "dd if=/dev/zero of=/var/www/html/data/admin/files/large_file_3.bin bs=1M count=3"

# 掃描檔案
docker compose exec app php occ files:scan admin

# 驗證使用率
docker compose exec app php occ user:info admin | grep -i used
# 應該顯示：used: 9 MB (90%)  ← 超過 80% 閾值
```

**步驟 1.4：模擬這些檔案為舊檔案**

```bash
# 獲取所有 .bin 檔案的 file_id
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT fileid, path FROM oc_filecache WHERE path LIKE '%.bin%';"

# 將所有 .bin 檔案的最後訪問時間設為 31 天前
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 31 DAY)) WHERE file_id IN (SELECT fileid FROM oc_filecache WHERE path LIKE '%.bin%');"
```

#### ▶️ 執行測試

**步驟 2.1：找到儲存監控任務的 Job ID**

```bash
docker compose exec app php occ background-job:list | grep StorageMonitor

# 輸出示例：
#   - OCA\AutoArchiver\Cron\StorageMonitorJob (ID: 118, last run: ...)

# 記下 Job ID（假設是 118）
```

**步驟 2.2：執行儲存監控任務**

```bash
docker compose exec app php occ background-job:execute 118 --force-execute
```

#### ✅ 驗證結果

**步驟 3.1：查看監控日誌**

```bash
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'storagemonitor'"

# 應該看到：
# StorageMonitor: User admin storage usage: 90% (threshold: 80%)
# StorageMonitor: Archiving files to reduce storage usage...
# Archiving file: large_file_1.bin
# Archiving file: large_file_2.bin
# ...
# StorageMonitor: Storage usage reduced to 15%
```

**步驟 3.2：檢查使用率是否降低**

```bash
docker compose exec app php occ user:info admin | grep -i used

# 應該顯示使用率已降低到 80% 以下
# 例如：used: 1 MB (10%)
```

**步驟 3.3：檢查封存結果**

```bash
# 檢查 Archive 資料夾中的檔案
docker compose exec app ls -lh /var/www/html/data/admin/files/Archive/

# 應該看到：
# large_file_1.bin.zip
# large_file_2.bin.zip
# large_file_3.bin.zip
```

#### 🧹 清理測試資料

```bash
# 恢復配額為無限制
docker compose exec app php occ user:setting admin files quota "none"

# 刪除測試檔案
docker compose exec app bash -c "rm -rf /var/www/html/data/admin/files/Archive"
docker compose exec app bash -c "rm -f /var/www/html/data/admin/files/*.ncarchive"
docker compose exec app php occ files:scan admin
```

#### ✅ 預期結果總結

- ✅ 系統檢測到儲存使用率超過 80%
- ✅ 自動封存最久未使用的檔案
- ✅ 持續封存直到使用率降到閾值以下
- ✅ 日誌中有完整的監控和封存記錄

---

### 測試 4：通知系統與「留宿宮中」功能

#### 🎯 測試目標

驗證系統能在檔案即將被封存前 7 天發送通知，並允許使用者延長保留期限。

#### 📋 前置準備

**步驟 1.1：清除舊的測試資料（重要！）**

```bash
# 清除所有通知和決策記錄
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "DELETE FROM oc_notifications WHERE app = 'auto_archiver'; DELETE FROM oc_archiver_decisions;"

# 驗證清除成功
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT COUNT(*) FROM oc_notifications WHERE app = 'auto_archiver';"
# 應該顯示：0
```

**步驟 1.2：上傳測試檔案**

```bash
# 方法 A：通過 Web UI 上傳一個檔案（例如：notice_test.txt）

# 方法 B：通過命令行創建
docker compose exec app bash -c "echo 'Test notification content' > /var/www/html/data/admin/files/notice_test.txt"
docker compose exec app php occ files:scan admin
```

**步驟 1.3：查詢檔案 ID**

```bash
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT fileid, path FROM oc_filecache WHERE path LIKE '%notice_test.txt%';"

# 輸出示例：
# +--------+----------------------------+
# | fileid | path                       |
# +--------+----------------------------+
# |    539 | files/notice_test.txt      |
# +--------+----------------------------+

# 記下 fileid（假設是 539）
```

**步驟 1.4：模擬即將被封存的檔案（23 天前訪問 = 距離封存還有 7 天）**

```bash
# 將 file_id=539 的最後訪問時間設為 23 天前
# 計算方式：30天閾值 - 23天 = 7天（符合通知條件）
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 23 DAY)) WHERE file_id = 539;"

# 驗證修改成功
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT file_id, FROM_UNIXTIME(last_accessed) as last_accessed, FLOOR((UNIX_TIMESTAMP() - last_accessed) / 86400) as days_ago FROM oc_auto_archiver_access WHERE file_id = 539;"

# 輸出示例：
# +---------+---------------------+----------+
# | file_id | last_accessed       | days_ago |
# +---------+---------------------+----------+
# |     539 | 2024-11-05 10:00:00 |       23 |
# +---------+---------------------+----------+
```

#### ▶️ 執行測試

**步驟 2.1：找到通知任務的 Job ID**

```bash
docker compose exec app php occ background-job:list | grep -i notification

# 輸出示例：
#   - OCA\AutoArchiver\Cron\NotificationJob (ID: 125, last run: ...)

# 記下 Job ID（假設是 125）
```

**步驟 2.2：執行通知任務**

```bash
# 使用 --force-execute 強制立即執行
docker compose exec app php occ background-job:execute 125 --force-execute
```

#### ✅ 驗證結果（後端）

**步驟 3.1：查看通知任務日誌**

```bash
docker compose exec app bash -c "tail -n 100 data/nextcloud.log | grep -i 'AutoArchiver\|notificationjob'"

# 應該看到：
# [AutoArchiver] NotificationJob: Checking files for notification...
# [AutoArchiver] Sending notification for file: notice_test.txt (file_id: 539, days until archive: 7)
# [AutoArchiver] Notification sent successfully
```

**步驟 3.2：檢查通知是否寫入資料庫**

```bash
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT notification_id, user, object_id, subject, subject_parameters FROM oc_notifications WHERE app = 'auto_archiver' ORDER BY notification_id DESC LIMIT 1;"

# 輸出示例：
# +------------------+-------+-----------+-------------------+------------------------------------------------+
# | notification_id  | user  | object_id | subject           | subject_parameters                             |
# +------------------+-------+-----------+-------------------+------------------------------------------------+
# |               32 | admin |       539 | file_will_archive | {"file":"notice_test.txt","days":7}            |
# +------------------+-------+-----------+-------------------+------------------------------------------------+
```

**步驟 3.3：檢查決策記錄是否創建**

```bash
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT file_id, user_id, decision, FROM_UNIXTIME(notified_at) as notified_at, file_path FROM oc_archiver_decisions WHERE file_id = 539;"

# 輸出示例：
# +---------+---------+----------+---------------------+-------------------+
# | file_id | user_id | decision | notified_at         | file_path         |
# +---------+---------+----------+---------------------+-------------------+
# |     539 | admin   | pending  | 2024-11-28 10:00:00 | notice_test.txt   |
# +---------+---------+----------+---------------------+-------------------+
```

✅ **後端驗證完成！** 通知已成功發送並寫入資料庫。

#### ✅ 驗證結果（前端 UI）

**步驟 4.1：清除瀏覽器緩存（重要！）**

```
1. 按 Ctrl+Shift+Delete（Windows/Linux）或 Cmd+Shift+Delete（Mac）
2. 選擇「圖片和檔案」或「緩存」
3. 時間範圍選擇「所有時間」
4. 點擊「清除資料」
5. **關閉瀏覽器並重新打開**
```

**步驟 4.2：打開開發者工具**

```
1. 打開 http://localhost:8080
2. 按 F12 打開開發者工具
3. 切換到 Console 標籤
4. 按 Ctrl+Shift+R 強制刷新頁面
```

**步驟 4.3：查看通知**

```
1. 點擊右上角的鈴鐺圖標（通知）
2. 應該看到通知：
   「File notice_test.txt will be archived in 7 days」
   「This file has not been accessed for a long time and will be archived in 7 days. Tap 延長 7 天 to keep it.」

3. 通知下方應該有兩個按鈕：
   - 🔵 [延長 7 天]（藍色按鈕）
   - ⚪ [忽略]（灰色按鈕）
```

**步驟 4.4：檢查 Console 日誌**

```javascript
// Console 應該顯示：
[AutoArchiver] Notification handler loaded
[AutoArchiver] Auto Archiver notification detected: notification
[AutoArchiver] Notification ID: 32
[AutoArchiver] Got fileId from API: 539
[AutoArchiver] Message element found: notification
[AutoArchiver] Buttons added successfully
```

✅ **前端驗證完成！** 通知和按鈕已正確顯示。

#### ▶️ 測試「延長 7 天」功能

**步驟 5.1：點擊「延長 7 天」按鈕**

```
1. 在通知中點擊「延長 7 天」按鈕
2. 按鈕應該變為 disabled 狀態（防止重複點擊）
3. 應該彈出成功訊息：「文件保留期限已延長 7 天」
4. 通知應該從通知列表中消失
```

**步驟 5.2：檢查 Console 日誌**

```javascript
// Console 應該顯示：
[AutoArchiver] Extending file: 539
[AutoArchiver] API URL: /apps/auto_archiver/extend7days/539
[AutoArchiver] Response status: 200
[AutoArchiver] Extend response: {success: true, message: "文件保留期限已延長7天"}
[AutoArchiver] Notification removed from Nextcloud API
```

**步驟 5.3：驗證 last_accessed 時間是否更新**

```bash
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT file_id, FROM_UNIXTIME(last_accessed) as last_accessed, FLOOR((UNIX_TIMESTAMP() - last_accessed) / 86400) as days_ago FROM oc_auto_archiver_access WHERE file_id = 539;"

# 輸出示例（last_accessed 應該更新為當前時間附近）：
# +---------+---------------------+----------+
# | file_id | last_accessed       | days_ago |
# +---------+---------------------+----------+
# |     539 | 2024-11-28 15:30:45 |        0 |  ← 已更新為當前時間！
# +---------+---------------------+----------+
```

**步驟 5.4：驗證決策記錄是否更新**

```bash
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT file_id, decision, FROM_UNIXTIME(decided_at) as decided_at FROM oc_archiver_decisions WHERE file_id = 539;"

# 輸出示例：
# +---------+--------------+---------------------+
# | file_id | decision     | decided_at          |
# +---------+--------------+---------------------+
# |     539 | extend_7_days| 2024-11-28 15:30:45 |  ← decision 已更新！
# +---------+--------------+---------------------+
```

✅ **延長功能驗證完成！** 檔案的保留期限已成功延長。

#### ▶️ 測試「忽略」功能（可選）

**步驟 6.1：重新生成通知（用於測試忽略功能）**

```bash
# 重置 last_accessed 為 23 天前
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 23 DAY)) WHERE file_id = 539;"

# 刪除舊的決策記錄
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "DELETE FROM oc_archiver_decisions WHERE file_id = 539; DELETE FROM oc_notifications WHERE app = 'auto_archiver' AND object_id = '539';"

# 重新執行通知任務
docker compose exec app php occ background-job:execute 125 --force-execute

# 刷新瀏覽器查看新通知
```

**步驟 6.2：點擊「忽略」按鈕**

```
1. 在通知中點擊「忽略」按鈕
2. 應該彈出訊息：「已忽略通知」
3. 通知應該從列表中消失
```

**步驟 6.3：驗證 last_accessed 未更新**

```bash
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT file_id, FROM_UNIXTIME(last_accessed) as last_accessed, FLOOR((UNIX_TIMESTAMP() - last_accessed) / 86400) as days_ago FROM oc_auto_archiver_access WHERE file_id = 539;"

# 輸出示例（last_accessed 仍然是 23 天前）：
# +---------+---------------------+----------+
# | file_id | last_accessed       | days_ago |
# +---------+---------------------+----------+
# |     539 | 2024-11-05 10:00:00 |       23 |  ← 未更新
# +---------+---------------------+----------+
```

**步驟 6.4：驗證決策記錄**

```bash
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT file_id, decision FROM oc_archiver_decisions WHERE file_id = 539;"

# 輸出示例：
# +---------+----------+
# | file_id | decision |
# +---------+----------+
# |     539 | ignore   |  ← 記錄為 ignore
# +---------+----------+
```

#### 🧹 清理測試資料

```bash
# 刪除測試檔案
docker compose exec app bash -c "rm -f /var/www/html/data/admin/files/notice_test.txt"
docker compose exec app php occ files:scan admin

# 清除通知和決策記錄
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "DELETE FROM oc_notifications WHERE app = 'auto_archiver'; DELETE FROM oc_archiver_decisions;"
```

#### ✅ 預期結果總結

- ✅ 檔案 23 天未訪問時，系統發送通知（距離封存還有 7 天）
- ✅ 通知在 Nextcloud 通知中心顯示
- ✅ 通知包含檔案名稱和剩餘天數
- ✅ 通知下方有「延長 7 天」和「忽略」按鈕
- ✅ 點擊「延長 7 天」後，`last_accessed` 更新為當前時間
- ✅ 點擊「忽略」後，記錄決策但不更新 `last_accessed`
- ✅ 所有操作都有完整的日誌記錄

---

### 測試 5：資料夾過濾

#### 🎯 測試目標

驗證系統只封存檔案，不封存資料夾（避免破壞資料夾結構）。

#### 📋 前置準備

**步驟 1.1：創建測試資料夾和檔案**

```bash
# 通過 Web UI 創建：
# 1. 創建資料夾：test_folder
# 2. 在 test_folder 內上傳檔案：test_file_in_folder.txt

# 或通過命令行：
docker compose exec app bash -c "mkdir -p /var/www/html/data/admin/files/test_folder"
docker compose exec app bash -c "echo 'File inside folder' > /var/www/html/data/admin/files/test_folder/test_file_in_folder.txt"
docker compose exec app php occ files:scan admin
```

**步驟 1.2：查詢資料夾和檔案的 ID**

```bash
# 查詢資料夾 ID（type = 2 表示資料夾）
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT fileid, path, mimetype FROM oc_filecache WHERE path LIKE '%test_folder%' AND mimetype = 2;"

# 輸出示例：
# +--------+----------------------+----------+
# | fileid | path                 | mimetype |
# +--------+----------------------+----------+
# |    600 | files/test_folder    |        2 |  ← 資料夾
# +--------+----------------------+----------+

# 查詢檔案 ID（type != 2 表示檔案）
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT fileid, path FROM oc_filecache WHERE path LIKE '%test_file_in_folder.txt%';"

# 輸出示例：
# +--------+-------------------------------------------+
# | fileid | path                                      |
# +--------+-------------------------------------------+
# |    601 | files/test_folder/test_file_in_folder.txt |
# +--------+-------------------------------------------+
```

**步驟 1.3：模擬資料夾和檔案為舊資料**

```bash
# 將資料夾的 last_accessed 設為 31 天前
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 31 DAY)) WHERE file_id = 600;"

# 將檔案的 last_accessed 設為 31 天前
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 31 DAY)) WHERE file_id = 601;"
```

#### ▶️ 執行測試

```bash
# 執行封存任務
docker compose exec app php occ background-job:execute 117 --force-execute
```

#### ✅ 驗證結果

**步驟 3.1：查看日誌（資料夾應該被跳過）**

```bash
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'folder\|skipped\|test_folder'"

# 應該看到：
# Skipping folder: test_folder (file_id: 600)  ← 資料夾被跳過
# Archiving file: test_file_in_folder.txt (file_id: 601)  ← 檔案被封存
```

**步驟 3.2：檢查 Web UI**

```
1. test_folder 資料夾仍然存在（未被封存）
2. test_folder 內應該有：
   - test_file_in_folder.txt.ncarchive（占位符）
3. Archive 資料夾內應該有：
   - test_file_in_folder.txt.zip
```

#### 🧹 清理測試資料

```bash
docker compose exec app bash -c "rm -rf /var/www/html/data/admin/files/test_folder"
docker compose exec app bash -c "rm -rf /var/www/html/data/admin/files/Archive"
docker compose exec app php occ files:scan admin
```

#### ✅ 預期結果總結

- ✅ 資料夾不會被封存（即使超過 30 天未訪問）
- ✅ 資料夾內的檔案可以正常被封存
- ✅ 資料夾結構保持完整
- ✅ 日誌中有「跳過資料夾」的記錄

---

## 💡 功能詳解

### 「留宿宮中」通知系統

#### 🔔 功能概述

「留宿宮中」是一個智能通知系統，在檔案即將被封存前主動提醒使用者，讓使用者決定是否延長保留期限。

**核心概念：**
- 📅 檔案 **30 天**未訪問 → 自動封存
- 🔔 檔案 **23 天**未訪問 → 發送通知（還有 7 天）
- ⏰ 使用者可選擇「延長 7 天」或「忽略通知」

#### 🚀 工作流程

```
Day 0: 使用者訪問檔案
   ↓
Day 23: NotificationJob 檢測到檔案即將被封存
   ↓
Day 23: 發送通知到 Nextcloud 通知中心
   ↓
Day 23-30: 使用者可以選擇：
   ├── 選項 A：點擊「延長 7 天」→ last_accessed 重設為當前時間 → 延長 30 天
   ├── 選項 B：點擊「忽略」→ 記錄決策，但不更新 last_accessed
   └── 選項 C：不做任何操作
   ↓
Day 30: ArchiveOldFiles 執行封存（如果使用者未延長）
```

#### 📊 資料庫表：oc_archiver_decisions

| 欄位 | 類型 | 說明 |
|------|------|------|
| `id` | bigint | 主鍵（自動遞增）|
| `file_id` | bigint | 檔案 ID（關聯 `oc_filecache.fileid`）|
| `user_id` | varchar(64) | 使用者 ID |
| `decision` | varchar(32) | 決策類型：`pending`, `extend_7_days`, `ignore`, `archive` |
| `notified_at` | bigint | 通知發送時間（Unix 時間戳）|
| `decided_at` | bigint | 決策時間（Unix 時間戳，可為 NULL）|
| `file_path` | varchar(4000) | 檔案路徑（用於記錄和統計）|

**決策類型說明：**
- `pending`：已發送通知，等待使用者決策
- `extend_7_days`：使用者選擇延長保留期限
- `ignore`：使用者選擇忽略通知
- `archive`：檔案已被自動封存（24 小時內重複通知檢查）

#### 🌐 API 端點

##### 1. 延長保留期限（Extend 7 Days）

```
POST /apps/auto_archiver/extend7days/{fileId}
```

**功能**：將檔案的最後訪問時間更新為當前時間，實際延長約 30 天保留期。

**請求示例：**
```bash
curl -X POST "http://localhost:8080/apps/auto_archiver/extend7days/539" \
  -H "requesttoken: <CSRF_TOKEN>" \
  -u admin:admin
```

**回應示例：**
```json
{
  "success": true,
  "message": "文件保留期限已延長7天",
  "newLastAccessed": 1732800645
}
```

##### 2. 忽略通知（Dismiss）

```
DELETE /apps/auto_archiver/dismiss/{fileId}
```

**功能**：記錄使用者選擇忽略通知，但不更新 `last_accessed`。

**請求示例：**
```bash
curl -X DELETE "http://localhost:8080/apps/auto_archiver/dismiss/539" \
  -H "requesttoken: <CSRF_TOKEN>" \
  -u admin:admin
```

**回應示例：**
```json
{
  "success": true,
  "message": "通知已忽略"
}
```

##### 3. 查看統計資料（Statistics）

```
GET /apps/auto_archiver/statistics
```

**功能**：查看當前使用者的決策統計。

**請求示例：**
```bash
curl -X GET "http://localhost:8080/apps/auto_archiver/statistics" \
  -H "OCS-APIRequest: true" \
  -u admin:admin
```

**回應示例：**
```json
{
  "success": true,
  "statistics": {
    "extend_7_days": 15,
    "ignore": 3,
    "archive": 8
  }
}
```

#### 🤖 背景任務：NotificationJob

- **執行頻率**：每小時一次（`protected $interval = 3600;`）
- **功能**：
  1. 掃描所有檔案的 `last_accessed` 時間
  2. 找出 23-29 天前訪問的檔案（距離封存還有 1-7 天）
  3. 檢查是否在 24 小時內已發送通知（避免重複）
  4. 發送通知到 Nextcloud 通知中心
  5. 記錄到 `oc_archiver_decisions` 表（decision = 'pending'）

**手動執行：**
```bash
# 找到 NotificationJob 的 ID
docker compose exec app php occ background-job:list | grep NotificationJob

# 執行任務（假設 ID 為 125）
docker compose exec app php occ background-job:execute 125 --force-execute
```

### 自動封存系統

#### 📦 功能概述

自動封存系統會定期掃描所有檔案，將超過 30 天未訪問的檔案壓縮並移動到 `Archive` 資料夾。

#### 🚀 工作流程

```
1. ArchiveOldFiles 每小時執行一次
   ↓
2. 掃描 oc_auto_archiver_access 表，找出 last_accessed >= 30 天的檔案
   ↓
3. 過濾：跳過資料夾、只處理檔案
   ↓
4. 對每個符合條件的檔案：
   a. 壓縮為 ZIP
   b. 移動到 Archive 資料夾
   c. 在原位置創建 .ncarchive 占位符
   d. 刪除 oc_auto_archiver_access 記錄
   ↓
5. 完成
```

#### 🗜️ 壓縮和占位符

**ZIP 檔案內容：**
```
old_file.txt.zip
└── old_file.txt  (原始檔案)
```

**占位符檔案內容（JSON）：**
```json
{
  "original_path": "files/old_file.txt",
  "archive_path": "files/Archive/old_file.txt.zip",
  "archived_at": 1732800645,
  "original_size": 1024,
  "mime_type": "text/plain"
}
```

#### 🤖 背景任務：ArchiveOldFiles

- **執行頻率**：每小時一次
- **封存閾值**：30 天（`ARCHIVE_THRESHOLD_DAYS = 30`）

**手動執行：**
```bash
docker compose exec app php occ background-job:execute 117 --force-execute
```

---

### 儲存空間監控系統

#### 💾 功能概述

儲存空間監控系統會定期檢查使用者的儲存使用率，當超過閾值（預設 80%）時，自動封存最久未使用的檔案以釋放空間。

#### 🚀 工作流程

```
1. StorageMonitorJob 每小時執行一次
   ↓
2. 計算使用者儲存使用率（已使用 / 配額）
   ↓
3. 如果使用率 >= 80%：
   a. 從 oc_auto_archiver_access 中找出最久未訪問的檔案
   b. 逐一封存檔案
   c. 每封存一個檔案後重新計算使用率
   d. 持續封存直到使用率 < 80%
   ↓
4. 完成
```

#### 🤖 背景任務：StorageMonitorJob

- **執行頻率**：每小時一次
- **使用率閾值**：80%（`STORAGE_THRESHOLD = 0.80`）

**手動執行：**
```bash
docker compose exec app php occ background-job:execute 118 --force-execute
```

---

### 檔案恢復系統

#### 🔄 功能概述

使用者可以透過點擊 `.ncarchive` 占位符來恢復已封存的檔案。

#### 🚀 工作流程

```
1. 使用者在 Web UI 中點擊 .ncarchive 檔案
   ↓
2. JavaScript 攔截點擊事件
   ↓
3. 彈出確認對話框：「是否恢復資料？」
   ↓
4. 使用者點擊「確定」
   ↓
5. 發送 POST 請求到 /apps/auto_archiver/restore/{fileId}
   ↓
6. RestoreController 處理請求：
   a. 讀取占位符檔案，獲取 archive_path
   b. 解壓 ZIP 檔案到原位置
   c. 刪除 ZIP 檔案和占位符
   d. 重新掃描檔案系統
   ↓
7. 返回成功訊息
```

#### 🌐 API 端點

```
POST /apps/auto_archiver/restore/{fileId}
```

**前端 JavaScript（script.js）：**
- 監聽 `.ncarchive` 檔案的點擊事件
- 使用 `OC.dialogs.confirm()` 顯示確認對話框
- 使用 `fetch()` 調用 API
- 成功後刷新檔案列表

---

## 🔍 調試與排查

### 📊 日誌查看

#### 查看所有 Auto Archiver 日誌

```bash
# 實時查看
docker compose exec app tail -f data/nextcloud.log | grep -i "autoarchiver\|archiver"

# 查看最近 200 行
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'autoarchiver\|archiver'"
```

#### 查看特定功能的日誌

```bash
# 封存任務日誌
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'archiveoldfiles\|archiving'"

# 通知任務日誌
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'notificationjob'"

# 儲存監控日誌
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'storagemonitor'"

# 恢復功能日誌
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'restore'"
```

#### 啟用調試模式

```bash
# 設定日誌等級為 Debug (0 = 最詳細)
docker compose exec app php occ config:system:set loglevel --value=0

# 恢復為預設等級 (2 = Warning)
docker compose exec app php occ config:system:set loglevel --value=2
```

---

### 🔧 常見問題排查

#### 問題 1：應用程式無法啟用

**症狀：**
```bash
$ docker compose exec app php occ app:enable auto_archiver
Error: App "auto_archiver" cannot be enabled...
```

**排查步驟：**

```bash
# 1. 檢查應用程式目錄是否存在
docker compose exec app ls -la /var/www/html/custom_apps/ | grep auto_archiver

# 2. 檢查應用程式結構
docker compose exec app ls -la /var/www/html/custom_apps/auto_archiver/appinfo/

# 3. 檢查 info.xml 語法
docker compose exec app php occ app:check-code auto_archiver

# 4. 查看詳細錯誤
docker compose exec app php occ app:enable auto_archiver -vvv
```

**常見原因：**
- ❌ `info.xml` 中 `max-version` 不支援當前 Nextcloud 版本
- ❌ 應用程式目錄權限問題
- ❌ `info.xml` 語法錯誤

**解決方案：**
```bash
# 修改 info.xml 中的 max-version
# 編輯 my-apps/auto_archiver/appinfo/info.xml
# <nextcloud min-version="28" max-version="32"/>

# 重新啟用
docker compose exec app php occ app:disable auto_archiver
docker compose exec app php occ app:enable auto_archiver
```

---

#### 問題 2：背景任務不執行

**症狀：**
```
執行 background-job:execute 時顯示：
Job was not executed because it is not due
```

**排查步驟：**

```bash
# 1. 檢查任務列表
docker compose exec app php occ background-job:list | grep -i archiver

# 2. 查看上次執行時間
docker compose exec app php occ background-job:list | grep -A 1 "ArchiveOldFiles"
# 輸出示例：
#   - OCA\AutoArchiver\Cron\ArchiveOldFiles (ID: 117)
#     last run: 2024-11-28 14:00:00 UTC

# 3. 檢查 Cron 配置
docker compose exec app php occ config:app:get core backgroundjobs_mode
# 應該顯示：cron 或 ajax 或 webcron
```

**解決方案：**

```bash
# 方案 A：使用 --force-execute 強制執行
docker compose exec app php occ background-job:execute 117 --force-execute

# 方案 B：重置 last_run 時間（讓任務變為 "due"）
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "UPDATE oc_jobs SET last_run = 0 WHERE id = 117;"

# 然後再執行（無需 --force-execute）
docker compose exec app php occ background-job:execute 117
```

---

#### 問題 3：通知沒有出現或按鈕沒有顯示

**症狀：**
- 執行 NotificationJob 後，Web UI 沒有顯示通知
- 或通知顯示但沒有「延長 7 天」和「忽略」按鈕

**完整診斷流程：**

##### 步驟 1：檢查後端是否發送通知

```bash
# 1.1 查看 NotificationJob 日誌
docker compose exec app bash -c "tail -n 100 data/nextcloud.log | grep -i 'notificationjob'"

# 應該看到：
# [AutoArchiver] NotificationJob: Checking files...
# [AutoArchiver] Sending notification for file: notice_test.txt

# 1.2 檢查通知是否寫入資料庫
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT notification_id, user, object_id, subject, subject_parameters FROM oc_notifications WHERE app = 'auto_archiver' ORDER BY notification_id DESC LIMIT 3;"

# 應該有記錄：
# +------------------+-------+-----------+-------------------+-----------------------------------+
# | notification_id  | user  | object_id | subject           | subject_parameters                |
# +------------------+-------+-----------+-------------------+-----------------------------------+
# |               32 | admin |       539 | file_will_archive | {"file":"...","days":7}           |
# +------------------+-------+-----------+-------------------+-----------------------------------+
```

**如果資料庫沒有記錄：**
- ❌ NotificationJob 沒有正確執行
- ❌ 檔案的 `last_accessed` 不在 23-29 天範圍內
- ❌ 24 小時內已發送過通知（檢查 `oc_archiver_decisions` 表）

**解決方案：**
```bash
# 清除決策記錄（允許重新發送通知）
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "DELETE FROM oc_archiver_decisions WHERE file_id = 539;"

# 確認 last_accessed 在正確範圍
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 23 DAY)) WHERE file_id = 539;"

# 重新執行通知任務
docker compose exec app php occ background-job:execute 125 --force-execute
```

##### 步驟 2：檢查 Notifier 是否正確解析

```bash
# 2.1 檢查 Notifier 類別是否已註冊
docker compose exec app php occ app:list | grep auto_archiver
# 應該顯示：auto_archiver    0.1.9      enabled

# 2.2 測試 Notifier（可選，需要創建測試腳本）
# 查看日誌中是否有 "Notification was not parsed by any notifier" 錯誤
docker compose exec app bash -c "tail -n 100 data/nextcloud.log | grep -i 'notifier'"

# 如果有錯誤，說明 Notifier 未正確註冊或執行失敗
```

**解決方案：**
```bash
# 重新啟用應用（重新註冊 Notifier）
docker compose exec app php occ app:disable auto_archiver
docker compose exec app php occ app:enable auto_archiver
```

##### 步驟 3：檢查前端 JS 是否載入

```bash
# 3.1 檢查 App 版本（版本號影響 JS 緩存）
docker compose exec app php occ app:list | grep auto_archiver
# 應該顯示：auto_archiver    0.1.9      enabled

# 3.2 檢查 JS 檔案是否存在
docker compose exec app ls -lh /var/www/html/custom_apps/auto_archiver/js/
# 應該看到：
# notification.js
# script.js

# 3.3 查看 JS 版本（檢查第一行註解）
docker compose exec app bash -c "head -n 10 custom_apps/auto_archiver/js/notification.js"
```

**解決方案：**
```bash
# A. 增加 App 版本號強制更新 JS 緩存
# 編輯 my-apps/auto_archiver/appinfo/info.xml
# 將 <version>0.1.9</version> 改為 <version>0.2.0</version>

# B. 重新啟用應用
docker compose exec app php occ app:disable auto_archiver
docker compose exec app php occ app:enable auto_archiver

# C. 清除瀏覽器緩存（重要！）
# 按 Ctrl+Shift+Delete → 清除「圖片和檔案」→ 關閉並重新打開瀏覽器
```

##### 步驟 4：檢查前端 Console

**操作步驟：**
```
1. 打開 http://localhost:8080
2. 按 F12 打開開發者工具
3. 切換到 Console 標籤
4. 按 Ctrl+Shift+R 強制刷新頁面
5. 點擊鈴鐺圖標（通知）
```

**預期 Console 輸出：**
```javascript
[AutoArchiver] Notification handler loaded  ← JS 已載入
[AutoArchiver] Auto Archiver notification detected: notification  ← 檢測到通知
[AutoArchiver] Notification ID: 32  ← 通知 ID
[AutoArchiver] Got fileId from API: 539  ← 檔案 ID
[AutoArchiver] Message element found: notification  ← 找到訊息元素
[AutoArchiver] Buttons added successfully  ← 按鈕已添加
```

**如果沒有任何 `[AutoArchiver]` 日誌：**
- ❌ JavaScript 沒有載入
- ❌ 版本緩存問題

**解決方案：**
```bash
# 1. 完全清除瀏覽器緩存
#    Ctrl+Shift+Delete → 選擇「所有時間」→ 清除「圖片和檔案」
# 2. 關閉瀏覽器
# 3. 重新打開瀏覽器
# 4. 重新登入 Nextcloud
# 5. 按 Ctrl+Shift+R 強制刷新
```

##### 步驟 5：確認 DOM 結構

**在瀏覽器 Console 執行：**
```javascript
// 查找 auto_archiver 通知元素
const notification = document.querySelector('[data-app="auto_archiver"]');
if (notification) {
    console.log('✅ 找到通知元素');
    console.log('data-app:', notification.getAttribute('data-app'));
    console.log('data-id:', notification.getAttribute('data-id'));
    console.log('data-object-type:', notification.getAttribute('data-object-type'));
    console.log('是否已有按鈕:', notification.querySelector('.auto-archiver-buttons') ? '是' : '否');
} else {
    console.log('❌ 找不到通知元素，可能：');
    console.log('1. 通知沒有在資料庫中');
    console.log('2. 通知已被刪除');
    console.log('3. Notifier 解析失敗');
}
```

##### 完整重置步驟（當所有方法都失敗時）

```bash
# 1. 清除所有測試資料
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "DELETE FROM oc_notifications WHERE app = 'auto_archiver'; DELETE FROM oc_archiver_decisions;"

# 2. 增加 App 版本號
# 編輯 my-apps/auto_archiver/appinfo/info.xml
# 將 <version> 加 1

# 3. 重新啟用應用
docker compose exec app php occ app:disable auto_archiver
docker compose exec app php occ app:enable auto_archiver

# 4. 重置檔案訪問時間
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 23 DAY)) WHERE file_id = 539;"

# 5. 重新執行通知任務
docker compose exec app php occ background-job:execute 125 --force-execute

# 6. 完全清除瀏覽器緩存
# Ctrl+Shift+Delete → 所有時間 → 清除

# 7. 關閉瀏覽器並重新打開

# 8. 重新登入並檢查
```

---

#### 問題 4：按鈕點擊後出現 404 錯誤

**症狀：**
```javascript
// Console 顯示：
Failed to load resource: the server responded with a status of 404 (Not Found)
/apps/auto_archiver/api/v1/extend7days/539
```

**原因：**
- ❌ API 路由未正確定義
- ❌ 前端 JavaScript 中的 URL 錯誤

**排查步驟：**

```bash
# 1. 檢查 routes.php 中的路由定義
docker compose exec app cat /var/www/html/custom_apps/auto_archiver/appinfo/routes.php

# 應該包含：
# [
#     'name' => 'Notification#extend7Days',
#     'url' => '/extend7days/{fileId}',
#     'verb' => 'POST',
# ],
```

**解決方案：**

```bash
# 1. 確認 routes.php 正確（參考上面的範例）

# 2. 確認 notification.js 中的 URL 正確
docker compose exec app bash -c "cat custom_apps/auto_archiver/js/notification.js | grep -A 2 'generateUrl'"

# 應該看到：
# const url = OC.generateUrl('/apps/auto_archiver/extend7days/{fileId}', { fileId: fileId });

# 3. 重新啟用應用
docker compose exec app php occ app:disable auto_archiver
docker compose exec app php occ app:enable auto_archiver

# 4. 清除瀏覽器緩存
```

---

#### 問題 5：檔案無法封存

**症狀：**
- 執行 ArchiveOldFiles 後，符合條件的檔案沒有被封存

**排查步驟：**

```bash
# 1. 檢查檔案是否符合封存條件
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT file_id, FROM_UNIXTIME(last_accessed) as last_accessed, FLOOR((UNIX_TIMESTAMP() - last_accessed) / 86400) as days_ago FROM oc_auto_archiver_access ORDER BY days_ago DESC LIMIT 10;"

# 應該有 days_ago >= 30 的記錄

# 2. 檢查檔案是否存在
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT fc.fileid, fc.path, aa.last_accessed FROM oc_filecache fc JOIN oc_auto_archiver_access aa ON fc.fileid = aa.file_id WHERE aa.last_accessed < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY)) LIMIT 5;"

# 3. 查看封存任務日誌
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'archiveoldfiles\|archiving'"
```

**常見原因和解決方案：**

**原因 A：檔案實際上不存在（已被刪除）**
```bash
# 清理孤立記錄
# （此操作需要在 ArchiveOldFiles.php 中添加邏輯，或手動執行）
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "DELETE aa FROM oc_auto_archiver_access aa LEFT JOIN oc_filecache fc ON aa.file_id = fc.fileid WHERE fc.fileid IS NULL;"
```

**原因 B：檔案是資料夾（被跳過）**
```bash
# 檢查是否為資料夾（mimetype = 2）
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT fc.fileid, fc.path, fc.mimetype FROM oc_filecache fc JOIN oc_auto_archiver_access aa ON fc.fileid = aa.file_id WHERE aa.last_accessed < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY));"

# 如果 mimetype = 2，則是資料夾，會被跳過
```

**原因 C：Archive 資料夾創建失敗**
```bash
# 手動創建 Archive 資料夾
docker compose exec app bash -c "mkdir -p /var/www/html/data/admin/files/Archive"
docker compose exec app php occ files:scan admin

# 重新執行封存任務
docker compose exec app php occ background-job:execute 117 --force-execute
```

**原因 D：權限問題**
```bash
# 檢查檔案權限
docker compose exec app ls -la /var/www/html/data/admin/files/

# 修復權限（如果需要）
docker compose exec app chown -R www-data:www-data /var/www/html/data/
```

---

#### 問題 6：檔案恢復不工作

**症狀：**
- 點擊 `.ncarchive` 檔案沒有反應
- 或彈出對話框後點擊「確定」沒有恢復

**排查步驟：**

```bash
# 1. 檢查前端 JS 是否載入
# 按 F12 → Console，應該看到：
# 🕵️ AutoArchiver v0.1.9 Loaded

# 2. 檢查占位符檔案內容
docker compose exec app cat /var/www/html/data/admin/files/old_file.txt.ncarchive

# 應該是有效的 JSON：
# {"original_path":"files/old_file.txt","archive_path":"files/Archive/old_file.txt.zip",...}

# 3. 檢查 ZIP 檔案是否存在
docker compose exec app ls -lh /var/www/html/data/admin/files/Archive/

# 應該有對應的 .zip 檔案

# 4. 查看恢復日誌
docker compose exec app bash -c "tail -n 100 data/nextcloud.log | grep -i 'restore'"
```

**解決方案：**

```bash
# 如果 JS 未載入，清除瀏覽器緩存

# 如果 ZIP 檔案不存在，無法恢復（需要重新上傳檔案）

# 如果 API 請求失敗，檢查 routes.php 是否正確定義 restore 路由
```

---

## 📚 快速參考手冊

### 容器管理

```bash
# 啟動所有服務
docker compose up -d

# 停止所有服務
docker compose down

# 重啟 app 容器
docker compose restart app

# 查看容器狀態
docker compose ps

# 查看容器日誌
docker compose logs -f app
docker compose logs -f db

# 進入容器 Shell
docker compose exec app bash
docker compose exec db bash
```

---

### 應用程式管理

```bash
# 啟用應用
docker compose exec app php occ app:enable auto_archiver

# 禁用應用
docker compose exec app php occ app:disable auto_archiver

# 查看應用狀態
docker compose exec app php occ app:list | grep auto_archiver

# 檢查應用程式碼
docker compose exec app php occ app:check-code auto_archiver

# 查看 Nextcloud 狀態
docker compose exec app php occ status
```

---

### 背景任務管理

```bash
# 列出所有背景任務
docker compose exec app php occ background-job:list | grep -i archiver

# 執行 ArchiveOldFiles（封存任務）
docker compose exec app php occ background-job:execute 117 --force-execute

# 執行 StorageMonitorJob（儲存監控）
docker compose exec app php occ background-job:execute 118 --force-execute

# 執行 NotificationJob（通知任務）
docker compose exec app php occ background-job:execute 125 --force-execute

# 重置任務的 last_run 時間
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "UPDATE oc_jobs SET last_run = 0 WHERE id = 117;"
```

---

### 資料庫操作

#### 查詢檔案訪問記錄

```bash
# 查看所有訪問記錄
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT * FROM oc_auto_archiver_access ORDER BY last_accessed DESC LIMIT 10;"

# 查看特定檔案的訪問記錄
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT file_id, FROM_UNIXTIME(last_accessed) as last_accessed FROM oc_auto_archiver_access WHERE file_id = 539;"

# 查看超過 30 天未訪問的檔案
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT file_id, FROM_UNIXTIME(last_accessed) as last_accessed, FLOOR((UNIX_TIMESTAMP() - last_accessed) / 86400) as days_ago FROM oc_auto_archiver_access WHERE last_accessed < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY));"
```

#### 模擬舊檔案（測試用）

```bash
# 將特定檔案的訪問時間設為 31 天前（會被封存）
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 31 DAY)) WHERE file_id = 539;"

# 將特定檔案的訪問時間設為 23 天前（會收到通知）
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 23 DAY)) WHERE file_id = 539;"
```

#### 查詢通知記錄

```bash
# 查看所有 auto_archiver 的通知
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT notification_id, user, object_id, subject, subject_parameters FROM oc_notifications WHERE app = 'auto_archiver' ORDER BY notification_id DESC;"

# 查看特定使用者的通知
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT * FROM oc_notifications WHERE app = 'auto_archiver' AND user = 'admin';"
```

#### 查詢決策記錄

```bash
# 查看所有決策記錄
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT file_id, user_id, decision, FROM_UNIXTIME(notified_at) as notified_at, FROM_UNIXTIME(decided_at) as decided_at, file_path FROM oc_archiver_decisions ORDER BY notified_at DESC;"

# 統計決策類型
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT decision, COUNT(*) as count FROM oc_archiver_decisions WHERE user_id = 'admin' GROUP BY decision;"
```

#### 清除測試資料

```bash
# 清除所有通知和決策記錄
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "DELETE FROM oc_notifications WHERE app = 'auto_archiver'; DELETE FROM oc_archiver_decisions;"

# 清除特定檔案的記錄
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "DELETE FROM oc_notifications WHERE app = 'auto_archiver' AND object_id = '539'; DELETE FROM oc_archiver_decisions WHERE file_id = 539;"

# 清除所有訪問記錄（謹慎使用！）
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "DELETE FROM oc_auto_archiver_access;"
```

#### 查詢檔案資訊

```bash
# 根據檔案名稱查詢 file_id
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT fileid, path, size, mimetype FROM oc_filecache WHERE path LIKE '%notice_test.txt%';"

# 查詢所有檔案（排除資料夾）
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT fileid, path FROM oc_filecache WHERE mimetype != 2 LIMIT 10;"

# 查詢 Archive 資料夾中的檔案
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e \
  "SELECT fileid, path FROM oc_filecache WHERE path LIKE '%Archive%';"
```

---

### 使用者管理

```bash
# 查看使用者資訊（包含儲存使用率）
docker compose exec app php occ user:info admin

# 設定使用者配額
docker compose exec app php occ user:setting admin files quota "10 MB"

# 取消配額限制
docker compose exec app php occ user:setting admin files quota "none"

# 列出所有使用者
docker compose exec app php occ user:list
```

---

### 檔案系統管理

```bash
# 掃描所有使用者的檔案
docker compose exec app php occ files:scan --all

# 掃描特定使用者的檔案
docker compose exec app php occ files:scan admin

# 列出使用者的檔案
docker compose exec app ls -lh /var/www/html/data/admin/files/

# 刪除測試檔案
docker compose exec app bash -c "rm -rf /var/www/html/data/admin/files/Archive"
docker compose exec app bash -c "rm -f /var/www/html/data/admin/files/*.ncarchive"
```

---

### 日誌查看

```bash
# 實時查看所有 Auto Archiver 日誌
docker compose exec app tail -f data/nextcloud.log | grep -i "autoarchiver\|archiver"

# 查看最近 200 行日誌
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'autoarchiver'"

# 查看特定功能的日誌
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'notificationjob'"
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'archiveoldfiles'"
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'storagemonitor'"
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'restore'"

# 設定日誌等級
docker compose exec app php occ config:system:set loglevel --value=0  # Debug
docker compose exec app php occ config:system:set loglevel --value=2  # Warning (預設)
```

---

### 完整測試流程（一鍵複製）

#### 測試封存功能

```bash
# 1. 上傳測試檔案
docker compose exec app bash -c "echo 'Test content' > /var/www/html/data/admin/files/test.txt"
docker compose exec app php occ files:scan admin

# 2. 獲取 file_id
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SELECT fileid FROM oc_filecache WHERE path LIKE '%test.txt%';"
# 假設得到 file_id = 123

# 3. 模擬 31 天前訪問
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 31 DAY)) WHERE file_id = 123;"

# 4. 執行封存
docker compose exec app php occ background-job:execute 117 --force-execute

# 5. 檢查結果
docker compose exec app ls -lh /var/www/html/data/admin/files/Archive/
docker compose exec app ls -lh /var/www/html/data/admin/files/ | grep ncarchive
```

#### 測試通知功能

```bash
# 1. 清除舊資料
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "DELETE FROM oc_notifications WHERE app = 'auto_archiver'; DELETE FROM oc_archiver_decisions;"

# 2. 上傳測試檔案
docker compose exec app bash -c "echo 'Notification test' > /var/www/html/data/admin/files/notice.txt"
docker compose exec app php occ files:scan admin

# 3. 獲取 file_id
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SELECT fileid FROM oc_filecache WHERE path LIKE '%notice.txt%';"
# 假設得到 file_id = 456

# 4. 模擬 23 天前訪問
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 23 DAY)) WHERE file_id = 456;"

# 5. 執行通知任務
docker compose exec app php occ background-job:execute 125 --force-execute

# 6. 檢查通知
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SELECT * FROM oc_notifications WHERE app = 'auto_archiver';"

# 7. 在瀏覽器中查看（記得清除緩存）
# http://localhost:8080 → 點擊鈴鐺圖標
```

---

## 🎓 測試檢查清單

在提交或部署前，請確認以下測試都通過：

- [ ] **環境設置**：Docker 容器正常啟動，應用程式成功啟用
- [ ] **自動封存**：模擬舊檔案，執行封存，檔案正確壓縮並移動
- [ ] **檔案恢復**：點擊占位符，檔案成功恢復
- [ ] **儲存監控**：降低配額，觸發監控，自動封存釋放空間
- [ ] **通知系統**：模擬即將到期檔案，成功發送通知
- [ ] **延長期限**：點擊「延長 7 天」按鈕，`last_accessed` 正確更新
- [ ] **忽略通知**：點擊「忽略」按鈕，決策正確記錄
- [ ] **資料夾過濾**：資料夾不被封存，資料夾內檔案可封存
- [ ] **日誌輸出**：所有操作都有清晰的日誌記錄
- [ ] **錯誤處理**：測試異常情況（空間不足、檔案不存在等）

---

## 📖 相關資源

- [Nextcloud 開發者文件](https://docs.nextcloud.com/server/latest/developer_manual/)
- [Nextcloud App 開發教學](https://docs.nextcloud.com/server/latest/developer_manual/app_development/)
- [Nextcloud Notification API](https://docs.nextcloud.com/server/latest/developer_manual/basics/notifications.html)
- [Docker Compose 文件](https://docs.docker.com/compose/)
- [MariaDB 文件](https://mariadb.com/kb/en/documentation/)

---

## 📝 版本歷史

### v2.0.0 (2025-11-28)
- 📘 完全重構開發者指南
- 🎯 優化測試流程，移除重複內容
- 📊 新增完整的排查診斷流程
- 🔍 新增快速參考手冊
- ✅ 每個測試都包含完整的準備、執行、驗證、清理步驟
- 🆕 針對新手提供 10 分鐘快速入門

### v1.1.0 (2025-11-27)
- ✨ 新增「留宿宮中」通知系統
- 📧 封存前 7 天主動發送通知
- ⏰ 使用者可延長檔案保留期限
- 📊 記錄使用者決策並提供統計功能
- 🗄️ 新增 `oc_archiver_decisions` 資料表
- 🔧 新增 NotificationJob 後台任務
- 🌐 新增 NotificationController API 端點

### v1.0.0 (2025-11-27)
- 🎉 初始版本
- 📁 檔案存取追蹤功能
- 🗜️ 自動封存舊檔案
- 🔄 檔案恢復功能
- 💾 儲存空間監控
- 📊 完整的開發測試文檔

---

**🎉 恭喜！你已經完成了 Auto Archiver 開發者指南的閱讀。**

如有任何問題，請參考「調試與排查」章節，或查看日誌和資料庫以診斷問題。

**文檔版本**：v2.0.0  
**最後更新**：2025-11-28


<function_calls>
<invoke name="run_terminal_cmd">
<parameter name="command">echo "檔案正在重新生成..."