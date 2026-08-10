# Jin

## Languages

[English](README_en.md) | [日本語](../README.md) | [Français](README_fr.md) | [Deutsch](README_de.md) | [简体中文](README_zh_CN.md) | [繁體中文](README_zh_TW.md) | [Español](README_es.md) | [Português (Brasil)](README_pt_BR.md) | [Português (Portugal)](README_pt_PT.md) | [한국어](README_ko.md)

Jin (陣) is self-hosted groupware for multiple teams working together on projects. It brings messages, to-dos, schedules, files, real-time chat, card tables, and recurring check-ins into one project workspace.

Its information architecture is inspired by Basecamp, while the names, screens, colors, and icons are original. The [Japanese README](../README.md) is the canonical, complete operations guide. This translation covers the product, requirements, setup paths, security model, and verification commands.

## Main features

- Create and switch teams (workspaces), invite members, and manage permissions
- Create, edit, scope, date, and archive projects
- Message boards with comments, subscriptions, pins, and attachments
- Rich-text input with Trix and server-side HTML sanitization
- To-do lists, assignees, due dates, subtasks, comments, attachments, and drag-and-drop sorting
- Project schedules plus cross-project day, week, month, and Gantt views
- Drag-and-drop file uploads and authenticated downloads
- Real-time project chat using Laravel Reverb
- Drag-and-drop card tables (Kanban)
- Recurring check-ins and answer history
- Activity feeds, cross-project reports, lineup, and full-text search including supported file contents
- Signed read-only iCalendar subscriptions
- Email verification, two-factor authentication, and passkeys
- Site-wide IPv4, IPv6, and CIDR restrictions
- Responsive UI for desktop and mobile

## Technology stack

| Area           | Technology                                                     |
| -------------- | -------------------------------------------------------------- |
| Backend        | PHP 8.5 / Laravel 13                                           |
| Frontend       | Inertia.js 3 / Svelte 5 / TypeScript                           |
| UI             | Tailwind CSS 4                                                 |
| Rich text      | Trix 2 / Symfony HTML Sanitizer 8                              |
| Realtime       | Laravel Reverb / Laravel Echo                                  |
| Authentication | Laravel Fortify / Passkeys / 2FA                               |
| Typed routes   | Laravel Wayfinder                                              |
| Queue          | Laravel database queue (development) / Redis (production)      |
| Database       | SQLite FTS5 (development) / PostgreSQL + PGroonga (production) |
| Tests          | Pest 4 / PHPStan / Svelte Check / ESLint                       |

## Requirements

- PHP 8.5 or later and Composer 2
- Node.js 22.12 or later and npm
- PHP SQLite extension for local development and normal tests
- Python 3.10 or later and MarkItDown when file-content search is enabled
- ImageMagick and Poppler for first-page PDF thumbnails
- PostgreSQL and PGroonga for production file-content search
- ONLYOFFICE Docs for read-only Office previews

Local development uses SQLite FTS5. Production uses PostgreSQL with PGroonga; MySQL is not a supported production configuration for file-content search.

## Quick local setup

From a fresh clone:

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

Start the development processes in separate terminals:

```bash
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
php artisan queue:work
php artisan reverb:start
```

Run `php artisan schedule:work` as an additional process when due-date notifications and recurring check-ins are required. Open <http://localhost:8000>.

After seeding, the development-only sample account is:

```text
Email: test@example.com
Password: password
```

Do not use this account or password in production.

## macOS and ONLYOFFICE

The supported macOS path uses Homebrew and Apple [`container`](https://github.com/apple/container). Apple Container requires Apple silicon and macOS 26 or later. Install the latest signed package from the [official releases](https://github.com/apple/container/releases), then run:

```bash
brew install php composer node@22 python imagemagick poppler
container system start
./setup.sh --platform macos --app-dir "$PWD" --no-nginx
```

Set these values in `.env` when ONLYOFFICE is enabled:

```dotenv
ONLYOFFICE_ENABLED=true
ONLYOFFICE_JWT_SECRET=replace-with-at-least-32-random-characters
```

Never commit the JWT secret. `setup.sh` checks both the DocumentServer health endpoint and its editor API before completing. See the [canonical macOS instructions](../README.md#macos開発環境) for localhost DNS mapping, container lifecycle, and recovery details.

## MCP integration

The local Jin MCP server runs the entire process as a fixed Jin user. Use the dedicated command instead of the generic `mcp:start` command:

```bash
php artisan jin:mcp user@example.com
```

The selected user's team and project permissions apply to every MCP tool. Run this only in a trusted local environment and use a dedicated least-privilege account.

Example `.mcp.json` client configuration:

```json
{
    "mcpServers": {
        "jin": {
            "command": "php",
            "args": [
                "/absolute/path/to/jin/artisan",
                "jin:mcp",
                "user@example.com"
            ]
        }
    }
}
```

## Ubuntu 24.04 / 26.04 production setup

`setup.sh` provisions Jin, PHP, PostgreSQL/PGroonga, Redis, Node.js, MarkItDown, Nginx, Supervisor, TLS, and ONLYOFFICE Docs. It is designed to be rerun for updates while preserving `.env`, secrets, the database, and private storage.

The supported production host is amd64 Ubuntu Server. Before running setup:

- Point the domain's DNS A/AAAA record to the server
- Expose only TCP 80/443 publicly, plus the chosen SSH port
- Provide at least 2 CPUs, 4 GB RAM, 4 GB swap, and adequate disk space
- Back up and inspect existing Nginx, PostgreSQL, and port usage on non-fresh hosts
- Never commit production credentials or secrets

Initial setup:

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

Add `--email admin@example.com` for Let's Encrypt registration and expiry notices. Use `--no-ssl` only for an isolated environment where a public certificate cannot be issued. If passwordless sudo is intentionally configured, rerun with `--sudo-nopasswd` when setup instructs you to do so.

On the first interactive run, setup asks whether access to Jin should be restricted by source IP. Pressing Enter without typing anything sets `IP_RESTRICTION_ENABLED=false` and allows access from every IP address. When restriction is enabled, enter IP addresses or CIDR ranges separated by commas. For example, to allow multiple local networks, enter `192.168.1.0/24, 10.0.0.0/8`. Setup removes whitespace and prepends `127.0.0.1,::1`, which are required for its health checks and internal ONLYOFFICE traffic, before saving the result to `ALLOWED_IPS` in `.env`.

Setup then displays ten languages and asks for the default. Select Japanese, English, French, German, Simplified Chinese, Traditional Chinese, Spanish, Brazilian Portuguese, European Portuguese, or Korean by number or locale code. Pressing Enter without a selection defaults to Japanese. The selected value is saved to `APP_LOCALE` in `.env`.

Without a TTY, the defaults are unrestricted IP access and Japanese. For an automated installation, pass environment variables as follows. Supplying `ALLOWED_IPS` by itself also enables IP restriction.

```bash
IP_RESTRICTION_ENABLED=true \
ALLOWED_IPS='192.168.1.0/24, 10.0.0.0/8' \
APP_LOCALE=en \
./setup.sh --platform ubuntu --domain jin.example.com --app-dir /var/www/jin
```

Subsequent runs reuse `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS`, and `APP_LOCALE` from the existing `.env` and do not repeat these questions.

The generated database password is stored in `.env` and in `/etc/jin/database-password` as `root:root` with mode `0600`. Setup also creates a PostgreSQL `LOGIN SUPERUSER` role matching the deployment OS user for local peer administration. Keep that role separate from the application's `DB_USER` and tightly control shell access to the deployment account.

Post-deployment checks:

```bash
curl -fsS https://jin.example.com/up
curl -fsS https://jin.example.com/onlyoffice/healthcheck
sudo supervisorctl status
sudo systemctl --no-pager --full status nginx php8.5-fpm postgresql redis-server onlyoffice-documentserver
sudo ss -ltnp
```

Jin and ONLYOFFICE listeners other than 80/443 must not bind to `0.0.0.0` or `[::]`. The default setup binds DocumentServer to `127.0.0.1:8081` and the Laravel callback endpoint to `127.0.0.1:8090`.

## File-content search

After asynchronous extraction, Everything can search inside `docx`, `xlsx`, `pptx`, and `pdf` files through MarkItDown. Legacy Office formats, OpenDocument formats, encrypted or password-protected files, failed extractions, oversized files, and partial output from obsolete generations are searched by filename only. Jin never accepts, stores, or sends document passwords to the extractor.

Backfill commands:

```bash
php artisan stored-files:backfill-content
php artisan stored-files:backfill-content --retry-failed
php artisan stored-files:backfill-content --reindex
```

Run the command without options normally. Use `--retry-failed` only for failed rows at the current checksum and `--reindex` after changing the extractor or normalization logic.

## Security boundaries

- Files remain under `storage/app/private` and are served only through authorized download routes.
- Client visibility is scoped per project.
- Calendar subscription URLs contain signatures and must be handled like passwords.
- ONLYOFFICE JWT secrets and fully signed URLs must never be committed or logged.
- Database notifications remain authorization-filtered after project access is revoked.
- Site-wide IP restrictions also cover the login page and `/up`.

Example private-network restriction:

```dotenv
IP_RESTRICTION_ENABLED=true
ALLOWED_IPS=203.0.113.10,198.51.100.0/24,2001:db8::/32
TRUSTED_PROXIES=10.0.0.0/8
```

Do not use `*` for trusted proxies. Run `php artisan optimize:clear` after changing these values.

## Tests and quality checks

```bash
composer ci:check
composer test
npm run lint:check
npm run format:check
npm run types:check
npm run build
```

Run a focused Pest test with:

```bash
php artisan test --compact --filter=Project
```

## Troubleshooting

- **Every page returns 404:** check `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS`, and `TRUSTED_PROXIES`.
- **Chat is not delivered immediately to another browser:** verify Reverb, the queue worker, Vite, `REVERB_ALLOWED_ORIGINS`, browser-console errors, and Laravel logs.
- **Invitations or notifications are not emailed:** `MAIL_MAILER=log` writes locally to `storage/logs/laravel.log`; configure a production mail driver to send email.
- **UI changes do not appear:** run `npm run dev` during development, or run `npm run build` and `php artisan optimize:clear` for a production-style check.
- **ONLYOFFICE returns 503 or Download failed:** verify the enabled flag, all three directional URLs, matching JWT secrets, health endpoint, callback reachability, and service logs. Never log the complete JWT or signed URL.

For the complete operational reference, including exact macOS DNS mapping, Ubuntu update behavior, ONLYOFFICE file-size alignment, role details, and end-user workflows, use the [Japanese README](../README.md).
