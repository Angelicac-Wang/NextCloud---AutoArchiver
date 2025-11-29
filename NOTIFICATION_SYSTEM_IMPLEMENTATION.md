# 「留宿宮中」通知系統實施摘要

## 📋 實施概述

本次實施完成了「留宿宮中」通知系統，允許使用者在檔案被封存前收到通知並選擇延長保留期限。

## ✅ 已完成功能

### 1. 資料庫層 ✓

**新增資料表：`oc_archiver_decisions`**
- 記錄通知發送時間
- 記錄使用者決策（延長/忽略/自動封存）
- 支援後續統計分析

**檔案位置**：
```
my-apps/auto_archiver/lib/Migration/Version000200Date20251127000000.php
```

### 2. 後台任務層 ✓

**NotificationJob**
- 每小時執行一次
- 檢查 23 天前訪問的檔案（距離封存還有 7 天）
- 透過 Nextcloud 通知中心發送通知
- 避免重複通知（24小時內）

**檔案位置**：
```
my-apps/auto_archiver/lib/Cron/NotificationJob.php
```

### 3. API 控制層 ✓

**NotificationController**

提供三個 API 端點：

1. **POST /extend/{fileId}** - 延長保留期限
   - 重設檔案的 last_accessed 為當前時間
   - 記錄決策為 'extend'

2. **DELETE /dismiss/{fileId}** - 忽略通知
   - 記錄決策為 'ignore'
   - 檔案仍會在到期時被封存

3. **GET /statistics** - 查看統計
   - 返回使用者的決策統計數據

**檔案位置**：
```
my-apps/auto_archiver/lib/Controller/NotificationController.php
```

### 4. 路由配置 ✓

**更新檔案**：
```
my-apps/auto_archiver/appinfo/routes.php
```

新增路由：
- `/extend/{fileId}` - POST
- `/dismiss/{fileId}` - DELETE
- `/statistics` - GET

### 5. 應用註冊 ✓

**更新檔案**：
```
my-apps/auto_archiver/lib/AppInfo/Application.php
```

註冊 NotificationJob 到後台任務列表。

### 6. 開發文檔 ✓

**更新檔案**：
```
DEVELOPER_GUIDE joe.md
```

新增內容：
- 測試 5：通知系統與「留宿宮中」功能（完整測試流程）
- 🔔 通知系統專章（功能詳解、API 文檔、使用場景）
- 常見問題排查（問題 6、7）
- 快速參考指令更新
- 測試檢查清單更新
- 版本歷史記錄

## 📊 系統架構

```
┌─────────────────────────────────────────────────────────┐
│                   NotificationJob                        │
│              (每小時檢查一次)                              │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│          檢查 23 天前訪問的檔案                           │
│     (距離 30 天封存閾值還有 7 天)                         │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│              發送 Nextcloud 通知                         │
│         (記錄到 oc_archiver_decisions)             │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
         ┌────────┴────────┐
         │                 │
         ▼                 ▼
┌──────────────┐   ┌──────────────┐
│  延長保留期   │   │  忽略通知     │
│  (extend)    │   │  (ignore)    │
└──────────────┘   └──────────────┘
         │                 │
         └────────┬────────┘
                  ▼
┌─────────────────────────────────────────────────────────┐
│          記錄決策到資料庫                                 │
│          (供統計分析使用)                                 │
└─────────────────────────────────────────────────────────┘
```

## 🚀 部署步驟

### 1. 重新啟用應用（執行資料庫遷移）

```bash
docker compose exec app php occ app:disable auto_archiver
docker compose exec app php occ app:enable auto_archiver
```

### 2. 驗證後台任務已註冊

```bash
docker compose exec app php occ background-job:list | grep -i notification
```

應該能看到：
```
| 119 | OCA\AutoArchiver\Cron\NotificationJob | ...
```

### 3. 驗證資料表已創建

```bash
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "DESCRIBE oc_archiver_decisions;"
```

### 4. 手動測試通知任務

```bash
# 創建測試檔案並設置為 23 天前訪問
# 執行通知任務
docker compose exec app php occ background-job:execute 119

# 查看日誌
docker compose exec app tail -n 50 data/nextcloud.log | grep -i notification
```

## 📖 使用說明

### 使用者視角

1. **收到通知**
   - 點擊右上角通知圖標（鈴鐺）
   - 查看「檔案即將被封存」通知
   - 顯示檔案名稱和剩餘天數

2. **選擇操作**
   - 點擊「延長期限」→ 檔案保留期延長 30 天
   - 點擊「忽略」→ 檔案將在到期時被封存

3. **查看統計**
   - 透過 API 查看自己的決策統計

### 開發者視角

詳細測試和使用說明請參考：
- `DEVELOPER_GUIDE joe.md` - 測試 5
- `DEVELOPER_GUIDE joe.md` - 🔔 通知系統專章

## 🔍 測試檢查清單

- [x] 資料庫遷移成功執行
- [x] NotificationJob 已註冊到後台任務
- [x] API 路由正確配置
- [x] NotificationController 正常工作
- [ ] 通知能正確發送到使用者
- [ ] 延長保留期限功能正常
- [ ] 忽略通知功能正常
- [ ] 統計功能正常
- [ ] 決策記錄正確保存

## 📝 注意事項

1. **通知頻率**：NotificationJob 每小時執行一次，避免過於頻繁
2. **重複通知**：24 小時內不會對同一檔案重複發送通知
3. **時間計算**：
   - 封存閾值：30 天
   - 通知閾值：23 天（30 - 7 = 23）
4. **決策記錄**：所有決策都會永久保存，用於未來的行為分析

## 🐛 已知問題

目前無已知問題。如果遇到問題，請參考 `DEVELOPER_GUIDE joe.md` 的「常見問題排查」章節。

## 📚 相關文件

- `DEVELOPER_GUIDE joe.md` - 完整開發測試指南
- `README.md` - 專案主要文檔
- API 文檔內嵌在 `DEVELOPER_GUIDE joe.md`

## 🎯 下一步計劃

可考慮的未來增強：
- [ ] 電子郵件通知支援
- [ ] 自定義通知時間（不只是 7 天前）
- [ ] 批量延長保留期限
- [ ] 可視化統計圖表
- [ ] 導出決策記錄為 CSV

---

**實施日期**：2025-11-27  
**版本**：v1.1.0  
**實施者**：AI Assistant

