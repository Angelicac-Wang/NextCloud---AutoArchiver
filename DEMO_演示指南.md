# Auto Archiver 功能演示指南 🎬

## 📋 演示目标

展示系统的五大核心功能：
1. **主动保护**：监控存储使用率，防止存储空间耗尽
2. **用户控制**：让用户参与归档决策（帮我封存/不要封存）
3. **智能监控**：实时追踪文件访问，更新 last_accessed
4. **用户体验**：7天预警通知，让用户提前知道哪些文件即将被封存
5. **存储优化**：自动归档30天未使用的文件（尊重钉选状态）

---

## 🎯 演示流程概览

```
步骤 1: 准备环境，清空测试数据
  ↓
步骤 2: 创建测试文件，触发80%存储警告
  ↓
步骤 3: 测试用户决策（帮我封存 vs 不要封存）
  ↓
步骤 4: 测试文件访问追踪（点击文件更新 last_accessed）
  ↓
步骤 5: 测试7天预警通知
  ↓
步骤 6: 测试钉选功能 + 30天自动封存
  ↓
完成！🎉
```

---

## 📝 详细操作步骤

### 🔧 步骤 1：准备环境

#### 1.1 清空测试数据

```bash
# 清空 access 追踪表
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "DELETE FROM oc_auto_archiver_access;"

# 清空用户决策表
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "DELETE FROM oc_archiver_decisions;"

# 清空通知表
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "DELETE FROM oc_notifications;"

# 清空 job 执行记录（可选，让 job 立即执行）
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "DELETE FROM oc_jobs WHERE class LIKE '%StorageMonitor%';"
```

#### 1.2 重启应用（确保应用状态干净）

```bash
docker compose restart app
```

#### 1.3 登录 Nextcloud Web UI

打开浏览器访问：`http://localhost:8080`
- 用户名：`admin`
- 密码：`admin`

---

### 📊 步骤 2：触发80%存储警告

#### 2.1 查看当前存储使用情况

```bash
# 查看用户配额和使用情况
docker compose exec --user www-data app php occ user:info admin
```

#### 2.2 创建大文件以触发80%警告

**说明**：假设用户配额是 5GB，我们需要使用超过 4GB（80%）

```bash
# 方案 1：在容器内创建大文件（推荐）
docker compose exec --user www-data app bash -c "cd /var/www/html/data/admin/files && dd if=/dev/zero of=test_large_file_1.bin bs=1M count=3000"
docker compose exec --user www-data app bash -c "cd /var/www/html/data/admin/files && dd if=/dev/zero of=test_large_file_2.bin bs=1M count=1500"

# 方案 2：通过 Web UI 上传大文件
# （上传一些大视频或文件，总大小超过配额的80%）

# 重新扫描文件（让 Nextcloud 识别新文件）
docker compose exec --user www-data app php occ files:scan admin
```

#### 2.3 手动执行 StorageMonitorJob

```bash
# 查找 StorageMonitorJob 的 ID
docker compose exec --user www-data app php occ background-job:list | grep -i storage

# 假设 ID 是 123，强制执行
docker compose exec --user www-data app php occ background-job:execute 123 --force-execute
```

**期望结果**：
- ✅ 日志显示：「Storage usage is X% (threshold: 80%)」
- ✅ 日志显示：「Sending storage warning notification」

#### 2.4 在 Web UI 查看通知

刷新浏览器（Ctrl + Shift + R），点击右上角的通知图标 🔔

**期望看到**：
```
⚠️ 储存空间警告
您的储存空间使用率已达 X%（已使用 X GB / 总容量 X GB）
系统将自动封存长期未使用的档案以释放空间。

[帮我封存]  [不要封存]
```

---

### ✅ 步骤 3：测试用户决策功能

#### 3.1 测试"不要封存"按钮

##### 3.1.1 点击"不要封存"按钮

在 Web UI 的通知中，点击 **[不要封存]** 按钮

##### 3.1.2 验证决策已记录

```bash
# 查询决策表
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SELECT * FROM oc_archiver_decisions;"
```

**期望输出**：
```
| user_id | notification_type | decision     | decided_at          |
|---------|-------------------|--------------|---------------------|
| admin   | storage_warning   | skip_archive | 2025-11-30 12:00:00 |
```

##### 3.1.3 再次执行 StorageMonitorJob

```bash
docker compose exec --user www-data app php occ background-job:execute 123 --force-execute
```

##### 3.1.4 验证系统尊重用户决策

```bash
# 查看日志
docker compose exec app bash -c "tail -n 50 data/nextcloud.log | grep -i 'skip_archive\|StorageMonitor'"
```

**期望看到**：
```
User chose 'skip_archive', will not automatically archive files
```

##### 3.1.5 验证文件未被封存

```bash
# 查看文件列表（应该还在）
docker compose exec --user www-data app ls -lh /var/www/html/data/admin/files/
```

**期望结果**：`test_large_file_1.bin` 和 `test_large_file_2.bin` 仍然存在

---

#### 3.2 测试"帮我封存"功能（可选）

如果想测试"帮我封存"功能，可以重置决策：

```bash
# 删除之前的决策
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "DELETE FROM oc_archiver_decisions WHERE user_id='admin';"

# 删除通知
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "DELETE FROM oc_notifications;"

# 再次执行 StorageMonitorJob（触发新通知）
docker compose exec --user www-data app php occ background-job:execute 123 --force-execute
```

在 Web UI 中，点击 **[帮我封存]** 按钮，然后验证系统会自动封存文件。

---

### 🔍 步骤 4：测试文件访问追踪

#### 4.1 准备测试文件

```bash
# 创建几个小测试文件
docker compose exec --user www-data app bash -c "cd /var/www/html/data/admin/files && echo 'Test File A' > test_file_A.txt"
docker compose exec --user www-data app bash -c "cd /var/www/html/data/admin/files && echo 'Test File B' > test_file_B.txt"
docker compose exec --user www-data app bash -c "cd /var/www/html/data/admin/files && echo 'Test File C' > test_file_C.txt"

# 重新扫描
docker compose exec --user www-data app php occ files:scan admin
```

#### 4.2 在 Web UI 点击文件

1. 进入 Nextcloud 文件管理页面
2. 点击 `test_file_A.txt`（打开文件）
3. 等待 2 秒
4. 点击 `test_file_B.txt`

#### 4.3 验证 last_accessed 已更新

```bash
# 查询 access 表
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SELECT file_id, file_path, FROM_UNIXTIME(last_accessed) as last_accessed_time FROM oc_auto_archiver_access WHERE file_path LIKE '%test_file%' ORDER BY last_accessed DESC;"
```

**期望输出**：
```
| file_id | file_path        | last_accessed_time  |
|---------|------------------|---------------------|
| 456     | /test_file_B.txt | 2025-11-30 12:05:00 |
| 455     | /test_file_A.txt | 2025-11-30 12:04:58 |
| 457     | /test_file_C.txt | NULL                |
```

**说明**：
- ✅ A 和 B 有最新的访问时间
- ✅ C 没有被点击，所以没有记录

---

### ⏰ 步骤 5：测试7天预警通知

#### 5.1 手动修改文件的 last_accessed 为 23 天前

```bash
# 计算 23 天前的 Unix 时间戳
# 当前时间 - 23天 = 当前时间 - (23 * 24 * 3600)

# 获取 test_file_C 的 file_id
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SELECT fileid FROM oc_filecache WHERE path LIKE 'files/test_file_C.txt';"

# 假设 file_id 是 457，更新为 23 天前
# 1732000000 是示例时间戳，你需要计算实际的 23 天前时间戳
TIMESTAMP_23_DAYS_AGO=$(($(date +%s) - 23*24*3600))
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "INSERT INTO oc_auto_archiver_access (file_id, file_path, user_id, last_accessed) VALUES (457, '/test_file_C.txt', 'admin', $TIMESTAMP_23_DAYS_AGO) ON DUPLICATE KEY UPDATE last_accessed = $TIMESTAMP_23_DAYS_AGO;"
```

#### 5.2 手动执行 NotificationJob（7天预警通知）

```bash
# 查找 NotificationJob 的 ID
docker compose exec --user www-data app php occ background-job:list | grep -i notification

# 假设 ID 是 124，强制执行
docker compose exec --user www-data app php occ background-job:execute 124 --force-execute
```

**期望结果**：
- ✅ 日志显示：「Found X files eligible for notification」
- ✅ 日志显示：「Sent notification for file」

#### 5.3 在 Web UI 查看通知

刷新浏览器，查看通知 🔔

**期望看到**：
```
📁 档案即将封存提醒
档案 test_file_C.txt 已 23 天未使用，将在 7 天后自动封存。

[钉选此档案]  [标记为已读]  [立即封存]
```

#### 5.4 测试通知按钮

##### 选项 A：点击"钉选此档案"

- 文件会被标记为钉选（`pinned = 1`）
- 验证：
  ```bash
  docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SELECT file_id, pinned FROM oc_auto_archiver_access WHERE file_path LIKE '%test_file_C%';"
  ```

##### 选项 B：点击"标记为已读"

- 通知消失，但文件状态不变

##### 选项 C：点击"立即封存"

- 文件立即被封存

---

### 📌 步骤 6：测试钉选功能 + 30天自动封存

#### 6.1 准备两个测试文件

```bash
# 创建两个新文件
docker compose exec --user www-data app bash -c "cd /var/www/html/data/admin/files && echo 'Pin Test File' > pin_test_file.txt"
docker compose exec --user www-data app bash -c "cd /var/www/html/data/admin/files && echo 'Normal Test File' > normal_test_file.txt"

# 重新扫描
docker compose exec --user www-data app php occ files:scan admin
```

#### 6.2 在 Web UI 点击这两个文件

1. 点击 `pin_test_file.txt`
2. 点击 `normal_test_file.txt`

#### 6.3 钉选其中一个文件

**方案 1：通过 Web UI 钉选**（如果你实现了钉选按钮）
- 右键点击 `pin_test_file.txt`
- 选择"钉选此档案"

**方案 2：直接在数据库中钉选**

```bash
# 获取文件 ID
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SELECT fileid FROM oc_filecache WHERE path LIKE 'files/pin_test_file.txt';"

# 假设 file_id 是 458，标记为钉选
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "UPDATE oc_auto_archiver_access SET pinned = 1 WHERE file_id = 458;"
```

#### 6.4 将两个文件的 last_accessed 改为 31 天前

```bash
# 计算 31 天前的时间戳
TIMESTAMP_31_DAYS_AGO=$(($(date +%s) - 31*24*3600))

# 获取两个文件的 file_id
# 假设 pin_test_file.txt = 458, normal_test_file.txt = 459

# 更新 last_accessed
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "UPDATE oc_auto_archiver_access SET last_accessed = $TIMESTAMP_31_DAYS_AGO WHERE file_id IN (458, 459);"

# 验证
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SELECT file_id, file_path, FROM_UNIXTIME(last_accessed) as last_accessed_time, pinned FROM oc_auto_archiver_access WHERE file_id IN (458, 459);"
```

**期望输出**：
```
| file_id | file_path              | last_accessed_time  | pinned |
|---------|------------------------|---------------------|--------|
| 458     | /pin_test_file.txt     | 2025-10-30 12:00:00 | 1      |
| 459     | /normal_test_file.txt  | 2025-10-30 12:00:00 | 0      |
```

#### 6.5 手动执行 ArchiveOldFiles Job（30天自动封存）

```bash
# 查找 ArchiveOldFiles 的 ID
docker compose exec --user www-data app php occ background-job:list | grep -i archive

# 假设 ID 是 125，强制执行
docker compose exec --user www-data app php occ background-job:execute 125 --force-execute
```

#### 6.6 验证封存结果

```bash
# 方法 1：查看日志
docker compose exec app bash -c "tail -n 100 data/nextcloud.log | grep -i 'archive\|pinned'"

# 方法 2：检查文件是否被移动到 .archive 目录
docker compose exec --user www-data app ls -la /var/www/html/data/admin/files/.archive/

# 方法 3：查看原始文件目录
docker compose exec --user www-data app ls -la /var/www/html/data/admin/files/ | grep -E "pin_test|normal_test"
```

**期望结果**：
- ✅ `normal_test_file.txt` 被移动到 `.archive/` 目录（已封存）
- ✅ `pin_test_file.txt` 仍在原位置（因为被钉选，未封存）
- ✅ 日志显示：「Skipping pinned file: /pin_test_file.txt」
- ✅ 日志显示：「Archived file: /normal_test_file.txt」

---

## 🎉 演示完成检查清单

### ✅ 功能验证清单

- [ ] **主动保护**：存储使用率超过 80% 时收到警告通知
- [ ] **用户控制 - 不要封存**：点击"不要封存"后，系统尊重决策，不自动封存
- [ ] **用户控制 - 帮我封存**：点击"帮我封存"后，系统自动封存文件
- [ ] **智能监控**：点击文件后，`last_accessed` 实时更新
- [ ] **用户体验 - 7天预警**：文件 23 天未使用时收到预警通知
- [ ] **用户体验 - 钉选**：钉选的文件收到7天预警通知
- [ ] **存储优化 - 自动封存**：30 天未使用的文件自动封存
- [ ] **存储优化 - 尊重钉选**：钉选的文件不会被自动封存

---

## 🛠 常用命令速查

### 查询数据库

```bash
# 查看所有追踪的文件
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SELECT file_id, file_path, FROM_UNIXTIME(last_accessed) as time, pinned FROM oc_auto_archiver_access;"

# 查看用户决策
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SELECT * FROM oc_archiver_decisions;"

# 查看通知
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "SELECT * FROM oc_notifications;"

# 查看 Job 列表
docker compose exec --user www-data app php occ background-job:list
```

### 查看日志

```bash
# 查看最新 100 行日志
docker compose exec app bash -c "tail -n 100 data/nextcloud.log"

# 过滤特定关键词
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'archive'"
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'storage'"
docker compose exec app bash -c "tail -n 200 data/nextcloud.log | grep -i 'notification'"
```

### 重置环境

```bash
# 清空所有测试数据
docker compose exec db mysql -u nextcloud -ppassword nextcloud -e "DELETE FROM oc_auto_archiver_access; DELETE FROM oc_archiver_decisions; DELETE FROM oc_notifications;"

# 重启应用
docker compose restart app

# 刷新浏览器
# 按 Ctrl + Shift + R 强制刷新
```

---

## 💡 演示技巧

### 1. 准备演示环境

- 提前清空所有测试数据
- 确保用户配额设置合理（建议 5GB）
- 准备好大文件（可以提前上传）

### 2. 演示顺序

建议按照文档顺序演示，逻辑流畅：
1. 先演示被动触发（存储警告）
2. 再演示主动操作（用户决策）
3. 最后演示自动化（追踪、预警、封存）

### 3. 突出亮点

- **用户决策被尊重**：演示"不要封存"后系统确实不封存
- **实时追踪**：点击文件后立即查询数据库，展示实时性
- **钉选保护**：对比钉选和未钉选文件的不同处理方式

### 4. 处理演示中的问题

- 如果通知没出现，检查 Job 是否成功执行
- 如果文件没封存，检查日志找出原因
- 如果数据库查询出错，检查表名和字段名是否正确

---

## 📚 相关文档

- [开发者指南](./DEVELOPER_GUIDE%20joe.md) - 完整的技术文档和测试指南
- [储存空间警告修复说明](./储存空间警告通知_问题修复说明.md) - 用户决策功能的详细说明
- [常用指令](./我常用的指令.md) - 快速命令参考

---

**最后更新**：2025-11-30  
**版本**：v1.0  
**适用于**：Auto Archiver v1.2.0+

