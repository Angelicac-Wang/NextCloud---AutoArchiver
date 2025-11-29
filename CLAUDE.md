# Nextcloud 視覺客製化指南

本文件說明如何在不修改 Nextcloud Server 核心程式碼的情況下，透過 AutoArchiver 應用程式來客製化 Nextcloud 的視覺外觀。

---

## 📚 目錄

1. [為什麼要這樣做](#為什麼要這樣做)
2. [Nextcloud Server 視覺設定的程式碼位置](#nextcloud-server-視覺設定的程式碼位置)
3. [在 AutoArchiver 中覆蓋視覺設定](#在-autoarchiver-中覆蓋視覺設定)
4. [實作範例](#實作範例)
5. [不同分頁的背景設定](#不同分頁的背景設定)

---

## 🤔 為什麼要這樣做

### 問題

當我們想要客製化 Nextcloud 的外觀（例如替換 Logo、修改背景圖片、改變配色等），有兩種方式：

1. **直接修改 NextCloud-server 核心程式碼**
   - ❌ 困難：需要修改核心檔案
   - ❌ 難以整合：同學的功能寫在 AutoArchiver，整合複雜
   - ❌ 維護困難：升級 Nextcloud 時會遺失修改

2. **在 AutoArchiver 應用程式中用 CSS 覆蓋**（推薦）
   - ✅ 簡單：只需要添加 CSS 檔案
   - ✅ 容易整合：與同學的 AutoArchiver 功能無縫整合
   - ✅ 易於維護：不修改核心，可以隨時掛載到正式環境

### 解決方案

我們採用**方案 2**：讀取 NextCloud-server 的視覺設定程式碼位置，然後在 AutoArchiver 中用 CSS 覆蓋。

---

## 📂 Nextcloud Server 視覺設定的程式碼位置

### 1. CSS 變數定義

**檔案位置**：`NextCloud-server/core/css/variables.scss`

關鍵變數（第 61-64 行）：

```scss
$image-logo: url('../img/logo/logo.svg?v=1') !default;
$image-login-background: url('../img/background.png?v=2') !default;
$image-logoheader: url('../img/logo/logo.svg?v=1') !default;
$image-favicon: url('../img/logo/logo.svg?v=1') !default;
```

顏色變數（第 18-50 行）：

```scss
$color-main-text: #222 !default;
$color-main-background: #fff !default;
$color-primary: #0082c9 !default;
$color-primary-text: #ffffff !default;
$color-error: #e9322d;
$color-warning: #eca700;
$color-success: #46ba61;
```

### 2. Header Logo 設定

**檔案位置**：`NextCloud-server/core/css/header.scss`（第 70 行）

```scss
.logo {
    background-image: var(--image-logoheader, var(--image-logo, url('../img/logo/logo.svg')));
    background-repeat: no-repeat;
    background-size: contain;
    background-position: center;
    width: 62px;
    position: absolute;
    filter: var(--image-logoheader-custom, var(--background-image-invert-if-bright));
}
```

**說明**：Logo 使用了 CSS 變數的 fallback 機制：
1. 優先使用 `--image-logoheader`
2. 若無，則使用 `--image-logo`
3. 最後才使用預設的 `url('../img/logo/logo.svg')`

### 3. Logo 圖片檔案位置

**檔案位置**：`NextCloud-server/core/img/logo/`

```
logo/
├── logo.svg              # 主要 SVG logo
├── logo.png              # PNG 版本
├── logo-icon-175px.png   # 小圖示
├── logo-mail.png         # 郵件用 logo
└── logo-enterprise.svg   # 企業版 logo
```

### 4. Dashboard 特定樣式

**檔案位置**：`NextCloud-server/apps/dashboard/css/dashboard.scss`

Dashboard 使用特殊的 body class 來識別：

```scss
#body-user.dashboard--dark & {
    --color-header: rgba(255, 255, 255, 1);
}

#body-user.dashboard--scrolled & {
    margin-top: 0;
}
```

### 5. 分頁識別方式

Nextcloud 使用 `body` 的 class 或 data 屬性來識別不同分頁：

- Dashboard: `#body-user.dashboard--*` 或 `body[data-app="dashboard"]`
- Files: `body[data-app="files"]`
- 其他應用: `body[data-app="<app-name>"]`

---

## 🎨 在 AutoArchiver 中覆蓋視覺設定

### 基本原理

使用 CSS 的 `!important` 規則和更高的選擇器優先級來覆蓋 Nextcloud 的預設樣式。

### 實作步驟

#### 1. 建立 CSS 檔案

在 AutoArchiver 中建立 CSS 檔案：

```
my-apps/auto_archiver/
├── css/
│   ├── dashboard.css      # Dashboard 專用樣式
│   ├── files.css          # Files 專用樣式
│   └── global.css         # 全域樣式（Logo、Header 等）
├── public/
│   ├── logo.svg           # 自訂 Logo
│   ├── dashboard_bg.jpg   # Dashboard 背景圖
│   └── files_bg.jpg       # Files 背景圖
└── lib/AppInfo/Application.php
```

#### 2. 在 Application.php 中註冊 CSS

**檔案位置**：`my-apps/auto_archiver/lib/AppInfo/Application.php`

```php
public function boot(IBootContext $context): void {
    Util::addScript('auto_archiver', 'script');

    // 註冊全域樣式
    Util::addStyle('auto_archiver', 'global');

    // 註冊特定分頁樣式
    Util::addStyle('auto_archiver', 'dashboard');
    Util::addStyle('auto_archiver', 'files');
}
```

---

## 💡 實作範例

### 範例 1：替換 Header Logo

**檔案**：`css/global.css`

```css
/* 覆蓋 Logo CSS 變數 */
:root {
    --image-logo: url('../public/logo.svg') !important;
    --image-logoheader: url('../public/logo.svg') !important;
}

/* 直接覆蓋 Header Logo */
#header .logo {
    background-image: url('../public/logo.svg') !important;
    background-size: contain !important;
    background-position: center !important;
    filter: none !important; /* 移除預設的濾鏡效果 */
}
```

**說明**：
- 使用 `:root` 覆蓋 CSS 變數（適用於所有使用這些變數的地方）
- 直接針對 `#header .logo` 選擇器覆蓋（確保生效）
- 使用 `!important` 提高優先級

### 範例 2：修改主色調

**檔案**：`css/global.css`

```css
/* 覆蓋主色調 */
:root {
    --color-primary: #ff6b6b !important;           /* 主色 */
    --color-primary-element: #ff6b6b !important;   /* 按鈕等元素 */
    --color-primary-text: #ffffff !important;      /* 主色文字 */
}

/* 覆蓋 Header 背景色 */
#header {
    background-color: #2c3e50 !important;
}
```

### 範例 3：Dashboard 背景圖片（僅 Dashboard 分頁）

**檔案**：`css/dashboard.css`

```css
/* Dashboard 分頁背景圖片 */
body[data-app="dashboard"] #content-vue {
    background-image: url('../public/dashboard_bg.jpg') !important;
    background-size: cover !important;
    background-position: center !important;
    background-repeat: no-repeat !important;
    background-attachment: fixed !important;
}

/* Dashboard 主容器背景 */
body[data-app="dashboard"] #app-dashboard {
    background-image: url('../public/dashboard_bg.jpg') !important;
    background-size: cover !important;
    background-position: center !important;
    background-repeat: no-repeat !important;
}

/* 增加半透明遮罩，讓文字更清楚 */
body[data-app="dashboard"] #app-dashboard::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.2); /* 黑色半透明 */
    pointer-events: none;
    z-index: 0;
}

/* 確保 Dashboard 內容在遮罩之上 */
body[data-app="dashboard"] #app-dashboard > * {
    position: relative;
    z-index: 1;
}
```

**說明**：
- 使用 `body[data-app="dashboard"]` 選擇器，只在 Dashboard 分頁生效
- 不影響 Files 或其他分頁

### 範例 4：Files 分頁背景圖片

**檔案**：`css/files.css`

```css
/* Files 分頁背景圖片 */
body[data-app="files"] #content-vue {
    background-image: url('../public/files_bg.jpg') !important;
    background-size: cover !important;
    background-position: center !important;
    background-repeat: no-repeat !important;
    background-attachment: fixed !important;
}

/* Files 容器背景設定 */
body[data-app="files"] #app-content-vue {
    background-color: rgba(255, 255, 255, 0.9) !important; /* 半透明白色 */
}

/* Files 列表背景 */
body[data-app="files"] .files-list {
    background-color: rgba(255, 255, 255, 0.95) !important;
    border-radius: 10px;
    padding: 10px;
}
```

---

## 🎯 不同分頁的背景設定

### 概念

每個 Nextcloud 分頁都會在 `<body>` 標籤上添加 `data-app` 屬性或特定的 class，我們可以利用這個特性來針對不同分頁設定不同的背景。

### 分頁識別方式

| 分頁名稱 | 選擇器 | 說明 |
|---------|--------|------|
| Dashboard | `body[data-app="dashboard"]` | 首頁儀表板 |
| Files | `body[data-app="files"]` | 檔案管理 |
| Photos | `body[data-app="photos"]` | 相片應用 |
| Settings | `body[data-app="settings"]` | 設定頁面 |
| 登入頁面 | `#body-login` | 登入/註冊頁面 |

### 檢查當前分頁的方法

在瀏覽器開發者工具 Console 中執行：

```javascript
// 方法 1：檢查 data-app 屬性
console.log('當前分頁:', document.body.getAttribute('data-app'));

// 方法 2：檢查 body 的所有 class
console.log('Body classes:', document.body.className);

// 方法 3：檢查所有屬性
console.log('Body 屬性:', document.body.attributes);
```

### 實作：為每個分頁設定不同背景

**檔案結構**：

```
css/
├── global.css           # 全域樣式（Header、Logo）
├── backgrounds.css      # 所有分頁的背景設定
└── components.css       # 其他組件樣式
```

**範例**：`css/backgrounds.css`

```css
/* ==================== Dashboard 背景 ==================== */
body[data-app="dashboard"] #content-vue,
body[data-app="dashboard"] #app-dashboard {
    background-image: url('../public/bg-dashboard.jpg') !important;
    background-size: cover !important;
    background-position: center !important;
    background-repeat: no-repeat !important;
    background-attachment: fixed !important;
}

/* Dashboard 遮罩 */
body[data-app="dashboard"] #app-dashboard::before {
    content: '';
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.3);
    pointer-events: none;
    z-index: 0;
}

/* ==================== Files 背景 ==================== */
body[data-app="files"] #content-vue {
    background-image: url('../public/bg-files.jpg') !important;
    background-size: cover !important;
    background-position: center !important;
    background-repeat: no-repeat !important;
    background-attachment: fixed !important;
}

/* Files 列表容器半透明背景 */
body[data-app="files"] #app-content-vue .files-list {
    background-color: rgba(255, 255, 255, 0.92) !important;
    backdrop-filter: blur(10px);
    border-radius: 12px;
    padding: 12px;
}

/* ==================== Photos 背景 ==================== */
body[data-app="photos"] #content-vue {
    background-image: url('../public/bg-photos.jpg') !important;
    background-size: cover !important;
    background-position: center !important;
    background-attachment: fixed !important;
}

/* ==================== Settings 背景 ==================== */
body[data-app="settings"] #content-vue {
    background-color: #f5f5f5 !important;
    background-image: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
}

/* ==================== 登入頁面背景 ==================== */
#body-login {
    background-image: url('../public/bg-login.jpg') !important;
    background-size: cover !important;
    background-position: center !important;
    background-attachment: fixed !important;
}

/* ==================== 預設背景（其他分頁） ==================== */
body:not([data-app]) #content-vue {
    background-color: #fafafa !important;
}
```

### 高級技巧：動態背景

如果想要更進階的效果，可以使用 CSS 變數和漸層：

```css
/* 定義不同時段的背景色 */
:root {
    --bg-morning: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --bg-afternoon: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    --bg-evening: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --bg-night: linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%);
}

/* Dashboard 根據 class 切換背景 */
body[data-app="dashboard"].morning #app-dashboard {
    background-image: var(--bg-morning) !important;
}

body[data-app="dashboard"].afternoon #app-dashboard {
    background-image: var(--bg-afternoon) !important;
}

body[data-app="dashboard"].evening #app-dashboard {
    background-image: var(--bg-evening) !important;
}

body[data-app="dashboard"].night #app-dashboard {
    background-image: var(--bg-night) !important;
}
```

然後在 JavaScript 中動態添加 class：

```javascript
// 在 js/script.js 中
const hour = new Date().getHours();
let timeClass = 'morning';

if (hour >= 12 && hour < 17) timeClass = 'afternoon';
else if (hour >= 17 && hour < 20) timeClass = 'evening';
else if (hour >= 20 || hour < 6) timeClass = 'night';

document.body.classList.add(timeClass);
```

---

## 🔍 調試技巧

### 1. 檢查 CSS 是否載入

在瀏覽器開發者工具 Console 中執行：

```javascript
// 列出所有載入的樣式表
document.querySelectorAll('link[rel="stylesheet"]').forEach(link => {
    if(link.href.includes('auto_archiver')) {
        console.log('✅ CSS 已載入:', link.href);
    }
});

// 檢查特定 CSS 檔案
fetch('/apps/auto_archiver/css/dashboard.css')
    .then(res => res.ok ? console.log('✅ CSS 可訪問') : console.log('❌ CSS 無法訪問'));
```

### 2. 檢查 CSS 是否生效

```javascript
// 檢查元素的計算樣式
const dashboard = document.querySelector('#app-dashboard');
const styles = getComputedStyle(dashboard);
console.log('背景圖片:', styles.backgroundImage);
console.log('背景大小:', styles.backgroundSize);
```

### 3. 強制刷新瀏覽器快取

- **Windows/Linux**: `Ctrl + Shift + R`
- **Mac**: `Cmd + Shift + R`

### 4. 重新啟用應用程式

```bash
# 禁用應用程式
docker compose exec app php occ app:disable auto_archiver

# 重新啟用
docker compose exec app php occ app:enable auto_archiver

# 重啟容器
docker compose restart app
```

---

## 📝 完整實作清單

### 步驟 1：建立 CSS 檔案

```bash
cd my-apps/auto_archiver
mkdir -p css public
touch css/global.css css/backgrounds.css
```

### 步驟 2：放置圖片資源

將您的圖片放到 `public/` 目錄：

```
public/
├── logo.svg
├── bg-dashboard.jpg
├── bg-files.jpg
└── bg-login.jpg
```

### 步驟 3：編寫 CSS

參考上面的範例編寫 CSS 檔案。

### 步驟 4：註冊 CSS

在 `lib/AppInfo/Application.php` 的 `boot()` 方法中：

```php
Util::addStyle('auto_archiver', 'global');
Util::addStyle('auto_archiver', 'backgrounds');
```

### 步驟 5：測試

1. 重新啟用應用程式
2. 強制刷新瀏覽器
3. 檢查不同分頁的背景是否正確

---

## 🎓 總結

透過這種方式，我們可以：

1. ✅ **不修改 Nextcloud Server 核心程式碼**
2. ✅ **在 AutoArchiver 應用中完成所有視覺客製化**
3. ✅ **輕鬆與同學的功能整合**
4. ✅ **為不同分頁設定不同的背景**
5. ✅ **隨時掛載到正式環境使用**

---

**最後更新**：2025-11-28
**作者**：Claude AI + Yu
