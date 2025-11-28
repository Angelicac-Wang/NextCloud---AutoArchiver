# Auto Archiver - 開發者使用手冊

本手冊專為開發者設計，提供完整的開發環境設置、功能測試和調試指南。

---

## 🛠️ 環境設置

### 前置需求

- **Docker** 和 **Docker Compose**
  ```bash
  # 檢查 Docker 版本
  docker --version
  docker compose version
  ```

- **Git**（用於版本控制）
  ```bash
  git --version
  ```

### 專案結構

```
nextcloud-dev/
├── docker-compose.yml          # Docker Compose 配置
├── my-apps/
│   └── auto_archiver/          # 應用程式目錄
│       ├── appinfo/
│       ├── lib/
│       ├── js/
│       └── ...
└── README.md
```

### 克隆專案

```bash
# 克隆專案（假設你有 Git 倉庫）
git clone <repository-url>
cd nextcloud-dev
```

---

## 🚀 啟動開發環境

### 1. 啟動 Nextcloud 容器

```bash
# 在專案根目錄執行
docker compose up -d

# 查看容器狀態
docker compose ps

# 查看日誌
docker compose logs -f
```

### 2. 初始化 Nextcloud

首次啟動時，需要通過瀏覽器完成初始化：

1. 打開瀏覽器，訪問：`http://localhost:8081`
2. 創建管理員帳號（建議使用 `admin` / `admin`）
3. 等待初始化完成

### 3. 驗證環境

```bash
# 進入 Nextcloud 容器
docker compose exec app bash

# 在容器內執行 Nextcloud 命令
php occ status

# 退出容器
exit
```

---

## 📦 應用程式安裝與啟用

### 方法一：自動掛載（推薦）

專案已配置 Docker Compose，應用程式會自動掛載到容器內：

```bash
# 確認掛載是否成功
docker compose exec app ls -la /var/www/html/custom_apps/

# 應該能看到 auto_archiver 目錄
```

### 方法二：手動複製

如果需要手動複製：

```bash
# 複製應用程式到容器內
docker compose exec app cp -r /var/www/html/custom_apps/auto_archiver /var/www/html/custom_apps/
```

### 啟用應用程式

```bash
# 啟用應用程式
docker compose exec app php occ app:enable auto_archiver

# 檢查應用程式狀態
docker compose exec app php occ app:list | grep auto_archiver

# 應該看到：auto_archiver    0.1.4      enabled
```

### 禁用應用程式（用於測試）

```bash
# 禁用應用程式
docker compose exec app php occ app:disable auto_archiver

# 重新啟用
docker compose exec app php occ app:enable auto_archiver
```

---

## 🧪 功能測試指南

### 測試 1：檔案存取追蹤

**目標**：驗證系統能正確追蹤檔案存取時間。

#### 步驟：

1. **上傳測試檔案**
   ```bash
   # 通過 Nextcloud Web UI 上傳一個檔案，例如：test.txt
   # 或使用命令行（在容器內）
   docker compose exec app bash
   echo "Test content" > /var/www/html/data/admin/files/test.txt
   exit
   ```

2. **觸發檔案存取**
   - 在 Nextcloud Web UI 中點擊並打開 `test.txt`
   - 或使用 API 存取檔案

3. **檢查資料庫記錄**
   ```bash
   # 查看資料庫中的存取記錄
   docker compose exec app php occ db:query "SELECT * FROM oc_auto_archiver_access ORDER BY last_accessed DESC LIMIT 10"
   ```
   ```or
   docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SELECT * FROM oc_auto_archiver_access;"
   ```
4. **驗證結果**
   - 應該能看到 `test.txt` 的 `last_accessed` 時間已更新
   - `file_id` 對應檔案在 `oc_filecache` 中的 ID

#### 預期結果：

- 資料庫中出現新記錄
- `last_accessed` 時間為當前時間戳

---

### 測試 2：自動封存舊檔案

**目標**：驗證系統能自動封存超過 30 天未存取的檔案。

#### 步驟：

1. **準備測試檔案**
   ```bash
   # 上傳一個測試檔案（例如：old_file.txt）
   # 通過 Web UI 上傳，或使用命令行
   ```

2. **模擬舊檔案（修改資料庫中的存取時間）**
   ```bash
   # 獲取檔案 ID
   docker compose exec app php occ db:query "SELECT fileid, path FROM oc_filecache WHERE path LIKE '%old_file.txt%'"
   
   # 假設 file_id 為 123，將 last_accessed 設為 31 天前
   docker compose exec app php occ db:query "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 31 DAY)) WHERE file_id = 123"
   ```
   ```or
   docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "UPDATE oc_auto_archiver_access SET last_accessed = 1000000000 WHERE file_id = 123;"
   ```

3. **手動觸發封存任務**
   ```bash
   # 方法一：使用 background-job:execute
   docker compose exec app php occ background-job:execute OCA\\AutoArchiver\\Cron\\ArchiveOldFiles
   
   # 方法二：使用 --force-execute（如果支援）
   docker compose exec app php occ background-job:execute <Job ID> --force-execute
   ```

4. **檢查結果**
   ```bash
   # 查看日誌
   docker compose exec app tail -f data/nextcloud.log | grep -i "archiver\|archive"
   
   # 檢查 Archive 資料夾
   # 在 Nextcloud Web UI 中查看 Archive 資料夾，應該能看到壓縮檔
   
   # 檢查占位符檔案
   # 在原位置應該能看到 .ncarchive 檔案
   ```

#### 預期結果：

- 原檔案被壓縮為 `.zip` 並移動到 `Archive` 資料夾
- 原位置出現 `.ncarchive` 占位符檔案
- 資料庫記錄被刪除（因為檔案已封存）

---

### 測試 3：檔案恢復功能

**目標**：驗證使用者可以透過點擊占位符恢復檔案。

#### 步驟：

1. **確保有已封存的檔案**
   - 完成「測試 2」後，應該有 `.ncarchive` 檔案

2. **在 Web UI 中點擊占位符**
   - 進入 Nextcloud 檔案列表
   - 找到 `.ncarchive` 檔案（例如：`old_file.txt.ncarchive`）
   - 點擊該檔案

3. **確認恢復對話框**
   - 應該彈出確認對話框：「是否恢復資料？」
   - 點擊「確定」

4. **驗證恢復結果**
   ```bash
   # 檢查原檔案是否恢復
   # 在 Web UI 中應該能看到原始檔案（old_file.txt）
   
   # 檢查 Archive 資料夾中的 ZIP 檔案是否被刪除
   # Archive 資料夾中對應的 .zip 檔案應該已消失
   
   # 檢查占位符是否被刪除
   # .ncarchive 檔案應該已消失
   ```

#### 預期結果：

- 原始檔案恢復到原位置
- 占位符檔案被刪除
- Archive 資料夾中的 ZIP 檔案被刪除

---

### 測試 4：儲存空間監控

**目標**：驗證系統能在儲存空間使用率超過 80% 時自動封存檔案。

#### 步驟：

1. **檢查當前儲存使用率**
   ```bash
   # 查看使用者儲存資訊
   docker compose exec app php occ user:info admin
   
   # 查看儲存使用率（應該會顯示百分比）
   ```

2. **降低儲存配額（用於測試）**
   ```bash
   # 將配額設為較小值，例如 10MB
   docker compose exec app php occ user:setting admin files quota 10MB
   
   # 驗證配額
   docker compose exec app php occ user:info admin | grep quota
   ```

3. **上傳大檔案以觸發閾值**
   ```bash
   # 上傳幾個大檔案，使使用率超過 80%
   # 可以通過 Web UI 上傳，或使用命令行
   ```

4. **手動觸發儲存監控任務**
   ```bash
   # 觸發 StorageMonitorJob
   docker compose exec app php occ background-job:execute <Job ID>
   
   # 或使用 --force-execute
   docker compose exec app php occ background-job:execute <Job ID> --force-execute
   ```

5. **查看日誌**
   ```bash
   # 查看詳細日誌
   docker compose exec app tail -f data/nextcloud.log | grep -i "storagemonitor"
   ```

6. **驗證結果**
   ```bash
   # 檢查儲存使用率是否降低
   docker compose exec app php occ user:info admin
   
   # 檢查是否有檔案被封存
   # 查看 Archive 資料夾和占位符檔案
   ```

#### 預期結果：

- 系統檢測到儲存使用率超過閾值（預設 80%）
- 自動封存最久未使用的檔案
- 持續封存直到使用率降到閾值以下
- 日誌中顯示詳細的封存過程

---

### 測試 5：資料夾過濾

**目標**：驗證系統只封存檔案，不封存資料夾。

#### 步驟：

1. **創建測試資料夾和檔案**
   ```bash
   # 通過 Web UI 創建一個資料夾（例如：test_folder）
   # 在資料夾內上傳一個檔案（例如：test_file.txt）
   ```

2. **模擬資料夾為舊檔案**
   ```bash
   # 獲取資料夾的 file_id
   docker compose exec app php occ db:query "SELECT fileid, path FROM oc_filecache WHERE path LIKE '%test_folder%' AND type = 2"
   
   # 假設資料夾 file_id 為 456，將 last_accessed 設為 31 天前
   docker compose exec app php occ db:query "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 31 DAY)) WHERE file_id = 456"
   ```

3. **觸發封存任務**
   ```bash
   docker compose exec app php occ background-job:execute OCA\\AutoArchiver\\Cron\\ArchiveOldFiles
   ```

4. **檢查結果**
   ```bash
   # 查看日誌，應該看到資料夾被跳過的訊息
   docker compose exec app tail -f data/nextcloud.log | grep -i "folder\|skipped"
   ```

#### 預期結果：

- 資料夾不被封存（日誌顯示 "skipped"）
- 資料夾內的檔案可以正常被封存
- 資料夾結構保持完整

---


## 📊 日誌查看與調試

### 查看 Nextcloud 日誌

```bash
# 實時查看所有日誌
docker compose exec app tail -f data/nextcloud.log

# 只查看 Auto Archiver 相關日誌
docker compose exec app tail -f data/nextcloud.log | grep -i "auto_archiver\|archiver\|archive"

# 查看最近的日誌（最後 100 行）
docker compose exec app tail -n 100 data/nextcloud.log | grep -i archiver
```

### 查看特定功能的日誌

```bash
# 封存任務日誌
docker compose exec app tail -f data/nextcloud.log | grep -i "ArchiveOldFiles\|Archiving"

# 儲存監控日誌
docker compose exec app tail -f data/nextcloud.log | grep -i "StorageMonitor"

# 恢復功能日誌
docker compose exec app tail -f data/nextcloud.log | grep -i "Restore\|restore"
```

### 查看 Docker 容器日誌

```bash
# 查看 app 容器日誌
docker compose logs -f app

# 查看 db 容器日誌
docker compose logs -f db
```

### 啟用調試模式

```bash
# 在 Nextcloud 配置中啟用調試模式
docker compose exec app php occ config:system:set loglevel --value=0

# 0 = Debug, 1 = Info, 2 = Warning, 3 = Error, 4 = Fatal
```

---

## 💾 資料庫操作

### 查看資料表結構

```bash
# 查看 auto_archiver_access 表結構
docker compose exec app php occ db:query "DESCRIBE oc_auto_archiver_access"

# 查看所有記錄
docker compose exec app php occ db:query "SELECT * FROM oc_auto_archiver_access"
```

### 模擬舊檔案（用於測試）

```bash
# 將所有檔案的存取時間設為 31 天前
docker compose exec app php occ db:query "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 31 DAY))"

# 將特定檔案的存取時間設為 31 天前（假設 file_id = 123）
docker compose exec app php occ db:query "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 31 DAY)) WHERE file_id = 123"
```

### 清空測試資料

```bash
# 清空所有存取記錄（謹慎使用！）
docker compose exec app php occ db:query "DELETE FROM oc_auto_archiver_access"

# 清空特定使用者的記錄
docker compose exec app php occ db:query "DELETE FROM oc_auto_archiver_access WHERE file_id IN (SELECT fileid FROM oc_filecache WHERE storage = (SELECT numeric_id FROM oc_storages WHERE id = 'home::admin'))"
```

### 查看檔案資訊

```bash
# 查看檔案快取表
docker compose exec app php occ db:query "SELECT fileid, path, size, mimetype FROM oc_filecache WHERE path LIKE '%test%' LIMIT 10"

# 查看儲存資訊
docker compose exec app php occ db:query "SELECT * FROM oc_storages WHERE id LIKE 'home::%'"
```

---

## 🔧 常見問題排查

### 問題 1：應用程式無法啟用

**症狀**：執行 `occ app:enable auto_archiver` 時出現錯誤。

**解決方案**：

```bash
# 1. 檢查應用程式目錄權限
docker compose exec app ls -la /var/www/html/custom_apps/auto_archiver

# 2. 檢查應用程式結構
docker compose exec app php occ app:check-code auto_archiver

# 3. 查看詳細錯誤訊息
docker compose exec app php occ app:enable auto_archiver -v
```

### 問題 2：背景任務不執行

**症狀**：封存任務沒有自動執行。

**解決方案**：

```bash
# 1. 檢查 Cron 配置
docker compose exec app php occ config:app:get core backgroundjobs_mode

# 2. 手動觸發任務測試
docker compose exec app php occ background-job:execute OCA\\AutoArchiver\\Cron\\ArchiveOldFiles

# 3. 檢查任務是否在隊列中
docker compose exec app php occ background-job:list | grep -i archiver
```

### 問題 3：檔案無法封存

**症狀**：執行封存任務後，檔案沒有被封存。

**解決方案**：

```bash
# 1. 檢查日誌
docker compose exec app tail -n 200 data/nextcloud.log | grep -i "archiver\|archive"

# 2. 檢查檔案是否存在
docker compose exec app php occ db:query "SELECT * FROM oc_filecache WHERE fileid = <file_id>"

# 3. 檢查資料庫記錄
docker compose exec app php occ db:query "SELECT * FROM oc_auto_archiver_access WHERE file_id = <file_id>"

# 4. 檢查 Archive 資料夾是否存在
docker compose exec app php occ files:scan --all
```

### 問題 4：恢復功能不工作

**症狀**：點擊占位符檔案沒有反應。

**解決方案**：

```bash
# 1. 檢查 JavaScript 是否載入
# 在瀏覽器開發者工具中查看 Console，應該看到 "AutoArchiver v0.1.3 Loaded"

# 2. 檢查占位符檔案內容
docker compose exec app cat /var/www/html/data/admin/files/<filename>.ncarchive

# 3. 檢查 API 路由
docker compose exec app php occ app:list | grep auto_archiver

# 4. 查看瀏覽器網路請求
# 在瀏覽器開發者工具的 Network 標籤中查看是否有 POST 請求到 /apps/auto_archiver/restore/
```

### 問題 5：儲存空間監控不觸發

**症狀**：儲存使用率超過 80%，但沒有自動封存。

**解決方案**：

```bash
# 1. 檢查儲存使用率
docker compose exec app php occ user:info admin

# 2. 檢查閾值設定（在 StorageMonitorJob.php 中）
# STORAGE_THRESHOLD 預設為 0.80 (80%)

# 3. 手動觸發測試
docker compose exec app php occ background-job:execute OCA\\AutoArchiver\\Cron\\StorageMonitorJob

# 4. 查看日誌
docker compose exec app tail -f data/nextcloud.log | grep -i "storagemonitor"
```

---

## 🔄 開發工作流程

### 1. 修改程式碼

```bash
# 在本地編輯器中修改程式碼
# 由於 Docker 掛載，修改會立即反映到容器內
```

### 2. 編譯前端資源（如果修改了 JS/Vue 檔案）

**初次設置**：

```bash
# 進入 auto_archiver 目錄
cd my-apps/auto_archiver

# 安裝 npm 依賴
npm install

# 編譯前端資源（生產模式）
npm run build

# 或使用開發模式（自動監聽檔案變化）
npm run dev
```

**日常開發**：

```bash
# 每次修改 src/ 目錄下的檔案後，重新編譯
cd my-apps/auto_archiver
npm run build

# 或保持 watch 模式運行（自動編譯）
npm run dev
```

### 3. 重新載入應用程式

```bash
# 禁用並重新啟用應用程式
docker compose exec app php occ app:disable auto_archiver
docker compose exec app php occ app:enable auto_archiver
```

### 4. 清除快取

```bash
# 清除 Nextcloud 快取
docker compose exec app php occ files:scan --all
```

### 5. 測試修改

```bash
# 執行相關測試（參考「功能測試指南」）
docker compose exec app php occ background-job:execute OCA\\AutoArchiver\\Cron\\ArchiveOldFiles
```

### 6. 查看日誌

```bash
# 查看修改後的日誌輸出
docker compose exec app tail -f data/nextcloud.log | grep -i archiver
```

---

## 📝 快速參考指令

### 常用指令速查表

```bash
# === 容器管理 ===
docker compose up -d              # 啟動容器
docker compose down               # 停止容器
docker compose restart app        # 重啟 app 容器
docker compose exec app bash      # 進入容器

# === 前端編譯（在 my-apps/auto_archiver 目錄下執行）===
npm install                       # 安裝依賴（首次執行）
npm run build                     # 編譯前端資源
npm run dev                       # 開發模式（自動監聽）

# === 應用程式管理 ===
docker compose exec app php occ app:enable auto_archiver
docker compose exec app php occ app:disable auto_archiver
docker compose exec app php occ app:list | grep auto_archiver

# === 背景任務 ===
docker compose exec app php occ background-job:execute OCA\\AutoArchiver\\Cron\\ArchiveOldFiles
docker compose exec app php occ background-job:execute OCA\\AutoArchiver\\Cron\\StorageMonitorJob
docker compose exec app php occ background-job:list

# === 日誌查看 ===
docker compose exec app tail -f data/nextcloud.log | grep -i archiver
docker compose logs -f app

# === 資料庫操作 ===
docker compose exec app php occ db:query "SELECT * FROM oc_auto_archiver_access"
docker compose exec app php occ db:query "UPDATE oc_auto_archiver_access SET last_accessed = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 31 DAY))"

# === 使用者管理 ===
docker compose exec app php occ user:info admin
docker compose exec app php occ user:setting admin files quota 10MB

# === 檔案掃描 ===
docker compose exec app php occ files:scan --all
docker compose exec app php occ files:scan admin
```

---

## 🎯 測試檢查清單

在提交程式碼前，請確認以下測試都通過：

- [ ] **檔案存取追蹤**：上傳檔案並存取，檢查資料庫記錄
- [ ] **自動封存**：模擬舊檔案，觸發封存，檢查結果
- [ ] **檔案恢復**：點擊占位符，確認檔案恢復
- [ ] **資料夾過濾**：確認資料夾不被封存
- [ ] **儲存監控**：降低配額，觸發監控，確認自動封存
- [ ] **錯誤處理**：測試空間不足、檔案不存在等錯誤情況
- [ ] **日誌輸出**：確認所有操作都有適當的日誌記錄

---

## 📚 相關資源

- [Nextcloud 開發者文件](https://docs.nextcloud.com/server/latest/developer_manual/)
- [Nextcloud API 文件](https://docs.nextcloud.com/server/latest/developer_manual/api/)
- [Docker Compose 文件](https://docs.docker.com/compose/)

---

## 🤝 貢獻指南

歡迎貢獻程式碼！在提交 Pull Request 前，請：

1. 確保所有測試通過
2. 遵循現有的程式碼風格
3. 添加適當的註解和文檔
4. 更新相關的 README 或文檔

---

**最後更新**：2025-11-27  
**版本**：1.0.0

