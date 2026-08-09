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
| Backend        | PHP 8.5 / Laravel 13                                  |
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

- PHP 8.5以上とComposer 2
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

export PATH="$(brew --prefix php)/bin:$(brew --prefix node@22)/bin:$PATH"
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

## Ubuntu Server 24.04 / 26.04 公開環境

`setup.sh`は、Jin本体、PHP 8.5、PostgreSQL/PGroonga、Redis、Node.js 24、MarkItDown、Nginx、Supervisor、TLS、ONLYOFFICE Docsをまとめて構築するUbuntu Server用ワンショットプロビジョナーです。同じコマンドを再実行して更新でき、既存の`.env`、生成済みsecret、database、private storageを保持します。

対応環境はamd64版Ubuntu Serverです。ONLYOFFICE Docsの公式APT packageがamd64向けであるため、arm64ホストは対象外です。

| Ubuntu | PostgreSQL / PGroonga | PHP | Node.js |
| --- | --- | --- | --- |
| 24.04 LTS | PostgreSQL 16 / `postgresql-16-pgroonga` | 8.5 | 24 |
| 26.04 | PostgreSQL 18 / `postgresql-18-pgdg-pgroonga` | 8.5 | 24 |

### 前提

- `jin.example.com`のDNS A/AAAA recordをサーバーへ向ける
- TCP 80/443だけを外部公開する
- 少なくとも2 CPU、RAM 4 GB、swap 4 GBと十分な空き容量を用意する
- 既存サーバーへ導入する場合は、Nginx、PostgreSQL cluster、port使用状況を事前に確認する
- 本番credentialやsecretをGitへコミットしない

ONLYOFFICEは別domainを使用しません。ブラウザはJinと同じoriginの`/onlyoffice`へ接続し、DocumentServerの実portはloopbackだけで待ち受けます。

| 通信方向 | URL |
| --- | --- |
| Browser → DocumentServer | `https://jin.example.com/onlyoffice` |
| Laravel → DocumentServer | `http://127.0.0.1:8081` |
| DocumentServer → Laravel | `http://127.0.0.1:8090` |

### 初回セットアップ

installer用cloneを取得し、公開domainと配置先を指定して実行します。repositoryを省略した場合は`https://github.com/askdkc/jin`を使用します。

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

Let's Encryptの登録/期限通知先を指定する場合は`--email admin@example.com`を追加します。TLSをまだ発行できない閉域検証環境では`--no-ssl`を使用します。

database passwordやONLYOFFICE JWT secretは、既存`.env`の有効な値を優先し、未設定またはplaceholderの場合は安全なランダム値を生成して保持します。初回実行前に値を指定する場合は、コマンド引数ではなく環境変数を使用してください。

```bash
export DB_PASSWORD='replace-with-a-random-production-value'
export ONLYOFFICE_JWT_SECRET='replace-with-at-least-32-random-characters'
```

SMTPなど環境固有の設定は、初回完了後に`/var/www/jin/.env`へ設定してください。587番portのSTARTTLSは通常`MAIL_SCHEME=smtp`、465番portのimplicit TLSは`MAIL_SCHEME=smtps`です。providerの指定を優先します。

### 更新と再実行

以後は配置済みrepositoryの`setup.sh`を同じ引数で再実行します。scriptは`git pull --ff-only`を使用し、既存の`.env`、secret、database、private storageを維持したまま依存関係、build、migration、service設定を更新します。

```bash
cd /var/www/jin
./setup.sh \
  --platform ubuntu \
  --domain jin.example.com \
  --app-dir /var/www/jin \
  --onlyoffice-port 8081 \
  --app-internal-port 8090
```

### 公開後の確認

```bash
curl -fsS https://jin.example.com/up
curl -fsS https://jin.example.com/onlyoffice/healthcheck
sudo supervisorctl status
sudo systemctl --no-pager --full status nginx php8.5-fpm postgresql redis-server onlyoffice-documentserver
sudo ss -ltnp
```

80/443以外のJin/ONLYOFFICE関連listenerが`0.0.0.0`や`[::]`へ公開されていないことを確認してください。setupはDocumentServerを`127.0.0.1:8081`、Laravel callbackを`127.0.0.1:8090`へ限定します。

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
