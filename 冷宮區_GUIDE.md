第一次開冷宮區要執行以下步驟
docker compose restart app

改前端需要刷新畫面： Ctrl+Shift+R

# 1. 進入應用目錄
cd my-apps/auto_archiver

# 2. 安裝依賴
npm install

# 3. 編譯 JavaScript
npm run build

# 4. 重啟容器
cd ../..
docker compose restart app


