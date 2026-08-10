# Jin

## 語言

[English](README_en.md) | [日本語](../README.md) | [Français](README_fr.md) | [Deutsch](README_de.md) | [简体中文](README_zh_CN.md) | [繁體中文](README_zh_TW.md) | [Español](README_es.md) | [Português (Brasil)](README_pt_BR.md) | [Português (Portugal)](README_pt_PT.md) | [한국어](README_ko.md)

Jin（陣）是一套可自行託管的專案協作群組軟體，讓多個團隊以專案為單位共同工作。它把訊息、待辦事項、行程、檔案、即時聊天、看板與定期檢查集中到同一個專案工作區。

資訊架構參考了 Basecamp，但名稱、畫面、配色與圖示均為獨立設計。[日文 README](../README.md) 是完整且具權威性的維運文件。本翻譯涵蓋產品功能、系統需求、安裝方式、安全邊界與驗證指令。

## 主要功能

- 建立與切換團隊（工作區）、邀請成員及管理權限
- 建立、編輯、設定範圍與日期並封存專案
- 支援留言、訂閱、置頂與附件的訊息板
- 使用 Trix 的富文字輸入與伺服器端 HTML 清理
- 待辦清單、負責人、截止日期、子任務、留言、附件與拖放排序
- 專案行程以及跨專案的日、週、月與甘特圖檢視
- 拖放檔案上傳與經授權的下載
- 以 Laravel Reverb 實作的即時專案聊天
- 可拖放的卡片表格（Kanban）
- 定期檢查與回答歷程
- 活動時間軸、跨專案報告、陣容檢視與包含支援檔案內文的全域搜尋
- 附簽章的唯讀 iCalendar 訂閱
- 電子郵件驗證、雙因素驗證與 Passkey
- 套用至全站的 IPv4、IPv6 與 CIDR 限制
- 適用桌面與行動裝置的響應式介面

## 技術堆疊

| 領域       | 技術                                                   |
| ---------- | ------------------------------------------------------ |
| 後端       | PHP 8.5 / Laravel 13                                   |
| 前端       | Inertia.js 3 / Svelte 5 / TypeScript                   |
| 介面       | Tailwind CSS 4                                         |
| 富文字     | Trix 2 / Symfony HTML Sanitizer 8                      |
| 即時通訊   | Laravel Reverb / Laravel Echo                          |
| 身分驗證   | Laravel Fortify / Passkeys / 2FA                       |
| 型別化路由 | Laravel Wayfinder                                      |
| 佇列       | Laravel database queue（開發）/ Redis（正式環境）      |
| 資料庫     | SQLite FTS5（開發）/ PostgreSQL + PGroonga（正式環境） |
| 測試       | Pest 4 / PHPStan / Svelte Check / ESLint               |

## 系統需求

- PHP 8.5 以上與 Composer 2
- Node.js 22.12 以上與 npm
- 本機開發與一般測試所需的 PHP SQLite 擴充套件
- 啟用檔案內文搜尋時所需的 Python 3.10 以上與 MarkItDown
- 產生 PDF 首頁縮圖所需的 ImageMagick 與 Poppler
- 正式環境檔案內文搜尋所需的 PostgreSQL 與 PGroonga
- Office 唯讀預覽所需的 ONLYOFFICE Docs

本機開發使用 SQLite FTS5。正式環境使用 PostgreSQL 搭配 PGroonga；檔案內文搜尋的正式環境設定不支援 MySQL。

## 本機快速安裝

在新複製的儲存庫中執行：

```bash
composer install
python3 -m venv .venv-file-content
.venv-file-content/bin/python -m pip install --requirement requirements-file-content.txt
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
```

在不同終端機中啟動各程序：

```bash
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
php artisan queue:work
php artisan reverb:start
```

若需要截止日期通知與定期檢查，請另外啟動 `php artisan schedule:work`，然後開啟 <http://localhost:8000>。填入資料後的開發專用範例帳號為 `test@example.com`，密碼為 `password`。請勿在正式環境使用。

## macOS 與 ONLYOFFICE

支援的 macOS 方案使用 Homebrew 與 Apple [`container`](https://github.com/apple/container)。Apple Container 需要 Apple 晶片與 macOS 26 以上版本。

```bash
brew install php composer node@22 python imagemagick poppler
container system start
./setup.sh --platform macos --app-dir "$PWD" --no-nginx
```

啟用 ONLYOFFICE 時，請設定至少 32 個字元的隨機密鑰，且絕對不可提交至 Git：

```dotenv
ONLYOFFICE_ENABLED=true
ONLYOFFICE_JWT_SECRET=replace-with-at-least-32-random-characters
```

localhost DNS 對應、容器生命週期與復原方式請參閱[權威的 macOS 說明](../README.md#macos開発環境)。

## MCP 整合

本機 Jin MCP 伺服器的整個程序都以指定 Jin 使用者的權限執行：

```bash
php artisan jin:mcp user@example.com
```

僅應在可信任的本機環境中使用，並採用最低權限的專用帳號。該使用者的團隊與專案權限會套用到所有 MCP 工具。

## Ubuntu 24.04 / 26.04 正式環境部署

`setup.sh` 會設定 Jin、PHP、PostgreSQL/PGroonga、Redis、Node.js、MarkItDown、Nginx、Supervisor、TLS 與 ONLYOFFICE Docs。支援的目標為 amd64 Ubuntu Server，至少配備 2 個 CPU、4 GB 記憶體、4 GB swap 與足夠的磁碟空間。公開網路只應開放 80/443 與選定的 SSH 連接埠。

```bash
export JIN_DOMAIN=jin.example.com
export JIN_REPOSITORY=https://github.com/askdkc/jin
export JIN_PATH=/var/www/jin

sudo apt update
sudo apt install -y git
git clone --depth=1 "$JIN_REPOSITORY" /tmp/jin-installer
cd /tmp/jin-installer

./setup.sh \
  --platform ubuntu \
  --domain "$JIN_DOMAIN" \
  --repo "$JIN_REPOSITORY" \
  --app-dir "$JIN_PATH" \
  --onlyoffice-port 8081 \
  --app-internal-port 8090
```

若需要 Let's Encrypt 註冊與到期通知，請加入 `--email admin@example.com`。`--no-ssl` 僅適用於無法簽發公開憑證的隔離環境。若刻意設定了免密碼 sudo，請在安裝程式提示時使用 `--sudo-nopasswd`。產生的資料庫密碼會保存在 `.env` 與 `/etc/jin/database-password`（`root:root`、權限 `0600`）。與部署系統使用者同名的 PostgreSQL 管理角色必須和應用程式的 `DB_USER` 分開。

部署後檢查：

```bash
curl -fsS https://jin.example.com/up
curl -fsS https://jin.example.com/onlyoffice/healthcheck
sudo supervisorctl status
sudo systemctl --no-pager --full status nginx php8.5-fpm postgresql redis-server onlyoffice-documentserver
sudo ss -ltnp
```

除 80/443 外，任何 Jin 或 ONLYOFFICE 服務都不應監聽 `0.0.0.0` 或 `[::]`。

## 檔案內文搜尋

使用 MarkItDown 非同步擷取後，Everything 可以搜尋 `docx`、`xlsx`、`pptx` 與 `pdf` 的內文。舊版 Office、OpenDocument、加密或密碼保護的檔案、擷取失敗及超過大小限制的檔案只會依檔名搜尋。Jin 不會接收、儲存或把文件密碼傳給擷取器。

```bash
php artisan stored-files:backfill-content
php artisan stored-files:backfill-content --retry-failed
php artisan stored-files:backfill-content --reindex
```

## 安全邊界

- 檔案保存在 `storage/app/private`，只能經由授權的下載路由取得。
- Client 的可見範圍以專案為單位設定。
- 附簽章的行事曆訂閱 URL 應視同密碼保管。
- 絕不可記錄或提交 ONLYOFFICE JWT 密鑰與完整簽章 URL。
- IP 限制同樣涵蓋登入頁面與 `/up`。

```dotenv
IP_RESTRICTION_ENABLED=true
ALLOWED_IPS=203.0.113.10,198.51.100.0/24,2001:db8::/32
TRUSTED_PROXIES=10.0.0.0/8
```

信任的 Proxy 不可使用 `*`。修改後請執行 `php artisan optimize:clear`。

## 測試與品質檢查

```bash
composer ci:check
composer test
npm run lint:check
npm run format:check
npm run types:check
npm run build
```

## 疑難排解

- **所有頁面都傳回 404：** 檢查 `IP_RESTRICTION_ENABLED`、`ALLOWED_IPS` 與 `TRUSTED_PROXIES`。
- **聊天未立即同步：** 檢查 Reverb、佇列 worker、Vite、`REVERB_ALLOWED_ORIGINS`、瀏覽器主控台與 Laravel 日誌。
- **郵件未寄出：** `MAIL_MAILER=log` 只會寫入 `storage/logs/laravel.log`；正式環境需設定實際郵件驅動程式。
- **介面變更未出現：** 開發時使用 `npm run dev`，或執行 `npm run build` 後再執行 `php artisan optimize:clear`。
- **ONLYOFFICE 錯誤：** 檢查三個通訊 URL、共用 JWT 密鑰、健康檢查與回呼可達性，且不要記錄完整 JWT。

完整維運步驟請參閱[日文 README](../README.md)。
