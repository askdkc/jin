# Jin

[English](README_en.md) | [日本語](../README.md) | [Français](README_fr.md) | [Deutsch](README_de.md) | [简体中文](README_zh_CN.md) | [繁體中文](README_zh_TW.md) | [Español](README_es.md) | [Português (Brasil)](README_pt_BR.md) | [Português (Portugal)](README_pt_PT.md) | [한국어](README_ko.md)

Jin（陣）是一套可自行托管的项目协作群件，适合多个团队按项目共同工作。它把消息、待办事项、日程、文件、实时聊天、看板和定期检查集中到同一个项目工作区中。

<p align="center">
  <img width="100%" alt="Jin 服务介绍" src="./assets/jin-service-introduction.gif" />
</p>

## Ubuntu 24.04 / 26.04 生产部署

在 amd64 Ubuntu Server 24.04 / 26.04 上，运行 `setup.sh` 即可构建完整的 Jin 公共环境，无需手动安装或配置 Laravel 和数据库。

该脚本会安装必需的 APT 软件包、PHP 8.5、Composer 和 Node.js 24；克隆或更新 Jin；安装 Laravel 依赖；生成 `.env` 和应用密钥；执行数据库迁移和前端构建；配置 PostgreSQL/PGroonga、数据库、角色及 peer 认证；并安装 Redis、RabbitMQ、Queue Worker、Reverb、Scheduler、Supervisor、Nginx、PHP-FPM、ONLYOFFICE Docs 和文件处理工具。除非指定 `--no-ssl`，脚本还会获取 Let's Encrypt 证书并配置 HTTPS 重定向和自动续期。服务器至少需要 2 个 CPU、4 GB 内存、4 GB swap 和足够的磁盘空间。公网只应开放 80/443 和所选 SSH 端口。

```bash
sudo apt update
sudo apt install -y git
cd "$HOME"
git clone https://github.com/askdkc/jin.git
cd jin
./setup.sh
```

请以具有 sudo 权限的普通用户运行。包含 `setup.sh` 的已克隆仓库（例如 `/home/ubuntu/jin`）就是默认应用目录；只有需要其他目标目录时才使用 `--app-dir /var/www/jin`。交互式安装会依次请求 sudo 认证、公共域名、可选的 Let's Encrypt 邮箱、IP 限制、默认语言和 PostgreSQL 密码。sudo 密码由 `sudo -v` 直接处理，Jin 不会读取或保存。PostgreSQL 密码留空时会安全生成。脚本不会把用户加入 sudoers。若有意配置了免密码 sudo，请按提示使用 `--sudo-nopasswd`；`--no-ssl` 仅适用于隔离环境。生成的数据库密码保存在 `.env` 和 `/etc/jin/database-password`（`root:root`、权限 `0600`）中。与部署系统用户同名的 PostgreSQL 管理角色必须和应用的 `DB_USER` 分离。

首次交互式运行时，安装程序会询问是否按来源 IP 限制 Jin 的访问。直接按 Enter 而不输入任何内容时，将设置 `IP_RESTRICTION_ENABLED=false`，允许所有 IP 地址访问。启用限制后，请用逗号分隔输入 IP 地址或 CIDR 范围。例如，允许多个本地网络时可输入 `192.168.1.0/24, 10.0.0.0/8`。安装程序会移除空格，并在保存到 `.env` 的 `ALLOWED_IPS` 前自动加入健康检查和 ONLYOFFICE 内部通信所需的 `127.0.0.1,::1`。

随后，安装程序会显示十种语言并要求选择默认语言。可通过编号或区域设置代码选择日语、英语、法语、德语、简体中文、繁体中文、西班牙语、巴西葡萄牙语、欧洲葡萄牙语或韩语。直接按 Enter 时默认使用日语。所选值会保存到 `.env` 的 `APP_LOCALE`。

在没有 TTY 的环境中，默认允许所有 IP 访问并使用日语。自动安装时可传入以下环境变量。仅设置 `ALLOWED_IPS` 也会启用 IP 限制。

```bash
IP_RESTRICTION_ENABLED=true \
ALLOWED_IPS='192.168.1.0/24, 10.0.0.0/8' \
APP_LOCALE=zh_CN \
./setup.sh --platform ubuntu --domain jin.example.com
```

后续运行会复用现有 `.env` 中的 `IP_RESTRICTION_ENABLED`、`ALLOWED_IPS` 和 `APP_LOCALE`，不会重复询问。

部署后检查：

```bash
curl -fsS https://jin.example.com/up
curl -fsS https://jin.example.com/onlyoffice/healthcheck
sudo supervisorctl status
sudo systemctl --no-pager --full status nginx php8.5-fpm postgresql redis-server onlyoffice-documentserver
sudo ss -ltnp
```

除 80/443 外，任何 Jin 或 ONLYOFFICE 服务都不应监听 `0.0.0.0` 或 `[::]`。

## 主要功能

- 创建和切换团队（工作区）、邀请成员以及管理权限
- 创建、编辑、设定范围和日期并归档项目
- 支持评论、订阅、置顶和附件的消息板
- 使用 Trix 的富文本输入以及服务端 HTML 清理
- 待办列表、负责人、截止日期、子任务、评论、附件和拖放排序
- 项目日程以及跨项目的日、周、月和甘特图视图
- 拖放文件上传和经过授权的下载
- 基于 Laravel Reverb 的实时项目聊天
- 可拖放的卡片表格（Kanban）
- 定期检查和回答历史
- 活动时间线、跨项目报告、阵容视图以及包含受支持文件正文的全局搜索
- 带签名的只读 iCalendar 订阅
- 邮箱验证、双重认证和 Passkey
- 覆盖全站的 IPv4、IPv6 和 CIDR 限制
- 适配桌面和移动设备的响应式界面

## 安全边界

- 文件保存在 `storage/app/private`，只能通过经过授权的下载路由获取。
- Client 的可见范围按项目设置。
- 带签名的日历订阅 URL 应像密码一样保管。
- 绝不能记录或提交 ONLYOFFICE JWT 密钥和完整签名 URL。
- IP 限制同样覆盖登录页面和 `/up`。

```dotenv
IP_RESTRICTION_ENABLED=true
ALLOWED_IPS=203.0.113.10,198.51.100.0/24,2001:db8::/32
TRUSTED_PROXIES=10.0.0.0/8
```

可信代理不得使用 `*`。修改后请执行 `php artisan optimize:clear`。

## 故障排查

- **所有页面都返回 404：** 检查 `IP_RESTRICTION_ENABLED`、`ALLOWED_IPS` 和 `TRUSTED_PROXIES`。
- **聊天未立即同步：** 检查 Reverb、队列 worker、Vite、`REVERB_ALLOWED_ORIGINS`、浏览器控制台和 Laravel 日志。
- **邮件未发送：** `MAIL_MAILER=log` 只会写入 `storage/logs/laravel.log`；生产环境需配置真实邮件驱动。
- **界面变更未显示：** 开发时使用 `npm run dev`，或执行 `npm run build` 后再运行 `php artisan optimize:clear`。
- **ONLYOFFICE 错误：** 检查三个通信 URL、共享 JWT 密钥、健康检查和回调可达性，且不要记录完整 JWT。

完整的运维步骤请参阅[日文 README](../README.md)。

## 开发者信息

以下章节介绍本地开发、实现细节、集成和质量检查。若只需在 Ubuntu Server 上部署 Jin，通常不需要阅读这些内容。

### 技术栈

| 领域       | 技术                                               |
| ---------- | -------------------------------------------------- |
| 后端       | PHP 8.5 / Laravel 13                               |
| 前端       | Inertia.js 3 / Svelte 5 / TypeScript               |
| 界面       | Tailwind CSS 4                                     |
| 富文本     | Trix 2 / Symfony HTML Sanitizer 8                  |
| 实时通信   | Laravel Reverb / Laravel Echo                      |
| 身份认证   | Laravel Fortify / Passkeys / 2FA                   |
| 类型化路由 | Laravel Wayfinder                                  |
| 队列       | Laravel database queue（开发）/ Redis（生产）      |
| 数据库     | SQLite FTS5（开发）/ PostgreSQL + PGroonga（生产） |
| 测试       | Pest 4 / PHPStan / Svelte Check / ESLint           |

### 系统要求

- PHP 8.5 或更高版本以及 Composer 2
- Node.js 22.12 或更高版本以及 npm
- 用于本地开发和常规测试的 PHP SQLite 扩展
- 启用文件正文搜索时需要 Python 3.10 或更高版本以及 MarkItDown
- 用于生成 PDF 首页缩略图的 ImageMagick 和 Poppler
- 用于生产环境文件正文搜索的 PostgreSQL 和 PGroonga
- 用于 Office 只读预览的 ONLYOFFICE Docs

本地开发使用 SQLite FTS5。生产环境使用 PostgreSQL 和 PGroonga；文件正文搜索的生产配置不支持 MySQL。

### 本地快速安装

在新克隆的仓库中执行：

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

在不同终端中启动各个进程：

```bash
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
php artisan queue:work
php artisan reverb:start
```

如需截止日期通知和定期检查，请额外启动 `php artisan schedule:work`，然后访问 <http://localhost:8000>。填充数据后的开发专用示例账号为 `test@example.com`，密码为 `password`。请勿在生产环境中使用。

### macOS 与 ONLYOFFICE

受支持的 macOS 方案使用 Homebrew 和 Apple [`container`](https://github.com/apple/container)。Apple Container 需要 Apple 芯片和 macOS 26 或更高版本。

```bash
brew install php composer node@22 python imagemagick poppler
container system start
./setup.sh --platform macos --app-dir "$PWD" --no-nginx
```

启用 ONLYOFFICE 时，请设置至少 32 个字符的随机密钥，且绝不能将其提交到 Git：

```dotenv
ONLYOFFICE_ENABLED=true
ONLYOFFICE_JWT_SECRET=replace-with-at-least-32-random-characters
```

有关 localhost DNS 映射、容器生命周期和故障恢复，请参阅[权威的 macOS 说明](../README.md#macos開発環境)。

### MCP 集成

本地 Jin MCP 服务器的整个进程都以指定 Jin 用户的权限运行：

```bash
php artisan jin:mcp user@example.com
```

仅应在可信的本地环境中使用，并选择权限最小的专用账号。该用户的团队和项目权限会应用到所有 MCP 工具。

### 文件正文搜索

通过 MarkItDown 异步提取后，Everything 可以搜索 `docx`、`xlsx`、`pptx` 和 `pdf` 的正文。旧版 Office、OpenDocument、加密或受密码保护的文件、提取失败以及超出大小限制的文件仅按文件名搜索。Jin 不会接收、保存或向提取器发送文档密码。

```bash
php artisan stored-files:backfill-content
php artisan stored-files:backfill-content --retry-failed
php artisan stored-files:backfill-content --reindex
```

### 测试与质量检查

```bash
composer ci:check
composer test
npm run lint:check
npm run format:check
npm run types:check
npm run build
```
