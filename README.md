# Jin

Jin（陣）は、複数のチームがプロジェクト単位で協調して仕事を進めるための、日本語グループウェアです。メッセージ、To-do、予定、資料庫、チャット、カードテーブル、定期チェックインを1つのプロジェクトに集約します。

Basecampの情報設計を参考にしていますが、名称、画面、配色、アイコンは独自のものです。詳しい設計方針と実装計画は [PLAN.md](PLAN.md) を参照してください。

## 主な機能

- チーム（ワークスペース）の作成、切り替え、メンバー招待、権限管理
- プロジェクトの作成、編集、参加者指定、期間設定、アーカイブ
- メッセージボード、コメント、購読、ピン留め、添付ファイル
- Trixによる書式付き入力(サーバー側HTML無害化)
- To-doリスト、担当者、期限、サブタスク、コメント、添付、ドラッグ＆ドロップ並べ替え
- プロジェクト予定と、日・週・月・ガントの横断カレンダー
- ドラッグ＆ドロップ対応ファイルアップロード
- プロジェクト別リアルタイムチャット
- ドラッグ＆ドロップ対応カードテーブル（Kanban）
- 定期チェックインと回答履歴
- アクティビティ、ファイル本文を含む全体検索、ラインアップ、横断レポート
- 署名付きURLによる外部カレンダー購読（iCal）
- メール確認、2要素認証、Passkey
- IPv4、IPv6、CIDR対応の全サイトIP制限
- スマートフォン対応レスポンシブUI

## 技術構成

| 領域           | 使用技術                                              |
| -------------- | ----------------------------------------------------- |
| Backend        | PHP 8.4, 8.5 / Laravel 13                             |
| Frontend       | Inertia.js 3 / Svelte 5 / TypeScript                  |
| UI             | Tailwind CSS 4                                        |
| Rich text      | Trix 2 / Symfony HTML Sanitizer 8                     |
| Realtime       | Laravel Reverb / Laravel Echo                         |
| Authentication | Laravel Fortify / Passkeys / 2FA                      |
| Typed routes   | Laravel Wayfinder                                     |
| Queue          | Laravel database queue（開発）/ Redis（本番）         |
| Database       | SQLite FTS5（開発）/ PostgreSQL 17 + PGroonga（本番） |
| Test           | Pest 4 / PHPStan / Svelte Check / ESLint              |

## 動作環境

- PHP 8.4.1以上とComposer 2
- Node.js 22.12以上とnpm
- PHPのSQLite拡張（ローカル開発と通常テストで使用）
- Python 3.10以上とMarkItDown（ファイル本文検索を有効にする場合）
- ImageMagickとPoppler（PDFの先頭ページサムネイル生成用）
- PostgreSQL 17とPGroonga（本番のファイル本文検索で使用）
- ONLYOFFICE Docs（macOS開発はAppleの`container`、CIはGitHub Actions service、Ubuntu本番はAPTパッケージ）

ローカル開発はSQLite FTS5、本番はPostgreSQL 17とPGroongaを前提にしています。MySQLはファイル本文検索の本番構成として扱いません。

## macOS開発環境

以下はHomebrewとAppleの[`container`](https://github.com/apple/container) CLIを使う手順です。`container`はApple siliconとmacOS 26以降が必要です。[公式Releases](https://github.com/apple/container/releases)から最新の署名済み`.pkg`をインストールしてください。Docker Desktopは使用しません。

### 1. 依存パッケージを入れる

```bash
xcode-select --install
brew install php composer node@22 python imagemagick poppler

export PATH="$(brew --prefix php@8.4)/bin:$(brew --prefix node@22)/bin:$PATH"
php --version
composer --version
node --version
npm --version
container --version
```

`PATH`の設定は、動作確認後に同じ内容を`~/.zprofile`へ1回だけ追加してください。`container`のserviceを起動し、状態を確認します。

```bash
container system start
container system status
```

DocumentServerからmacOSのLaravelへ接続するため、Apple公式手順に従ってlocalhost用DNS mappingを作成します。この設定はPrivate Relayを無効化し、macOS再起動時にpacket-filter ruleが消えるため、再起動後は同じ2コマンドを再実行してください。

```bash
sudo container system dns delete host.container.internal 2>/dev/null || true
sudo container system dns create host.container.internal --localhost 203.0.113.113
```

### 2. Jinをセットアップする

新規cloneのリポジトリルートで、上から順に実行します。

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

`.env.example`にはmacOSローカル開発用のURLとファイル上限が設定済みです。ファイル本文検索を使う場合は、コピーした`.env`で機能を有効化し、実行ファイルを絶対パスで指定します。

```dotenv
STORED_FILE_CONTENT_ENABLED=true
STORED_FILE_CONTENT_MARKITDOWN_PATH=/absolute/path/to/jin/.venv-file-content/bin/markitdown
```

ONLYOFFICEを使う場合は、さらに次の2項目を設定します。

| パラメータ              | 設定値                                        |
| ----------------------- | --------------------------------------------- |
| `ONLYOFFICE_ENABLED`    | `true`                                        |
| `ONLYOFFICE_JWT_SECRET` | 32 bytes以上のランダムな値（Gitへ保存しない） |

設定変更後に`php artisan optimize:clear`を実行します。

### 3. ONLYOFFICE Docsを起動する

macOSのOnlyOffice provisioningは、リポジトリ直下の`setup.sh`を唯一の手順として使用します。スクリプトはアプリケーションのcanonical pathと`ONLYOFFICE_INSTANCE_ID`からコンテナ・named volumeを分離し、同じ構成の再実行では既存コンテナを再利用します。

```bash
./setup.sh --platform macos --app-dir "$PWD" --no-nginx
```

初回起動には時間がかかります。`setup.sh`はhealthcheckとeditor APIの両方を確認してから完了します。既存コンテナの設定を意図的に変更する場合だけ、named volumeをバックアップしたうえで`--recreate-container`を指定します。

### 4. 開発プロセスを起動する

DocumentServerからLaravelへ到達できるよう、各コマンドを別のターミナルで起動します。

ターミナル1（Laravel）:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

ターミナル2（Vite）:

```bash
npm run dev
```

ターミナル3（Queue）:

```bash
php artisan queue:work
```

Queue Workerは既定の`default` Queueだけで、通知、Broadcast、プレビュー生成、本文抽出を含むすべてのJobを処理します。ReverbとSchedulerはQueue Workerとは別プロセスなので、必要に応じて起動します。

ターミナル4（Reverb）:

```bash
php artisan reverb:start
```

期限通知と定期チェックインも動かす場合だけ、さらに起動します。

```bash
php artisan schedule:work
```

DocumentServerからLaravelへ到達できることを確認します。

`setup.sh`がDocumentServer内からLaravelの`/up`へ疎通確認を行います。失敗した場合は、Laravelを起動してからsetupのログに表示されたcontainer名で再確認します。

ブラウザで<http://localhost:8000>を開きます。Laravelは`127.0.0.1`だけで待ち受け、DocumentServerからの接続は`host.container.internal`のlocalhost mappingを経由します。

### サンプルユーザー

`php artisan db:seed`の実行後、次の開発専用アカウントでログインできます。

```text
メールアドレス: test@example.com
パスワード: password
```

## MCP連携

JinのローカルMCPサーバーは、プロセス全体を指定したJinユーザーとして実行します。MCPクライアントから利用する場合は、通常の`php artisan mcp:start jin`ではなく、必ず次のコマンドを使ってください。

```bash
php artisan jin:mcp user@example.com
```

メールアドレスで指定したユーザーのチーム・プロジェクト権限が、すべてのMCP toolへ適用されます。このコマンドは信頼できるローカル環境だけで起動し、共有アカウントや権限の強すぎるユーザーを指定しないでください。

Claude Codeなど、`.mcp.json`形式に対応するクライアントでは次のように設定できます。`artisan`はclone先の絶対パスへ、メールアドレスはMCP専用のJinユーザーへ置き換えます。

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

本番相当の`composer install --no-dev --optimize-autoloader`でも`jin:mcp`を使用できます。接続確認には`php artisan mcp:inspector jin`ではなく、固定ユーザーを設定できる上記コマンドをMCPクライアントへ登録してください。

## Ubuntu Server 26.04 公開環境

以下は新規のUbuntu Server 26.04でJinを`/var/www/jin`へ配置し、Nginx + PHP-FPMで公開し、同じホストにPostgreSQL 17、PGroonga、ONLYOFFICE Docsを入れる例です。Docker Engineは不要です。Ubuntu 26.04を本番対象として維持し、PGroongaはこの環境向けの公式APTパッケージが提供されるまで固定versionをsourceからbuildします。既存のWebサーバー、firewall、DBがある場合は、その設定を消さず差分だけ適用してください。

前提:

- `jin.example.com`と`office.example.com`のDNS A/AAAAレコードがこのサーバーを向いている
- TCP 80/443だけを外部公開する。8080と`ONLYOFFICE_HOST_PORT`は公開しない
- DocumentServer用に少なくとも2 CPU、RAM 4 GB、swap 4 GB、十分な空き容量を確保する
- 本番値とsecretをGitへコミットしない

実際の値を設定します。

```bash
export JIN_DOMAIN=jin.example.com
export OFFICE_DOMAIN=office.example.com
export JIN_REPOSITORY=https://example.com/your/jin.git
export JIN_PATH=/var/www/jin
export ONLYOFFICE_HOST_PORT=8081
```

SMTPの接続情報は後述する`.env`のパラメータへ設定します。STARTTLSを使う587番portは通常`MAIL_SCHEME=smtp`、implicit TLSを使う465番portは`MAIL_SCHEME=smtps`です。providerの指定を優先してください。localhost SMTPはこの手順では使用しません。

### 1. OSパッケージ、PostgreSQL 17、PGroonga、PHP 8.4、Node.js 22、Composerを入れる

```bash
sudo apt update
sudo apt install -y \
  build-essential ca-certificates curl git gnupg lsb-release nginx certbot \
  python3-certbot-nginx wget pkg-config python3 python3-venv \
  imagemagick poppler-utils fonts-noto-cjk supervisor unzip

sudo apt install -y postgresql-common
sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y

PGROONGA_APT_SOURCE="groonga-apt-source-latest-$(lsb_release --codename --short).deb"
wget "https://packages.groonga.org/ubuntu/${PGROONGA_APT_SOURCE}"
sudo apt install -y -V "./${PGROONGA_APT_SOURCE}"
rm -f "$PGROONGA_APT_SOURCE"
sudo apt update

sudo apt install -y \
  postgresql-17 postgresql-server-dev-17 redis-server libgroonga-dev libmsgpack-dev \
  php8.4-cli php8.4-fpm php8.4-pgsql php8.4-redis php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-intl php8.4-bcmath php8.4-gd

PGROONGA_VERSION=4.0.6
wget "https://packages.groonga.org/source/pgroonga/pgroonga-${PGROONGA_VERSION}.tar.gz"
tar xvf "pgroonga-${PGROONGA_VERSION}.tar.gz"
cd "pgroonga-${PGROONGA_VERSION}"
make HAVE_MSGPACK=1 PG_CONFIG=/usr/lib/postgresql/17/bin/pg_config
sudo make HAVE_MSGPACK=1 PG_CONFIG=/usr/lib/postgresql/17/bin/pg_config install
cd ..
rm -rf "pgroonga-${PGROONGA_VERSION}" "pgroonga-${PGROONGA_VERSION}.tar.gz"

curl -fsSL https://deb.nodesource.com/setup_22.x -o /tmp/nodesource_setup.sh
sudo -E bash /tmp/nodesource_setup.sh
sudo apt install -y nodejs

EXPECTED_CHECKSUM="$(curl -fsSL https://composer.github.io/installer.sig)"
php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
test "$EXPECTED_CHECKSUM" = "$ACTUAL_CHECKSUM"
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm -f /tmp/composer-setup.php

php --version
python3 --version
psql --version
node --version
composer --version
```

Ubuntu 26.04ではUbuntu公式repositoryのversion付きPHP 8.4 packageを使います。PostgreSQL 17はPGDG、GroongaはUbuntu 26.04（`resolute`）向けのGroonga公式APT repositoryを使います。PGroongaの公式Ubuntu install手順は現時点で26.04用packageを掲載していないため、Jinが使用する4.0.6をsourceからbuildし、PostgreSQL 17へinstallします。Node.jsは[NodeSourceの22.x setup script](https://github.com/nodesource/distributions/blob/master/scripts/deb/setup_22.x)、Composerは[公式のchecksum検証手順](https://getcomposer.org/doc/faqs/how-to-install-composer-programmatically.md)に合わせています。

### 2. Jinを配置する

```bash
sudo install -d -o "$USER" -g www-data "$JIN_PATH"
git clone "$JIN_REPOSITORY" "$JIN_PATH"
cd "$JIN_PATH"

composer install --no-dev --optimize-autoloader
python3 -m venv .venv-file-content
.venv-file-content/bin/python -m pip install --requirement requirements-file-content.txt
npm ci

cp .env.example .env
php artisan key:generate
```

既存サーバーへの更新では`git clone`と`cp .env.example .env`を実行しないでください。既存の`.env`と`storage/app/private`を保持し、リリース手順に合わせて`git pull`またはartifactを配置します。

### 3. ONLYOFFICE Docsをプロビジョニングする

UbuntuのOnlyOffice APT repository、JWT、`maxDownloadBytes`、service再起動、healthcheck、editor API確認、Jinの`.env`更新、OnlyOffice用Nginxは`setup.sh`が一元管理します。手動でrepositoryや`local.json`を書き換えず、次を実行してください。

```bash
cd "$JIN_PATH"
./setup.sh \
  --platform ubuntu \
  --app-dir "$JIN_PATH" \
  --domain "$JIN_DOMAIN" \
  --office-domain "$OFFICE_DOMAIN" \
  --onlyoffice-port "$ONLYOFFICE_HOST_PORT" \
  --nginx
```

`setup.sh`は`UPLOAD_MAX_FILE_MB`とOnlyOfficeのファイル上限も検証します。`ONLYOFFICE_JWT_SECRET`、`ONLYOFFICE_INSTANCE_ID`、その他の本番値は`.env`または環境変数で指定し、secretをコマンド履歴へ残さないでください。

`.env.example`に必要なパラメータはすべて記載されています。コピーした`.env`を直接編集し、本番環境では次の値へ変更してください。`<...>`は実際のdomain、credential、ランダムなsecretへ置き換え、Gitへコミットしません。

| パラメータ                                | 本番設定                                         |
| ----------------------------------------- | ------------------------------------------------ |
| `APP_ENV`                                 | `production`                                     |
| `APP_DEBUG`                               | `false`                                          |
| `APP_URL`                                 | `https://<JIN_DOMAIN>`                           |
| `DB_CONNECTION`                           | `pgsql`                                          |
| `DB_HOST` / `DB_PORT`                     | `127.0.0.1` / `5432`                             |
| `DB_DATABASE` / `DB_USERNAME`             | `jin` / `jin`                                    |
| `DB_PASSWORD`                             | PostgreSQLへ設定するランダムなpassword           |
| `STORED_FILE_CONTENT_ENABLED`             | `true`                                           |
| `STORED_FILE_CONTENT_MARKITDOWN_PATH`     | `/var/www/jin/.venv-file-content/bin/markitdown` |
| `REDIS_CLIENT` / `REDIS_HOST`             | `phpredis` / `127.0.0.1`                         |
| `QUEUE_CONNECTION`                        | `redis`                                          |
| `CACHE_STORE`                             | `redis`                                          |
| `SESSION_DRIVER`                          | `redis`                                          |
| `SESSION_SECURE_COOKIE`                   | `true`                                           |
| `IP_RESTRICTION_ENABLED`                  | 公開する場合は`false`、制限する場合は`true`      |
| `REVERB_APP_ID`                           | 一意のランダム値                                 |
| `REVERB_APP_KEY` / `REVERB_APP_SECRET`    | 一意のランダムなcredential                       |
| `REVERB_HOST`                             | `<JIN_DOMAIN>`                                   |
| `REVERB_PORT` / `REVERB_SCHEME`           | `443` / `https`                                  |
| `REVERB_ALLOWED_ORIGINS`                  | `<JIN_DOMAIN>`                                   |
| `MAIL_MAILER`                             | `smtp`                                           |
| `MAIL_SCHEME` / `MAIL_HOST` / `MAIL_PORT` | SMTP providerの指定値                            |
| `MAIL_USERNAME` / `MAIL_PASSWORD`         | SMTP providerのcredential                        |
| `MAIL_FROM_ADDRESS`                       | providerで検証済みの送信元address                |
| `ONLYOFFICE_ENABLED`                      | `true`                                           |
| `ONLYOFFICE_DOCUMENT_SERVER_URL`          | `http://127.0.0.1:<ONLYOFFICE_HOST_PORT>`        |
| `ONLYOFFICE_PUBLIC_URL`                   | `https://<OFFICE_DOMAIN>`                        |
| `APP_ONLYOFFICE_INTERNAL_URL`             | `https://<JIN_DOMAIN>`                           |
| `ONLYOFFICE_JWT_SECRET`                   | APTインストール時と同じ`ONLYOFFICE_SECRET`       |
| `ONLYOFFICE_INSTANCE_ID`                  | 共有DocumentServer内で一意の固定値               |

upload上限とONLYOFFICEのファイル上限は`.env.example`の200 MiB／500 MiB設定をそのまま使用します。本文抽出はsource 50 MiB、process出力16 MiB、検索index 2 MiB、process timeout 120秒、job timeout 180秒が初期値です。`STORED_FILE_CONTENT_JOB_TIMEOUT`はprocess timeoutより大きく、Redisの`retry_after`より小さくし、processing leaseはjob timeoutより大きくしてください。ダウンロード、印刷、コピーの許可も`.env.example`の値を必要に応じて変更してください。

`.env`の`DB_PASSWORD`と同じpasswordを使ってPostgreSQLのユーザーとDBを作成します。

```sql
CREATE USER jin WITH ENCRYPTED PASSWORD '<DB_PASSWORDと同じ値>';
CREATE DATABASE jin OWNER jin;
\connect jin
CREATE EXTENSION pgroonga;
```

```bash
sudo -u postgres psql
# 上のSQLを実行してから \q で終了する

php artisan optimize:clear
php artisan app:production-check
npm run build
chmod 600 .env
```

Viteは`VITE_REVERB_*`をbuild時に埋め込むため、`.env`の本番Reverb値を設定してから`npm run build`を実行します。DB、バックアップ先も公開前に本番用の値へ変更してください。初期値の`MAIL_MAILER=log`は実際のメールを送信せず、localhost SMTPもこの手順では構築しません。必ずproviderの接続情報と、そのproviderで検証済みの送信元addressを設定してください。

公開範囲をIPで制限する場合は`IP_RESTRICTION_ENABLED=true`へ変更し、利用者だけでなくDocumentServerから見える送信元IPも`ALLOWED_IPS`へ追加します。`TRUSTED_PROXIES=*`や`ALLOWED_IPS=*`は使いません。

OnlyOfficeのservice状態、設定値、healthcheck、editor APIは直前の`setup.sh`が確認します。パッケージ更新はstagingでOffice文書の表示を確認してから本番へ適用してください。

### 4. NginxとTLSを設定する

Jinは1ファイル200 MiB、1回のリクエスト合計500 MiB、最大20ファイルに制限します。multipart overheadを含めて受信できるよう、PHP-FPMとNginxのリクエスト上限は512 MiBに設定します。

```bash
sudo tee /etc/php/8.4/fpm/conf.d/99-jin-uploads.ini >/dev/null <<'EOF'
upload_max_filesize=200M
post_max_size=512M
max_file_uploads=20
max_input_time=3600
max_execution_time=3600
EOF

sudo systemctl reload php8.4-fpm
```

Jin本体とDocumentServerを別originで公開します。

```bash
ONLYOFFICE_HOST_PORT="$(sed -n 's|^ONLYOFFICE_DOCUMENT_SERVER_URL=http://127.0.0.1:||p' "${JIN_PATH}/.env")"
test -n "$ONLYOFFICE_HOST_PORT"

sudo tee /etc/nginx/sites-available/jin >/dev/null <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${JIN_DOMAIN};
    root ${JIN_PATH}/public;
    index index.php;
    client_max_body_size 512m;
    client_body_timeout 3600s;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_read_timeout 3600s;
    }

    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
    }

    location /apps {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
    }
}
EOF

sudo ln -sfn /etc/nginx/sites-available/jin /etc/nginx/sites-enabled/jin
sudo nginx -t
sudo systemctl reload nginx

sudo certbot --nginx -d "$JIN_DOMAIN"
sudo certbot renew --dry-run
```

OnlyOffice用Nginxと`OFFICE_DOMAIN`の証明書は`setup.sh --nginx`が管理します。ここではJin本体のNginxだけを設定します。証明書発行前にDNSとTCP 80の到達性が必要です。本番のBrowser → DocumentServerは必ずHTTPSにします。

### 5. DB更新、権限、常駐プロセスを設定する

```bash
cd "$JIN_PATH"
php artisan migrate --force
php artisan stored-files:backfill-checksums
php artisan stored-files:backfill-previews
php artisan stored-files:backfill-content
php artisan optimize

sudo chgrp -R www-data storage bootstrap/cache
sudo chmod -R g+rwX storage bootstrap/cache
sudo chown "$USER":www-data .env
sudo chmod 640 .env
```

`stored-files:backfill-checksums`は既存Office原本のcontent versionを補完します。`stored-files:backfill-previews`がQueueへ送るのは既存PDFの先頭ページ生成だけで、Office文書はローカル変換しません。`stored-files:backfill-content`は未処理またはchecksumが変わった対応ファイルを既定の`default` Queueへ送ります。失敗した現行ファイルを再試行する場合は`--retry-failed`、extractor更新後に全対応ファイルを再抽出する場合は`--reindex`を付けます。大量データではworker負荷を監視し、通常のbackfill完了後だけ必要なoptionを実行してください。

QueueとReverbはSupervisorで常駐させます。systemdから各プロセスを直接起動せず、systemdはSupervisor自体の起動だけを管理します。

```bash
sudo tee /etc/supervisor/conf.d/jin-queue.conf >/dev/null <<EOF
[program:jin-queue]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php ${JIN_PATH}/artisan queue:work
directory=${JIN_PATH}
user=www-data
numprocs=10
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=300
redirect_stderr=true
stdout_logfile=${JIN_PATH}/storage/logs/queue-worker-%(process_num)02d.log
EOF

sudo tee /etc/supervisor/conf.d/jin-reverb.conf >/dev/null <<EOF
[program:jin-reverb]
command=/usr/bin/php ${JIN_PATH}/artisan reverb:start --host=127.0.0.1 --port=8080
directory=${JIN_PATH}
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=30
redirect_stderr=true
stdout_logfile=${JIN_PATH}/storage/logs/reverb.log
EOF

sudo systemctl enable --now nginx php8.4-fpm postgresql redis-server supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status "jin-queue:*" jin-reverb

printf '%s\n' \
  "* * * * * www-data cd ${JIN_PATH} && /usr/bin/php artisan schedule:run >/dev/null 2>&1" | \
  sudo tee /etc/cron.d/jin-scheduler >/dev/null
sudo chmod 644 /etc/cron.d/jin-scheduler
```

`jin-queue`は10プロセスで既定の`default` Queueを共有し、通知、Broadcast、PDFプレビュー生成、本文抽出を含む全Jobを並列処理します。Job固有の`tries`と`timeout`は各Jobクラスに設定されており、最長Jobタイムアウト240秒（プレビュー生成）を`stopwaitsecs=300`が上回ります。CPUコア数、RAM、PDFサイズを監視し、重いJobが10プロセスを占有して通知やリアルタイム配信が待機する場合は`numprocs`を下げてください。本番構成はPostgreSQLとRedis Queue/Cache/Sessionを前提とします。SQLiteはローカル開発専用で、SQLiteを使う検証環境では各workerを1 processに抑えてください。

#### OS再起動時の自動起動

systemdでNginx、PHP-FPM、Supervisorを有効化します。SupervisorがQueueとReverbを起動し、ONLYOFFICEのAPTパッケージがds系serviceを管理します。独自のsystemd unitは作りません。

```bash
sudo systemctl enable nginx php8.4-fpm postgresql redis-server supervisor

systemctl is-enabled nginx php8.4-fpm supervisor
systemctl is-active ds-converter ds-docservice
sudo supervisorctl status "jin-queue:*" jin-reverb
```

期待値はNginx、PHP-FPM、Supervisorが`enabled`、ds系serviceが`active`、Supervisor配下の全プロセスが`RUNNING`です。macOS開発ではOS再起動後に`container system start`、localhost用DNS mappingの再作成、setupのログに表示されたcontainerの`container start`を実行します。

### 6. 公開前に疎通と安全性を確認する

```bash
curl -fsS "https://${JIN_DOMAIN}/up"
curl -fsS "https://${OFFICE_DOMAIN}/healthcheck"

cd "$JIN_PATH"
php artisan app:production-check
composer check-platform-reqs --no-dev

SMTP_SMOKE_TEST_RECIPIENT=recipient@example.com php artisan tinker --execute '\Illuminate\Support\Facades\Mail::raw("Jin SMTP smoke test", function (\Illuminate\Mail\Message $message): void { $message->to((string) getenv("SMTP_SMOKE_TEST_RECIPIENT"))->subject("Jin SMTP smoke test"); });'

sudo systemctl --no-pager --full status nginx php8.4-fpm supervisor ds-converter ds-docservice
sudo supervisorctl status "jin-queue:*" jin-reverb
```

`app:production-check`はHTTPS、デバッグ、Queue、Reverb、Cookie、upload上限が安全でない場合に失敗します。ファイル本文検索を有効にした場合は、5つのextractor command、timeoutとsizeの関係、Queueの`retry_after`、PostgreSQL上のPGroonga extensionも確認しますが、利用者ファイルを解析するprobeは実行しません。SMTPを使う場合はlocalhost、port、scheme、credentialの未設定も拒否しますが、providerへの接続と受信は保証しません。smoke test mailが指定した受信箱へ届き、送信元domainのSPF、DKIM、DMARCがproviderの要件を満たすことまで確認してください。ONLYOFFICEを有効にした場合は、JinのOffice file上限がupload上限または宣言したDocumentServerの`maxDownloadBytes`を超えていても失敗します。

Officeプレビューが`Download failed`になる場合は、DocumentServerから`${JIN_DOMAIN}`へのDNS、TLS chain、Nginx、egress firewall、LaravelのIP制限を確認してください。

通常の更新は、既存`.env`とprivate storageを維持したまま次を実行します。

```bash
(
  set -euo pipefail

  cd "$JIN_PATH"
  php artisan down --retry=60
  sudo supervisorctl stop "jin-queue:*" jin-reverb
  git pull --ff-only
  composer install --no-dev --optimize-autoloader
  python3 -m venv --upgrade-deps .venv-file-content
  .venv-file-content/bin/python -m pip install --requirement requirements-file-content.txt
  npm ci
  npm run build
  php artisan optimize:clear
  php artisan app:production-check
  php artisan migrate --force
  php artisan stored-files:backfill-checksums
  php artisan stored-files:backfill-content
  php artisan optimize
  sudo systemctl reload php8.4-fpm
  sudo supervisorctl start "jin-queue:*" jin-reverb
  php artisan up
)
```

このin-place更新では、アプリとworkerを停止してからmigrationを実行します。サブシェルは途中のコマンドが失敗した時点で停止するため、原因を直して残りの手順を完了するまでworkerの再起動と`php artisan up`は実行されません。これにより、コード更新とDB更新の間に新しいデータが書き込まれる競合を防ぎます。

### 旧専用キューからの移行

旧構成（`jin-broadcasts`、`jin-notifications`、`jin-previews`、`jin-file-content`、`jin-default`）を使っている既存サーバーでは、`php artisan queue:work`は`default` Queueしか取得しないため、旧キュー名に保存済みのJobは処理されません。Supervisor設定の切り替え前に、次の順序で旧キューを一度だけ空にします。

1. `php artisan down --retry=60`でアプリケーションをMaintenance Modeへ移行する。
2. 旧Supervisorワーカーを停止する。

   ```bash
   sudo supervisorctl stop "jin-broadcasts:*" "jin-notifications:*" "jin-previews:*" "jin-file-content:*" "jin-default:*" jin-reverb
   ```

3. 旧キュー名を指定した一時ワーカーで保存済みJobを処理する。

   ```bash
   sudo -u www-data php artisan queue:work redis --queue=broadcasts,notifications,previews,file-content,default --stop-when-empty --force
   ```

4. アプリケーションコードを更新し、`/etc/supervisor/conf.d/jin-queue.conf`を新しい単一プログラム構成へ置き換える。`jin-queue.conf`はGit管理外のため、Git更新だけでは変更されません。`git pull`後に次のコマンドで再作成してください。

   ```bash
   sudo tee /etc/supervisor/conf.d/jin-queue.conf >/dev/null <<EOF
   [program:jin-queue]
   process_name=%(program_name)s_%(process_num)02d
   command=/usr/bin/php ${JIN_PATH}/artisan queue:work
   directory=${JIN_PATH}
   user=www-data
   numprocs=10
   autostart=true
   autorestart=true
   stopasgroup=true
   killasgroup=true
   stopwaitsecs=300
   redirect_stderr=true
   stdout_logfile=${JIN_PATH}/storage/logs/queue-worker-%(process_num)02d.log
   EOF
   ```

5. `sudo supervisorctl reread`と`sudo supervisorctl update`を実行して旧プログラムを除去し、`jin-queue`を登録する。
6. `sudo supervisorctl status "jin-queue:*"`で10プロセス（`jin-queue_00`〜`jin-queue_09`）がすべて`RUNNING`であることを確認する。
7. `php artisan up`でMaintenance Modeを解除する。

旧プログラムの定義が`jin-queue.conf`に残っている場合は、`supervisorctl update`前に`rm /etc/supervisor/conf.d/jin-queue.conf`で置き換えてください。

`personal_note_attachments.deleted_at`を削除するreleaseへrollbackする場合は、先にattachmentの物理削除を完了します。アプリと通常workerを停止した状態で次を実行し、tombstoneが0件になってからmigrationをrollbackしてください。

```bash
sudo -u www-data php artisan personal-note-attachments:prune
sudo -u www-data php artisan queue:work redis --queue=default --stop-when-empty --tries=10 --timeout=120
php artisan tinker --execute 'throw_if(\App\Models\PersonalNoteAttachment::onlyTrashed()->exists(), \RuntimeException::class, "Personal-note attachment cleanup is incomplete.");'
```

cleanup未完了のrowがある場合、migration自身もrollbackを拒否します。失敗したblob削除を解決して上記を再実行し、rowを暗黙に復活させないでください。

DB、`storage/app/private`、`.env`、`/etc/onlyoffice/documentserver/local.json`をバックアップ対象に含めてください。DocumentServerの待受portはインターネットへ公開しません。

## ファイル本文検索

Everythingはファイル名に加えて、非同期抽出が完了した次の形式の本文を検索します。

| 形式       | 拡張子 | extractor  |
| ---------- | ------ | ---------- |
| Word       | `docx` | MarkItDown |
| Excel      | `xlsx` | MarkItDown |
| PowerPoint | `pptx` | MarkItDown |
| PDF        | `pdf`  | MarkItDown |

`doc`、`xls`、`ppt`、`xlsm`、`odt`、`ods`、`odp`とその他の形式はファイル名だけを検索します。暗号化またはpassword保護されたPDF／Officeファイルもファイル名だけを検索し、passwordを受け取ったり保存したりextractorへ渡したりしません。保護、失敗、上限超過、古いgenerationのpartial outputはindexへ公開しません。

既存ファイルを登録するcommand:

```bash
php artisan stored-files:backfill-content
php artisan stored-files:backfill-content --retry-failed
php artisan stored-files:backfill-content --reindex
```

通常はoptionなしを実行します。`--retry-failed`は現行checksumで失敗した行だけを再試行し、`--reindex`はextractorや正規化処理を更新した時に全対応ファイルを再抽出します。旧形式の本文抽出を使用していた環境では、更新後に`--reindex`を1回実行して既存の`doc`、`xls`、`ppt`本文indexを削除してください。

## ONLYOFFICE補足

Office文書は、macOS開発ではApple `container`上の公式OCI image、Ubuntu公開環境ではAPTパッケージ`onlyoffice-documentserver`を使って閲覧専用表示します。PDFと画像は既存viewerを使います。

| 種別               | 拡張子                       | `documentType` |
| ------------------ | ---------------------------- | -------------- |
| 文書               | `doc`, `docx`, `odt`         | `word`         |
| 表計算             | `xls`, `xlsx`, `xlsm`, `ods` | `cell`         |
| プレゼンテーション | `ppt`, `pptx`, `odp`         | `slide`        |

Browser、Laravel、DocumentServerで通信方向が違うため、3つのURLを分けて設定します。

| 通信                     | 設定                             | 開発例                                | 公開環境例                   |
| ------------------------ | -------------------------------- | ------------------------------------- | ---------------------------- |
| Laravel → DocumentServer | `ONLYOFFICE_DOCUMENT_SERVER_URL` | `http://127.0.0.1:8081`               | `http://127.0.0.1:8081`      |
| Browser → DocumentServer | `ONLYOFFICE_PUBLIC_URL`          | `http://127.0.0.1:8081`               | `https://office.example.com` |
| DocumentServer → Laravel | `APP_ONLYOFFICE_INTERNAL_URL`    | `http://host.container.internal:8000` | `https://jin.example.com`    |

### DocumentServerの待受ポートを変更する

8081が使用中なら、環境構築手順の最初に値を変更します。Ubuntu本番はdebconfがAPT版の待受portへ反映し、macOS開発はApple `container`の公開portへ反映します。

```bash
export ONLYOFFICE_HOST_PORT=18081
```

`ONLYOFFICE_JWT_SECRET`とDocumentServerのJWT secretには同じ32 bytes以上の値を設定します。secret、JWT、完全な署名URLはGitやログへ出さないでください。

### File size上限を一致させる

Jinの`ONLYOFFICE_MAX_FILE_SIZE_BYTES`は、`UPLOAD_MAX_FILE_MB`をbytes換算した値とDocumentServerの`FileConverter.converter.maxDownloadBytes`の両方以下にします。標準構成では3つを200 MiBに揃えます。

```json
{
    "FileConverter": {
        "converter": {
            "maxDownloadBytes": 209715200
        }
    }
}
```

`/etc/onlyoffice/documentserver/local.json`にはJWTなどの既存設定があるため、ファイル全体を置き換えずこのkeyだけをmergeしてください。`setup.sh`がmacOS・Ubuntuのmergeと再起動を行い、JSONはatomicに更新します。Jin側の`ONLYOFFICE_DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES`はDocumentServer設定と一致するようsetupが更新します。手動変更した場合は同じ値へ更新してから`php artisan app:production-check`を実行してください。

APT版の再起動とログ確認:

```bash
sudo systemctl restart ds-converter ds-docservice ds-metrics
sudo systemctl --no-pager --full status ds-converter ds-docservice
sudo journalctl -u ds-converter -u ds-docservice -n 200 --no-pager
```

### 障害確認

- `503`: `ONLYOFFICE_ENABLED`、3つのURL、JWT secret、選択したホスト側portの`/healthcheck`を確認する。
- `Download failed`: DocumentServerが`APP_ONLYOFFICE_INTERNAL_URL`へ到達できるか確認する。
- `JWT validation failed`: JinとDocumentServerのsecretが一致するか確認する。secretやJWT全文をログへ出さない。
- Mixed Content: JinがHTTPSなら`ONLYOFFICE_PUBLIC_URL`もHTTPSにする。
- 古い内容が出る: StorageをDB外から直接上書きせず、Jinの「新しいバージョンに差し替え」を使う。更新後に`php artisan optimize:clear`を実行する。
- Viewer初期化失敗: macOS開発ではsetupのログに表示されたcontainerの`container logs`、Ubuntu公開環境ではds系serviceと`/var/log/onlyoffice/documentserver/`を確認し、あわせてブラウザconsoleとNginx logを確認する。画面は認証済みダウンロードへフォールバックする。

### 公式資料

- [ONLYOFFICE Docs Ubuntu / Debian APTインストール](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-ubuntu.aspx)
- [ONLYOFFICE Docs JWT設定](https://helpcenter.onlyoffice.com/docs/installation/docs-configure-jwt.aspx)
- [ONLYOFFICE Docs Linux障害確認](https://helpcenter.onlyoffice.com/docs/installation/docs-troubleshooting-linux.aspx)
- [Apple container](https://github.com/apple/container)
- [Apple container How-to](https://github.com/apple/container/blob/main/docs/how-to.md)

## 使い方

### 1. チームを用意する

ログイン後、ヘッダーのチーム切り替えメニューまたは「設定 > チーム」からチームを作成します。チームは会社、部署、顧客対応単位など、共通のプロジェクトを管理するワークスペースです。

OwnerまたはAdminは、メールアドレスを指定してメンバーを招待できます。ローカルの初期設定ではメールが `storage/logs/laravel.log` に記録されるため、実際に送信するには `.env` のメール設定を変更してください。

### 2. 権限を割り当てる

| ロール | 操作範囲                                                                               |
| ------ | -------------------------------------------------------------------------------------- |
| Owner  | チーム、メンバー、招待、全プロジェクトを管理                                           |
| Admin  | チーム設定、招待、全プロジェクトを管理                                                 |
| Member | 明示的に参加したプロジェクトを閲覧・操作                                               |
| Client | 個別に許可されたプロジェクト、または「すべてのClientに公開」のプロジェクトを閲覧・操作 |

Clientへの公開境界はプロジェクト単位です。Clientに許可したプロジェクト内では、メッセージ、To-do、予定、資料庫、チャットなどの内容を共有します。

### 3. プロジェクトを作る

「プロジェクト > 新規プロジェクト」から次を設定します。

1. プロジェクト名と説明
2. 開始日と終了日
3. 参加するチームメンバー
4. すべてのClientに公開するかどうか

プロジェクトの作成、編集、アーカイブはOwnerまたはAdminが行えます。MemberとClientは許可されたプロジェクトに参加できます。

### 4. プロジェクトホームから各ツールを使う

プロジェクトを開くと、最近の情報をまとめたホーム画面が表示されます。

- **メッセージボード**: 告知や議論を投稿します。コメント、購読、ピン留め、ファイル添付に対応しています。
- **To-do**: リストを作り、担当者と期限を設定します。タスクとカードはドラッグ＆ドロップで移動できます。
- **スケジュール**: 終日または時刻付きの予定を登録します。
- **資料庫**: ドキュメント、ファイル、フォルダ、外部リンクをまとめます。
- **チャット**: プロジェクト参加者とリアルタイムで会話します。Reverbが停止中でも送信内容はDBに保存されますが、即時配信にはReverbとQueueの両方が必要です。
- **カードテーブル**: 列とカードを作り、Kanban形式で進捗を管理します。
- **チェックイン**: 定期的な質問を設定し、メンバーの回答を蓄積します。
- **アクティビティ**: プロジェクト内の主な変更履歴を確認します。

掲示板、コメント、To-do詳細、カード説明、資料文書、予定詳細、チェックイン回答、個人メモはTrixで書式を付けられます。ファイルはエディター内へ貼り付けず、各画面のドラッグ＆ドロップ添付を使います。

### 5. ファイルをアップロードする

「資料庫 > ファイル」またはメッセージ・To-doの添付欄を開き、ファイルを点線の領域へドロップします。領域をクリックしてファイル選択画面から選ぶこともできます。

- 最大サイズ: 1ファイル200 MiB、1回の合計500 MiB、最大20ファイル
- 対応形式: PDF、TXT、CSV、JPEG、PNG、WebP、Word、Excel、PowerPoint、ZIP
- 保存先: `storage/app/private`

ファイルは公開ディレクトリへ置かれません。認可済みのダウンロードルートを経由して取得します。

### 6. 全プロジェクトを横断して確認する

ヘッダーから次の画面を利用できます。

- **ダッシュボード**: 自分のTo-do、近日予定、参加プロジェクトを確認
- **プロジェクト**: プロジェクト一覧、作成、編集、アーカイブ
- **カレンダー**: 日、週、月、ガント表示を切り替えて予定と期間を確認
- **ラインアップ**: 複数プロジェクトの期間を時系列で確認
- **アクティビティ**: アクセス可能なプロジェクトの更新を時系列で確認
- **Everything**: プロジェクト横断でコンテンツと対応ファイルの本文を検索
- **レポート**: 未完了、期限超過、未割り当て、最近の完了数、担当者別負荷を確認

### 7. 外部カレンダーで購読する

「カレンダー > カレンダー購読」で名前を入力し、「購読URLを発行」を押します。発行されたURLをApple Calendar、Google Calendarなどの外部カレンダーへ登録すると、アクセス可能なプロジェクト予定を読み取り専用で購読できます。

購読URLには署名が含まれるため、パスワードと同様に扱ってください。漏えいした場合はJin上で購読を解除し、新しいURLを発行します。

## IPアドレス制限

IP制限はグローバルMiddlewareとして、ログイン画面と `/up` を含むサイト全体に適用されます。許可されていないアクセスには `404 Not Found` を返します。

社内限定で使う場合:

```dotenv
IP_RESTRICTION_ENABLED=true
ALLOWED_IPS=203.0.113.10,198.51.100.0/24,2001:db8::/32
```

グローバルに公開する場合:

```dotenv
IP_RESTRICTION_ENABLED=false
```

ロードバランサーやリバースプロキシ配下では、信頼するプロキシだけを指定します。`*` は使用しないでください。

```dotenv
TRUSTED_PROXIES=10.0.0.0/8
```

設定変更後はキャッシュを更新します。

```bash
php artisan optimize:clear
```

## テストと品質確認

ローカルで実行できる品質チェックをまとめて実行します。

```bash
composer ci:check
```

PHP側のフォーマット、静的解析、Pestだけを実行します。

```bash
composer test
```

フロントエンドを確認します。

```bash
npm run lint:check
npm run format:check
npm run types:check
npm run build
```

特定のPestテストだけを実行する場合:

```bash
php artisan test --compact --filter=Project
```

## トラブルシューティング

### すべての画面が404になる

`IP_RESTRICTION_ENABLED` と `ALLOWED_IPS` を確認してください。プロキシ配下では `TRUSTED_PROXIES` が正しくないと、Laravelが利用者のIPを解決できません。

### チャットが別ブラウザへ即時反映されない

次を確認してください。

1. `php artisan reverb:start` が起動している
2. `php artisan queue:work` が起動している
3. `npm run dev` が起動している
4. `REVERB_ALLOWED_ORIGINS` にブラウザのホスト名が含まれている
5. ViteのコンソールとLaravelログに接続エラーがない

### 招待メールや通知が届かない

初期設定の `MAIL_MAILER=log` はメールを送信しません。ローカルでは `storage/logs/laravel.log` を確認し、本番ではSMTPなどのメールドライバーを設定してください。

### 画面変更が反映されない

開発中は`npm run dev`を起動してください。本番相当の確認では次を実行します。

```bash
npm run build
php artisan optimize:clear
```
