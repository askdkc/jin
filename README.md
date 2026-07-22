# Jin

Jin（陣）は、複数のチームがプロジェクト単位で協調して仕事を進めるための、日本語グループウェアです。メッセージ、To-do、予定、資料庫、チャット、カードテーブル、定期チェックインを1つのプロジェクトに集約します。

Basecampの情報設計を参考にしていますが、名称、画面、配色、アイコンは独自のものです。詳しい設計方針と実装計画は [PLAN.md](PLAN.md) を参照してください。

## 主な機能

- チーム（ワークスペース）の作成、切り替え、メンバー招待、権限管理
- プロジェクトの作成、編集、参加者指定、期間設定、アーカイブ
- メッセージボード、コメント、購読、ピン留め、添付ファイル
- Trixによる書式付き入力とサーバー側HTML無害化
- To-doリスト、担当者、期限、サブタスク、コメント、添付、ドラッグ＆ドロップ並べ替え
- プロジェクト予定と、日・週・月・ガントの横断カレンダー
- テキストドキュメント、非公開ファイル、フォルダ、外部リンク
- ドラッグ＆ドロップ対応ファイルアップロード
- Laravel Reverbを使ったプロジェクト別リアルタイムチャット
- ドラッグ＆ドロップ対応カードテーブル（Kanban）
- 定期チェックインと回答履歴
- アクティビティ、全体検索、ラインアップ、横断レポート
- 署名付きURLによる外部カレンダー購読（iCalendar）
- メール確認、2要素認証、Passkey
- IPv4、IPv6、CIDR対応の全サイトIP制限
- スマートフォン対応レスポンシブUI

## 技術構成

| 領域           | 使用技術                                 |
| -------------- | ---------------------------------------- |
| Backend        | PHP 8.4 / Laravel 13                     |
| Frontend       | Inertia.js 3 / Svelte 5 / TypeScript     |
| UI             | Tailwind CSS 4                           |
| Rich text      | Trix 2 / Symfony HTML Sanitizer 8        |
| Realtime       | Laravel Reverb / Laravel Echo            |
| Authentication | Laravel Fortify / Passkeys / 2FA         |
| Typed routes   | Laravel Wayfinder                        |
| Queue          | Laravel database queue                   |
| Database       | SQLite（初期設定）                       |
| Test           | Pest 4 / PHPStan / Svelte Check / ESLint |

## 動作環境

- PHP 8.4.1以上とComposer 2
- Node.js 22.12以上とnpm
- PHPのSQLite拡張（初期設定で使用）
- ImageMagickとPoppler（PDFの先頭ページサムネイル生成用）
- ONLYOFFICE Docs（macOS開発とCIはDocker、Ubuntu本番はAPTパッケージ）

Office文書の表示にLibreOfficeは使いません。MySQLやPostgreSQLへ変更する場合は、対応するPHP拡張と`.env`のDB設定を別途追加してください。

## macOS開発環境

以下はHomebrewとDocker Desktopを使う手順です。Docker Desktopの対応macOSとライセンス条件は[公式インストール手順](https://docs.docker.com/desktop/setup/install/mac-install/)を確認してください。

### 1. 依存パッケージを入れる

```bash
xcode-select --install
brew install php@8.4 composer node@22 imagemagick poppler
brew install --cask docker

export PATH="$(brew --prefix php@8.4)/bin:$(brew --prefix node@22)/bin:$PATH"
php --version
composer --version
node --version
npm --version
```

`PATH`の設定は、動作確認後に同じ内容を`~/.zprofile`へ1回だけ追加してください。Docker Desktopには4 GB以上のメモリを割り当てます。Docker Desktopを起動し、Engineの準備完了を確認します。

```bash
open -a Docker

for attempt in {1..60}; do
  docker info >/dev/null 2>&1 && break
  sleep 2
done

docker info >/dev/null
```

### 2. Jinをセットアップする

新規cloneのリポジトリルートで、上から順に実行します。

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
```

既存の`.env`を更新できるよう、シェル関数を定義します。

```bash
set_env() {
  local key="$1" value="$2"

  if grep -q "^${key}=" .env; then
    sed -i '' "s|^${key}=.*|${key}=${value}|" .env
  else
    printf '%s=%s\n' "$key" "$value" >> .env
  fi
}

ONLYOFFICE_SECRET="$(openssl rand -hex 32)"
ONLYOFFICE_HOST_PORT="${ONLYOFFICE_HOST_PORT:-8081}"

set_env APP_NAME Jin
set_env APP_URL http://localhost:8000
set_env IP_RESTRICTION_ENABLED false
set_env ONLYOFFICE_ENABLED true
set_env ONLYOFFICE_DOCUMENT_SERVER_URL "http://127.0.0.1:${ONLYOFFICE_HOST_PORT}"
set_env ONLYOFFICE_PUBLIC_URL "http://127.0.0.1:${ONLYOFFICE_HOST_PORT}"
set_env APP_ONLYOFFICE_INTERNAL_URL http://host.docker.internal:8000
set_env ONLYOFFICE_JWT_SECRET "$ONLYOFFICE_SECRET"
set_env ONLYOFFICE_INSTANCE_ID jin-local

php artisan optimize:clear
```

### 3. ONLYOFFICE Docsを起動する

Docker volumeを使うため、コンテナを再作成してもDocumentServerのデータは残ります。

```bash
ONLYOFFICE_SECRET="$(sed -n 's/^ONLYOFFICE_JWT_SECRET=//p' .env)"
ONLYOFFICE_HOST_PORT="$(sed -n 's|^ONLYOFFICE_DOCUMENT_SERVER_URL=http://127.0.0.1:||p' .env)"
test "${#ONLYOFFICE_SECRET}" -ge 64
test -n "$ONLYOFFICE_HOST_PORT"

docker volume create jin-onlyoffice-data
docker volume create jin-onlyoffice-logs
docker volume create jin-onlyoffice-lib
docker volume create jin-onlyoffice-db

docker run -d \
  --name jin-onlyoffice \
  --restart unless-stopped \
  -p "127.0.0.1:${ONLYOFFICE_HOST_PORT}:80" \
  -e JWT_ENABLED=true \
  -e JWT_SECRET="$ONLYOFFICE_SECRET" \
  -e ALLOW_PRIVATE_IP_ADDRESS=true \
  -e ALLOW_META_IP_ADDRESS=false \
  -e PLUGINS_ENABLED=false \
  -v jin-onlyoffice-data:/var/www/onlyoffice/Data \
  -v jin-onlyoffice-logs:/var/log/onlyoffice \
  -v jin-onlyoffice-lib:/var/lib/onlyoffice \
  -v jin-onlyoffice-db:/var/lib/postgresql \
  onlyoffice/documentserver:9.4.0.1
```

`ALLOW_PRIVATE_IP_ADDRESS=true`は、DocumentServerからmacOS上の`host.docker.internal`へ接続するローカル開発専用設定です。公開環境では有効にしません。

初回起動には時間がかかります。healthcheckが通るまで確認します。

```bash
for attempt in {1..90}; do
  curl -fsS "http://127.0.0.1:${ONLYOFFICE_HOST_PORT}/healthcheck" && break
  sleep 2
done

curl -fsS "http://127.0.0.1:${ONLYOFFICE_HOST_PORT}/healthcheck"
```

### 4. 開発プロセスを起動する

DocumentServerからLaravelへ到達できるよう、各コマンドを別のターミナルで起動します。

ターミナル1（Laravel）:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

ターミナル2（Vite）:

```bash
npm run dev
```

ターミナル3（Queue）:

```bash
php artisan queue:work --timeout=240
```

ターミナル4（Reverb）:

```bash
php artisan reverb:start
```

期限通知と定期チェックインも動かす場合だけ、さらに起動します。

```bash
php artisan schedule:work
```

DocumentServerからLaravelへ到達できることを確認します。

```bash
docker exec jin-onlyoffice curl -fsS http://host.docker.internal:8000/up
```

ブラウザで<http://localhost:8000>を開きます。`0.0.0.0`での起動はローカル開発専用とし、信頼できないネットワークではfirewallで外部接続を拒否してください。

### サンプルユーザー

`php artisan db:seed`の実行後、次の開発専用アカウントでログインできます。

```text
メールアドレス: test@example.com
パスワード: password
```

## Ubuntu Server 24.04 / 26.04 公開環境

以下は新規サーバーでJinを`/var/www/jin`へ配置し、Nginx + PHP-FPMで公開し、同じホストにONLYOFFICE Docsの公式APTパッケージを入れる例です。Docker Engineは不要です。既存のWebサーバー、firewall、DBがある場合は、その設定を消さず差分だけ適用してください。

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

### 1. OSパッケージ、PHP 8.4、Node.js 22、Composerを入れる

```bash
sudo apt update
sudo apt install -y \
  ca-certificates curl git gnupg nginx certbot python3-certbot-nginx \
  imagemagick poppler-utils fonts-noto-cjk supervisor unzip software-properties-common

. /etc/os-release
case "$VERSION_ID" in
  24.04)
    sudo add-apt-repository -y ppa:ondrej/php
    sudo apt update
    ;;
  26.04)
    ;;
  *)
    printf 'Unsupported Ubuntu version: %s\n' "$VERSION_ID" >&2
    exit 1
    ;;
esac

sudo apt install -y \
  php8.4-cli php8.4-fpm php8.4-sqlite3 php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-intl php8.4-bcmath php8.4-gd

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
node --version
composer --version
```

Ubuntu 24.04の標準PHPは8.3のため、上記は[Ondřej SurýのPHP PPA](https://launchpad.net/~ondrej/+archive/ubuntu/php)を使います。Ubuntu 26.04ではディストリビューションのPHP 8.4パッケージを使います。Node.jsは[NodeSourceの22.x setup script](https://github.com/nodesource/distributions/blob/master/scripts/deb/setup_22.x)、Composerは[公式のchecksum検証手順](https://getcomposer.org/doc/faqs/how-to-install-composer-programmatically.md)に合わせています。

### 2. ONLYOFFICEのAPTリポジトリを追加する

Ubuntu標準リポジトリではなく、[ONLYOFFICE公式APTリポジトリ](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-ubuntu.aspx)を使います。リポジトリ名の`squeeze`は公式の指定です。

```bash
curl -fsSL https://download.onlyoffice.com/GPG-KEY-ONLYOFFICE \
  | gpg --dearmor \
  | sudo tee /usr/share/keyrings/onlyoffice.gpg >/dev/null

echo "deb [signed-by=/usr/share/keyrings/onlyoffice.gpg] https://download.onlyoffice.com/repo/debian squeeze main" \
  | sudo tee /etc/apt/sources.list.d/onlyoffice.list >/dev/null

sudo apt update
```

DocumentServerの待受portはインストール前にdebconfで`ONLYOFFICE_HOST_PORT`へ変更します。TCP 80/443以外をfirewallまたはcloud security groupで公開しないでください。

### 3. Jinを配置する

```bash
sudo install -d -o "$USER" -g www-data "$JIN_PATH"
git clone "$JIN_REPOSITORY" "$JIN_PATH"
cd "$JIN_PATH"

composer install --no-dev --optimize-autoloader
npm ci

cp .env.example .env
php artisan key:generate
```

既存サーバーへの更新では`git clone`と`cp .env.example .env`を実行しないでください。既存の`.env`と`storage/app/private`を保持し、リリース手順に合わせて`git pull`またはartifactを配置します。

### 4. ONLYOFFICE DocsをインストールしてJinを設定する

[ONLYOFFICE公式手順](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-ubuntu.aspx)のdebconf設定で、待受portとJWT secretをインストール前に固定します。次の2つのcode blockは同じshellで続けて実行してください。

```bash
ONLYOFFICE_SECRET="$(openssl rand -hex 32)"

printf 'onlyoffice-documentserver onlyoffice/ds-port select %s\n' \
  "$ONLYOFFICE_HOST_PORT" | sudo debconf-set-selections
printf '%s\n' \
  'onlyoffice-documentserver onlyoffice/jwt-enabled boolean true' \
  | sudo debconf-set-selections
printf 'onlyoffice-documentserver onlyoffice/jwt-secret password %s\n' \
  "$ONLYOFFICE_SECRET" | sudo debconf-set-selections

sudo apt install -y ttf-mscorefonts-installer onlyoffice-documentserver
```

Microsoft fontsのEULAを聞かれた場合は内容を確認して応答します。APTパッケージが`/etc/onlyoffice/documentserver/local.json`へJWT設定を保存します。同じsecretをJinの`.env`へ設定します。`ONLYOFFICE_INSTANCE_ID`は共有DocumentServer内で重複しない固定値へ変更してください。

```bash
set_env() {
  local key="$1" value="$2"

  if grep -q "^${key}=" .env; then
    sed -i "s|^${key}=.*|${key}=${value}|" .env
  else
    printf '%s=%s\n' "$key" "$value" >> .env
  fi
}

REVERB_APP_ID="$(openssl rand -hex 8)"
REVERB_APP_KEY="$(openssl rand -hex 16)"
REVERB_APP_SECRET="$(openssl rand -hex 32)"

set_env APP_NAME Jin
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://${JIN_DOMAIN}"
set_env IP_RESTRICTION_ENABLED false
set_env SESSION_SECURE_COOKIE true
set_env REVERB_APP_ID "$REVERB_APP_ID"
set_env REVERB_APP_KEY "$REVERB_APP_KEY"
set_env REVERB_APP_SECRET "$REVERB_APP_SECRET"
set_env REVERB_HOST "$JIN_DOMAIN"
set_env REVERB_PORT 443
set_env REVERB_SCHEME https
set_env REVERB_ALLOWED_ORIGINS "$JIN_DOMAIN"
set_env ONLYOFFICE_ENABLED true
set_env ONLYOFFICE_DOCUMENT_SERVER_URL "http://127.0.0.1:${ONLYOFFICE_HOST_PORT}"
set_env ONLYOFFICE_PUBLIC_URL "https://${OFFICE_DOMAIN}"
set_env APP_ONLYOFFICE_INTERNAL_URL "https://${JIN_DOMAIN}"
set_env ONLYOFFICE_JWT_SECRET "$ONLYOFFICE_SECRET"
set_env ONLYOFFICE_INSTANCE_ID jin-production
set_env ONLYOFFICE_ALLOW_DOWNLOAD false
set_env ONLYOFFICE_ALLOW_PRINT false
set_env ONLYOFFICE_ALLOW_COPY true

php artisan optimize:clear
npm run build
chmod 600 .env
unset ONLYOFFICE_SECRET REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET
```

Viteは`VITE_REVERB_*`をbuild時に埋め込むため、`.env`の本番Reverb値を設定してから`npm run build`を実行します。メール送信、DB、バックアップ先も公開前に本番用の値へ変更してください。初期値の`MAIL_MAILER=log`は実際のメールを送信しません。

公開範囲をIPで制限する場合は`IP_RESTRICTION_ENABLED=true`へ変更し、利用者だけでなくDocumentServerから見える送信元IPも`ALLOWED_IPS`へ追加します。`TRUSTED_PROXIES=*`や`ALLOWED_IPS=*`は使いません。

### 5. ONLYOFFICE Docsの起動を確認する

```bash
ONLYOFFICE_HOST_PORT="$(sed -n 's|^ONLYOFFICE_DOCUMENT_SERVER_URL=http://127.0.0.1:||p' .env)"
test -n "$ONLYOFFICE_HOST_PORT"

sudo systemctl restart ds-converter ds-docservice ds-metrics
systemctl is-active ds-converter ds-docservice

for attempt in {1..90}; do
  curl -fsS "http://127.0.0.1:${ONLYOFFICE_HOST_PORT}/healthcheck" && break
  sleep 2
done

curl -fsS "http://127.0.0.1:${ONLYOFFICE_HOST_PORT}/healthcheck"
dpkg-query -W onlyoffice-documentserver
```

DocumentServerはAPTの対象です。パッケージ更新はstagingでOffice文書の表示を確認してから本番へ適用してください。

### 6. NginxとTLSを設定する

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
    client_max_body_size 30m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
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

sudo tee /etc/nginx/sites-available/jin-onlyoffice >/dev/null <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${OFFICE_DOMAIN};

    location / {
        proxy_pass http://127.0.0.1:${ONLYOFFICE_HOST_PORT};
        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_buffering off;
    }
}
EOF

sudo ln -sfn /etc/nginx/sites-available/jin /etc/nginx/sites-enabled/jin
sudo ln -sfn /etc/nginx/sites-available/jin-onlyoffice /etc/nginx/sites-enabled/jin-onlyoffice
sudo nginx -t
sudo systemctl reload nginx

sudo certbot --nginx -d "$JIN_DOMAIN" -d "$OFFICE_DOMAIN"
sudo certbot renew --dry-run
```

証明書発行前にDNSとTCP 80の到達性が必要です。本番のBrowser → DocumentServerは必ずHTTPSにします。

### 7. DB更新、権限、常駐プロセスを設定する

```bash
cd "$JIN_PATH"
php artisan migrate --force
php artisan stored-files:backfill-checksums
php artisan stored-files:backfill-previews
php artisan optimize

sudo chgrp -R www-data storage bootstrap/cache
sudo chmod -R g+rwX storage bootstrap/cache
sudo chown "$USER":www-data .env
sudo chmod 640 .env
sudo chgrp www-data database database/database.sqlite
sudo chmod 2775 database
sudo chmod 664 database/database.sqlite
```

`stored-files:backfill-checksums`は既存Office原本のcontent versionを補完します。`stored-files:backfill-previews`がQueueへ送るのは既存PDFの先頭ページ生成だけで、Office文書はローカル変換しません。

QueueとReverbはSupervisorで常駐させます。systemdから各プロセスを直接起動せず、systemdはSupervisor自体の起動だけを管理します。

```bash
sudo tee /etc/supervisor/conf.d/jin-queue.conf >/dev/null <<EOF
[program:jin-queue]
command=/usr/bin/php ${JIN_PATH}/artisan queue:work --sleep=3 --tries=3 --timeout=240
directory=${JIN_PATH}
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=300
redirect_stderr=true
stdout_logfile=${JIN_PATH}/storage/logs/queue-worker.log
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

sudo systemctl enable --now nginx php8.4-fpm supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status jin-queue jin-reverb

printf '%s\n' \
  "* * * * * www-data cd ${JIN_PATH} && /usr/bin/php artisan schedule:run >/dev/null 2>&1" | \
  sudo tee /etc/cron.d/jin-scheduler >/dev/null
sudo chmod 644 /etc/cron.d/jin-scheduler
```

#### OS再起動時の自動起動

systemdでNginx、PHP-FPM、Supervisorを有効化します。SupervisorがQueueとReverbを起動し、ONLYOFFICEのAPTパッケージがds系serviceを管理します。独自のsystemd unitは作りません。

```bash
sudo systemctl enable nginx php8.4-fpm supervisor

systemctl is-enabled nginx php8.4-fpm supervisor
systemctl is-active ds-converter ds-docservice
sudo supervisorctl status jin-queue jin-reverb
```

期待値はNginx、PHP-FPM、Supervisorが`enabled`、ds系serviceが`active`、Supervisor側が両方`RUNNING`です。macOS開発は引き続きDocker Desktopのrestart policyを使います。

### 8. 公開前に疎通と安全性を確認する

```bash
curl -fsS "https://${JIN_DOMAIN}/up"
curl -fsS "https://${OFFICE_DOMAIN}/healthcheck"

cd "$JIN_PATH"
php artisan app:production-check
composer check-platform-reqs --no-dev
sudo systemctl --no-pager --full status nginx php8.4-fpm supervisor ds-converter ds-docservice
sudo supervisorctl status jin-queue jin-reverb
```

`app:production-check`はHTTPS、デバッグ、Queue、Reverb、Cookieなどの本番設定が安全でない場合に失敗します。

Officeプレビューが`Download failed`になる場合は、DocumentServerから`${JIN_DOMAIN}`へのDNS、TLS chain、Nginx、egress firewall、LaravelのIP制限を確認してください。

通常の更新は、既存`.env`とprivate storageを維持したまま次を実行します。

```bash
(
  set -euo pipefail

  cd "$JIN_PATH"
  php artisan down --retry=60
  sudo supervisorctl stop jin-queue jin-reverb
  git pull --ff-only
  composer install --no-dev --optimize-autoloader
  npm ci
  npm run build
  php artisan migrate --force
  php artisan stored-files:backfill-checksums
  php artisan optimize
  php artisan app:production-check
  sudo systemctl reload php8.4-fpm
  sudo supervisorctl start jin-queue jin-reverb
  php artisan up
)
```

このin-place更新では、アプリとworkerを停止してからmigrationを実行します。サブシェルは途中のコマンドが失敗した時点で停止するため、原因を直して残りの手順を完了するまでworkerの再起動と`php artisan up`は実行されません。これにより、コード更新とDB更新の間に新しいデータが書き込まれる競合を防ぎます。

DB、`storage/app/private`、`.env`、`/etc/onlyoffice/documentserver/local.json`をバックアップ対象に含めてください。DocumentServerの待受portはインターネットへ公開しません。

## ONLYOFFICE補足

Office文書はAPTパッケージ`onlyoffice-documentserver`で閲覧専用表示します。PDFと画像は既存viewerを使います。

| 種別               | 拡張子                       | `documentType` |
| ------------------ | ---------------------------- | -------------- |
| 文書               | `doc`, `docx`, `odt`         | `word`         |
| 表計算             | `xls`, `xlsx`, `xlsm`, `ods` | `cell`         |
| プレゼンテーション | `ppt`, `pptx`, `odp`         | `slide`        |

Browser、Laravel、DocumentServerで通信方向が違うため、3つのURLを分けて設定します。

| 通信                     | 設定                             | 開発例                             | 公開環境例                   |
| ------------------------ | -------------------------------- | ---------------------------------- | ---------------------------- |
| Laravel → DocumentServer | `ONLYOFFICE_DOCUMENT_SERVER_URL` | `http://127.0.0.1:8081`            | `http://127.0.0.1:8081`      |
| Browser → DocumentServer | `ONLYOFFICE_PUBLIC_URL`          | `http://127.0.0.1:8081`            | `https://office.example.com` |
| DocumentServer → Laravel | `APP_ONLYOFFICE_INTERNAL_URL`    | `http://host.docker.internal:8000` | `https://jin.example.com`    |

### DocumentServerの待受ポートを変更する

8081が使用中なら、環境構築手順の最初に値を変更します。Ubuntu本番はdebconfがAPT版の待受portへ反映し、macOS開発はDockerのホスト側portへ反映します。

```bash
export ONLYOFFICE_HOST_PORT=18081
```

`ONLYOFFICE_JWT_SECRET`とDocumentServerのJWT secretには同じ32 bytes以上の値を設定します。secret、JWT、完全な署名URLはGitやログへ出さないでください。

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
- Viewer初期化失敗: ds系service、`/var/log/onlyoffice/documentserver/`、ブラウザconsole、Nginx logを確認する。画面は認証済みダウンロードへフォールバックする。

### 公式資料

- [ONLYOFFICE Docs Ubuntu / Debian APTインストール](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-ubuntu.aspx)
- [ONLYOFFICE Docs JWT設定](https://helpcenter.onlyoffice.com/docs/installation/docs-configure-jwt.aspx)
- [ONLYOFFICE Docs Linux障害確認](https://helpcenter.onlyoffice.com/docs/installation/docs-troubleshooting-linux.aspx)
- [Docker Desktop for Mac](https://docs.docker.com/desktop/setup/install/mac-install/)

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

- 最大サイズ: 10 MB
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
- **Everything**: プロジェクト横断でコンテンツを検索
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
