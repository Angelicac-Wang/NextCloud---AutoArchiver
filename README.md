# Auto Archiver - Nextcloud 自動封存應用

> 📖 **開發者請注意**：如果您是開發者，想要在本地環境中設置和測試此應用程式，請查看 [開發者使用手冊](./DEVELOPER_GUIDE.md)。

## 簡介

**Auto Archiver** 是一個 Nextcloud 應用程式，用於自動壓縮並封存長時間未使用的檔案，以節省儲存空間。當檔案被封存後，系統會在原始位置留下一個占位符檔案，使用者可以透過點擊占位符輕鬆恢復原始檔案。

### 主要用途

- **節省儲存空間**：自動壓縮並封存長期未使用的檔案
- **無縫體驗**：封存後的檔案在原位置留下占位符，不會造成使用者恐慌
- **一鍵恢復**：點擊占位符即可自動解壓縮並恢復檔案
- **智能監控**：自動追蹤檔案存取時間，只封存真正未使用的檔案

---

## 功能特性

### ✨ 核心功能

1. **自動追蹤檔案存取**
   - 監聽檔案讀取事件（`BeforeNodeReadEvent`）
   - 記錄每個檔案的最後存取時間
   - 過濾預覽、縮圖等非實際存取請求

2. **自動封存舊檔案**
   - 每天執行一次背景任務（Cron Job）
   - 封存超過 30 天未存取的檔案
   - 自動壓縮為 ZIP 格式
   - 移動到 `Archive` 資料夾
   - 在原位置創建 `.ncarchive` 占位符檔案

3. **智能檔案過濾**
   - **只封存檔案**：自動跳過資料夾，不進行封存
   - 確保資料夾結構完整性

4. **一鍵恢復功能**
   - 點擊 `.ncarchive` 占位符檔案
   - 顯示確認對話框
   - 自動解壓縮並恢復到原始位置
   - 刪除占位符和封存檔案

5. **儲存配額檢查**
   - 恢復前檢查使用者可用儲存空間
   - 計算解壓縮後所需空間
   - 空間不足時提前警告，避免恢復失敗
   - 支援無限制配額和使用者配額

6. **儲存空間監控（Storage Monitoring）**
   - 每小時自動檢查所有使用者的儲存空間使用率
   - 當使用率達到 80% 時，自動觸發封存流程
   - 優先封存最久未使用的檔案（按 `last_accessed` 排序）
   - 持續封存直到使用率降到 80% 以下
   - 確保伺服器穩定與空間最佳化

---

## 安裝說明

### 前置需求

- Nextcloud 28-31 版本
- PHP 7.4 或更高版本
- 啟用 Nextcloud 的 Cron 背景任務

### 安裝步驟

1. **將應用程式放置到 Nextcloud 應用目錄**
   ```bash
   # 假設 Nextcloud 安裝在 /var/www/html
   cp -r auto_archiver /var/www/html/custom_apps/
   ```

2. **啟用應用程式**
   ```bash
   # 使用 Nextcloud 命令列工具
   sudo -u www-data php occ app:enable auto_archiver
   ```

3. **執行資料庫遷移**
   ```bash
   # Nextcloud 會自動執行遷移，但也可以手動執行
   sudo -u www-data php occ upgrade
   ```

4. **驗證安裝**
   ```bash
   # 檢查應用程式狀態
   sudo -u www-data php occ app:list | grep auto_archiver
   ```

### 手動執行封存任務（測試用）

```bash
# 強制執行一次封存任務（用於測試）
sudo -u www-data php occ background:job:execute OCA\AutoArchiver\Cron\ArchiveOldFiles
```

---

## 配置說明

### 封存時間閾值

預設情況下，系統會封存**超過 30 天未存取**的檔案。

如需修改此設定，請編輯 `lib/Cron/ArchiveOldFiles.php`：

```php
// 在 run() 方法中修改
$days = 30;  // 改為您想要的天數，例如 60 天
$threshold = time() - ($days * 24 * 60 * 60);
```

### Cron 執行頻率

#### 1. 時間基礎封存任務（ArchiveOldFiles）

預設每天執行一次封存任務。如需修改，請編輯 `lib/Cron/ArchiveOldFiles.php`：

```php
// 在 __construct() 方法中修改
$this->setInterval(24 * 60 * 60);  // 24 小時（秒數）
// 例如：每 12 小時執行一次
// $this->setInterval(12 * 60 * 60);
```

#### 2. 儲存空間監控任務（StorageMonitorJob）

預設每小時執行一次監控任務。如需修改，請編輯 `lib/Cron/StorageMonitorJob.php`：

```php
// 在 __construct() 方法中修改
$this->setInterval(60 * 60);  // 1 小時（秒數）
// 例如：每 30 分鐘執行一次
// $this->setInterval(30 * 60);
```

### 儲存空間監控閾值

預設當儲存空間使用率達到 **80%** 時會觸發自動封存。如需修改，請編輯 `lib/Cron/StorageMonitorJob.php`：

```php
// 在類別開頭修改常數
private const STORAGE_THRESHOLD = 0.80;  // 80%
// 例如：改為 75%
// private const STORAGE_THRESHOLD = 0.75;
```

### Archive 資料夾位置

封存的檔案會被移動到使用者根目錄下的 `Archive` 資料夾。如果該資料夾不存在，系統會自動創建。

---

## 使用手冊

### 對於一般使用者

#### 1. 檔案自動封存

- 系統會自動監控您的檔案存取情況
- 如果檔案超過 30 天未被存取，系統會自動：
  - 壓縮檔案為 ZIP 格式
  - 移動到 `Archive` 資料夾
  - 在原位置留下 `.ncarchive` 占位符檔案

#### 2. 識別封存檔案

封存後的檔案會顯示為：
- **檔案名稱**：`原檔案名稱.ncarchive`
- **圖示**：與原檔案相同，但副檔名為 `.ncarchive`

例如：
- 原始檔案：`document.pdf`
- 封存後：`document.pdf.ncarchive`

#### 3. 恢復封存檔案

1. **點擊占位符檔案**（`.ncarchive` 檔案）
2. **確認對話框**會顯示：
   ```
   此檔案已被封存以節省儲存空間。
   
   原始檔案名稱: document.pdf
   
   是否要恢復此檔案？恢復後檔案會自動解壓縮並回到原位置。
   ```
3. **點擊「確定」**開始恢復
4. 系統會：
   - 檢查您的儲存配額
   - 解壓縮檔案
   - 恢復到原始位置
   - 刪除占位符和封存檔案
   - 自動刷新頁面

#### 4. 儲存空間不足的情況

如果恢復檔案時空間不足，系統會顯示詳細錯誤訊息：

```
存儲空間不足！恢復此檔案需要 500 MB，但您只有 100 MB 可用空間。
請先刪除一些檔案或聯繫管理員增加配額。
```

**解決方法**：
- 刪除不需要的檔案
- 聯繫管理員增加儲存配額
- 清理 `Archive` 資料夾中的舊封存檔案

### 對於管理員

#### 查看封存日誌

```bash
# 查看 Nextcloud 日誌
tail -f /var/www/html/data/nextcloud.log | grep AutoArchiver
```

日誌會顯示：
- 封存任務執行時間
- 處理的檔案數量
- 成功封存的檔案數
- 跳過的資料夾數
- 錯誤訊息（如有）

#### 手動觸發封存任務

```bash
# 強制執行一次時間基礎封存任務（封存 30 天未使用的檔案）
sudo -u www-data php occ background:job:execute OCA\AutoArchiver\Cron\ArchiveOldFiles

# 強制執行一次儲存空間監控任務（檢查並封存超過 80% 使用率的用戶檔案）
sudo -u www-data php occ background:job:execute OCA\AutoArchiver\Cron\StorageMonitorJob
```

#### 查看資料庫記錄

```bash
# 連接到資料庫（根據您的設定調整）
mysql -u nextcloud_user -p nextcloud_db

# 查看所有追蹤的檔案
SELECT * FROM oc_auto_archiver_access ORDER BY last_accessed ASC;

# 查看即將被封存的檔案（30 天內未存取）
SELECT * FROM oc_auto_archiver_access 
WHERE last_accessed < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY));
```

#### 清除資料庫記錄（重新測試）

```bash
# 使用 PHP 腳本清除（推薦）
php -r "
require '/var/www/html/config/config.php';
\$pdo = new PDO('mysql:host='.\$CONFIG['dbhost'].';dbname='.\$CONFIG['dbname'], \$CONFIG['dbuser'], \$CONFIG['dbpassword']);
\$pdo->exec('UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 60 DAY))');
echo 'Database cleared for testing';
"
```

或直接使用 SQL：

```sql
-- 將所有記錄的存取時間設為 60 天前（用於測試）
UPDATE oc_auto_archiver_access 
SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 60 DAY));
```

---

## 技術細節

### 架構設計

```
┌─────────────────────────────────────────────────────────┐
│                    Nextcloud Core                       │
└─────────────────────────────────────────────────────────┘
                          │
        ┌─────────────────┼─────────────────┐
        │                 │                 │
┌───────▼────────┐ ┌──────▼──────┐ ┌───────▼─────────┐
│ FileReadListener│ │ArchiveOldFiles│ │RestoreController│
│  (Event Listener)│ │  (Cron Job)  │ │   (API Endpoint)│
└─────────────────┘ └─────────────┘ └─────────────────┘
        │                 │                 │
        └─────────────────┼─────────────────┘
                          │
              ┌───────────▼───────────┐
              │   Database            │
              │ (auto_archiver_access)│
              └───────────────────────┘
```

### 工作流程

#### 1. 檔案存取追蹤流程

```
使用者點擊檔案
    ↓
BeforeNodeReadEvent 觸發
    ↓
FileReadListener 處理
    ↓
過濾預覽/縮圖請求
    ↓
更新資料庫 last_accessed
```

#### 2. 時間基礎封存流程

```
Cron Job 執行（每天一次）
    ↓
查詢 30 天未存取的檔案
    ↓
對每個檔案：
    ├─ 檢查是否為資料夾 → 跳過
    ├─ 讀取檔案內容
    ├─ 創建 ZIP 壓縮檔
    ├─ 移動到 Archive 資料夾
    ├─ 創建 .ncarchive 占位符
    └─ 刪除資料庫記錄
```

#### 3. 儲存空間監控流程

```
StorageMonitorJob 執行（每小時一次）
    ↓
遍歷所有用戶
    ↓
檢查每個用戶的儲存使用率
    ↓
使用率 >= 80%？
    ├─ 否 → 跳過該用戶
    └─ 是 → 開始封存流程
        ↓
    查詢最久未使用的檔案（按 last_accessed 排序）
        ↓
    封存檔案（每次處理 10 個）
        ↓
    重新檢查使用率
        ↓
    使用率 < 80%？
        ├─ 是 → 停止封存
        └─ 否 → 繼續封存下一批檔案
```

#### 3. 恢復流程

```
使用者點擊 .ncarchive 檔案
    ↓
前端 JavaScript 攔截點擊
    ↓
顯示確認對話框
    ↓
呼叫 RestoreController API
    ↓
檢查儲存配額
    ├─ 空間不足 → 返回錯誤
    └─ 空間足夠 → 繼續
    ↓
讀取 ZIP 檔案
    ↓
解壓縮到臨時目錄
    ↓
恢復到原始位置
    ↓
刪除占位符和封存檔案
    ↓
刷新頁面
```

### 檔案格式

#### 占位符檔案（`.ncarchive`）

占位符檔案是一個 JSON 格式的文字檔案，包含以下資訊：

```json
{
    "original_name": "document.pdf",
    "archived_file_id": 123,
    "path": "/username/files/documents/document.pdf",
    "owner": "username"
}
```

---

## API 端點

### 1. Ping API（更新檔案存取時間）

**端點**：`POST /apps/auto_archiver/ping/{fileId}`

**用途**：當使用者點擊檔案時，前端會自動呼叫此 API 更新存取時間。

**參數**：
- `fileId`（路徑參數）：檔案 ID

**回應**：
```json
{
    "success": true,
    "message": "Access time updated"
}
```

**注意**：此 API 主要用於備用機制。正常情況下，`FileReadListener` 會自動處理檔案存取追蹤。

### 2. Restore API（恢復封存檔案）

**端點**：`POST /apps/auto_archiver/restore/{fileId}`

**用途**：恢復被封存的檔案。

**參數**：
- `fileId`（路徑參數）：占位符檔案的 ID

**成功回應**：
```json
{
    "success": true,
    "fileId": 456,
    "path": "/username/files/documents/document.pdf"
}
```

**錯誤回應（空間不足）**：
```json
{
    "success": false,
    "error": "storage_quota_exceeded",
    "message": "存儲空間不足！恢復此檔案需要 500 MB，但您只有 100 MB 可用空間。請先刪除一些檔案或聯繫管理員增加配額。",
    "required": 524288000,
    "available": 104857600,
    "quota": 1073741824,
    "used": 968884224
}
```

**其他錯誤回應**：
```json
{
    "success": false,
    "error": "Placeholder file not found"
}
```

---

## 數據庫結構

### 資料表：`oc_auto_archiver_access`

| 欄位名稱 | 類型 | 說明 |
|---------|------|------|
| `id` | INTEGER (AUTO_INCREMENT) | 主鍵 |
| `file_id` | INTEGER (UNIQUE) | Nextcloud 檔案 ID |
| `last_accessed` | INTEGER | 最後存取時間（Unix Timestamp） |

**索引**：
- 主鍵：`id`
- 唯一索引：`file_id`（確保每個檔案只有一筆記錄）

**資料表建立**：
資料表會在應用程式啟用時自動建立（透過 Migration）。

---

## 常見問題

### Q1: 為什麼有些檔案沒有被封存？

**A**: 可能的原因：
1. **檔案是資料夾**：系統只封存檔案，不封存資料夾
2. **檔案在 30 天內被存取過**：系統只封存超過 30 天未存取的檔案
3. **檔案不在 filecache 中**：檔案可能已被刪除或不存在

### Q2: 封存後的檔案在哪裡？

**A**: 封存後的 ZIP 檔案位於使用者根目錄下的 `Archive` 資料夾中。如果該資料夾不存在，系統會自動創建。

### Q3: 如何修改封存時間閾值？

**A**: 編輯 `lib/Cron/ArchiveOldFiles.php`，修改 `$days` 變數：

```php
$days = 30;  // 改為您想要的天數
```

### Q4: 恢復檔案時顯示「儲存空間不足」怎麼辦？

**A**: 
1. 刪除不需要的檔案以釋放空間
2. 聯繫管理員增加您的儲存配額
3. 清理 `Archive` 資料夾中的舊封存檔案

### Q5: 如何查看封存任務的執行日誌？

**A**: 
```bash
tail -f /var/www/html/data/nextcloud.log | grep AutoArchiver
```

### Q6: 可以手動觸發封存任務嗎？

**A**: 可以，使用以下命令：

```bash
sudo -u www-data php occ background:job:execute OCA\AutoArchiver\Cron\ArchiveOldFiles
```

### Q7: 占位符檔案可以手動刪除嗎？

**A**: 可以，但**不建議**。如果刪除占位符檔案，您將無法透過正常流程恢復封存檔案。您需要手動從 `Archive` 資料夾中找到對應的 ZIP 檔案並手動解壓縮。

### Q8: 封存任務多久執行一次？

**A**: 預設每天執行一次。可以在 `lib/Cron/ArchiveOldFiles.php` 的 `__construct()` 方法中修改 `setInterval()` 來調整執行頻率。

### Q9: 系統會封存哪些類型的檔案？

**A**: 系統會封存所有類型的檔案（PDF、圖片、文件等），只要：
- 是檔案（非資料夾）
- 超過 30 天未存取
- 存在於 filecache 中

### Q10: 如何停用自動封存功能？

**A**: 
```bash
sudo -u www-data php occ app:disable auto_archiver
```

停用後，系統不會再執行封存任務，但已封存的檔案和占位符仍會保留。

### Q11: 儲存空間監控是如何工作的？

**A**: 
- 系統每小時自動檢查所有用戶的儲存使用率
- 當使用率達到 80% 時，會自動開始封存最久未使用的檔案
- 封存會持續進行，直到使用率降到 80% 以下
- 每次封存會處理 10 個檔案，避免一次性處理過多檔案造成系統負載

### Q12: 如何修改儲存空間監控的閾值（80%）？

**A**: 編輯 `lib/Cron/StorageMonitorJob.php`，修改 `STORAGE_THRESHOLD` 常數：

```php
private const STORAGE_THRESHOLD = 0.80;  // 改為您想要的閾值，例如 0.75 表示 75%
```

### Q13: 儲存空間監控會影響無限制配額的用戶嗎？

**A**: 不會。系統只會監控有配額限制的用戶。如果用戶的配額設定為「無限制」，系統會跳過該用戶的監控。

---

## 版本歷史

### v0.1.3 (當前版本)
- ✅ 新增儲存空間監控功能（Storage Monitoring）
- ✅ 自動監控所有用戶的儲存使用率
- ✅ 使用率達到 80% 時自動封存最久未使用的檔案
- ✅ 持續封存直到使用率降到 80% 以下
- ✅ 每小時自動執行監控任務

### v0.1.2
- ✅ 新增儲存配額檢查功能
- ✅ 恢復前檢查可用空間
- ✅ 空間不足時顯示詳細錯誤訊息
- ✅ 優化頁面刷新速度

### v0.1.1
- ✅ 實現檔案恢復功能
- ✅ 恢復後自動刪除封存檔案
- ✅ 優化使用者體驗

### v0.1.0
- ✅ 初始版本
- ✅ 檔案存取追蹤
- ✅ 自動封存功能
- ✅ 占位符檔案
- ✅ 資料夾過濾

---

## 授權

本專案採用 AGPL-3.0 授權。

---

## 作者

Angelica

---

## 貢獻

歡迎提交 Issue 和 Pull Request！

---

## 相關連結

- [Nextcloud 官方文檔](https://docs.nextcloud.com/)
- [Nextcloud 應用開發指南](https://docs.nextcloud.com/server/latest/developer_manual/)

