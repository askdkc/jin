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

- PHP 8.4
- Composer 2
- Node.js 22.12以上とnpm
- PHPのSQLite拡張
- LibreOffice、ImageMagick、poppler-utils（Officeファイルのプレビュー用。後述）

MySQLやPostgreSQLを使う場合は、対応するPHP拡張と `.env` のDB設定も必要です。

## Officeファイルのプレビュー

Officeプレビューは、用途の異なる2つの経路に分けています。

1. **ファイル詳細の先頭ページ**: アップロード後のQueueでLibreOffice headlessから一時PDFを作り、popplerとImageMagickで非公開WebPへ変換します。画面を開くたびにOffice原本を変換しません。
2. **「プレビュー」ボタンの全文表示**: ONLYOFFICE Docs（DocumentServer）のWeb ViewerへOffice原本を渡します。ExcelもPDF化しないため、シート切り替え、セル表示、縦横スクロールを維持します。

PDFと画像はONLYOFFICEへ渡さず、既存viewerを使います。ONLYOFFICEが無効、未設定、または初期化に失敗した場合は、画面上のボタンから既存previewまたはダウンロードへフォールバックできます。

### 対応Office形式と閲覧制限

| 種別               | 拡張子                       | ONLYOFFICE `documentType` |
| ------------------ | ---------------------------- | ------------------------- |
| 表計算             | `xlsx`, `xls`, `xlsm`, `ods` | `cell`                    |
| 文書               | `docx`, `doc`, `odt`         | `word`                    |
| プレゼンテーション | `pptx`, `ppt`, `odp`         | `slide`                   |

viewerは完全な閲覧専用です。編集、コメント、レビュー、フォーム入力、チャット、保護設定変更、マクロ、プラグイン、共同編集、保存、更新callbackを有効にしません。`callbackUrl`も発行せず、JinのOffice原本は変更しません。ダウンロード、印刷、コピーだけを環境変数で個別に切り替えられます。

### WebP生成に必要なコマンド

`LIBREOFFICE_PATH`、`IMAGEMAGICK_PATH`、`POPPLER_PATH`には各コマンドのパスを設定します。PATHが通っていれば既定値の`soffice`、`convert`、`pdftoppm`のままで動作します。

#### macOS

```bash
brew install --cask libreoffice
brew install imagemagick poppler
```

```dotenv
LIBREOFFICE_PATH=/Applications/LibreOffice.app/Contents/MacOS/soffice
```

#### Ubuntu / Debian

```bash
sudo apt install libreoffice imagemagick poppler-utils fonts-noto-cjk
```

#### AlmaLinux / RHEL系

```bash
sudo dnf install libreoffice-headless libreoffice-writer libreoffice-calc libreoffice-impress ImageMagick poppler-utils google-noto-sans-cjk-ttc-fonts
```

#### Arch Linux

```bash
sudo pacman -S libreoffice-fresh imagemagick poppler noto-fonts-cjk
```

Linuxサーバーでは日本語フォント（各ディストリのNoto CJKパッケージ）を入れてください。フォントがないと先頭ページWebPの日本語が豆腐（□）になります。

### ONLYOFFICE環境・セットアップの選択

Dockerは必須ではありません。公式の対応範囲に入るOSでは、ONLYOFFICEのネイティブパッケージも有効な選択肢です。対象OS、NGINX、CPU、メモリ、swap、描画対象形式をstagingで受入れ確認してから本番へ進めてください。このREADMEは対象ホストでのliveなパッケージ導入検証を行ったことを示すものではありません。

| OS           | 推奨                                         | ネイティブ状態                                   | 注意点                                                                             |
| ------------ | -------------------------------------------- | ------------------------------------------------ | ---------------------------------------------------------------------------------- |
| macOS        | Docker Desktop（開発・受入れ）またはLinux VM | DocumentServerのネイティブサーバーパッケージなし | Desktop EditorsはDocumentServerではない。本番はLinux。                             |
| Ubuntu 24.04 | DEBまたはDocker                              | DEBは受入れ可能                                  | 公式DEB要件を満たすNGINXを用意し、stagingで描画を確認する。                        |
| Ubuntu 26.04 | Dockerを優先、DEBは評価用                    | DEBは評価経路                                    | ネイティブ要件に明示されていないため、依存関係・描画を受入れ確認する。             |
| AlmaLinux 9  | RPMまたはDocker                              | RHEL 9互換の派生経路                             | AlmaLinux自体は公式一覧に明記されない。stagingで検証する。                         |
| AlmaLinux 10 | Dockerまたは対応Linux VM                     | 一覧にあるネイティブ対応なし                     | RPMネイティブを前提にしない。                                                      |
| Arch Linux   | 公式SnapまたはDocker                         | 公式ArchネイティブDEB/RPMなし                    | AURの`onlyoffice-documentserver-bin`は第三者パッケージで、本番の推奨経路ではない。 |

ネイティブDEB/RPMは2-core 2 GHz以上、空きディスク40 GB以上、swap 4 GB以上を基本要件とし、DEBはホスト依存でRAM 2 GB以上、RPMの公式一覧はCentOS Stream 9 / RHEL 8 / 9です。いずれもNGINX 1.30以上が必要です。Docker公式ガイドはamd64 Linux、2-core 2 GHz以上、RAM 4 GB以上、swap 4 GB以上、空きディスク40 GB以上を記載しています。現在の`onlyoffice/documentserver:9.4.0.1`はamd64/arm64のmanifestがありますが、arm64は対象環境での受入れ確認を必須にします。

#### macOS（Docker Desktop）

macOSにはDocumentServerのネイティブサーバーパッケージがありません。Desktop Editorsを代用せず、ローカル開発・受入れは[Docker Desktopの公式macOS手順](https://docs.docker.com/desktop/setup/install/mac-install/)で行うか、要件を満たすLinux VMを使います。本番DocumentServerはLinuxへ配置します。

Docker DesktopのSettings > ResourcesでDocker VMに少なくとも2 CPU、RAM 4 GB、swap 4 GBを割り当てます。swapの既定値は1 GBなので変更し、ホスト側の空きディスクも40 GB以上にします。Apple Siliconではarm64コンテナのhealthcheck、対象Office形式、原本URLの往復を受入れ確認してください。

```bash
open -a Docker
docker info
docker version
docker run --rm hello-world
```

Docker Desktopでは`host.docker.internal`を使い、Linux Docker VMのbridge IPへPHPをbindしません。ホストLaravelを使う場合も、Docker Desktopから到達できることを確認し、必要ならローカルのfirewall/EDRで接続元を制限します。これは開発用であり、本番でPHPを`0.0.0.0`へ公開する案内ではありません。

Docker Desktopの商用利用や有償サブスクリプションの条件は、組織規模と用途によって異なるため、[Dockerの料金・サブスクリプション情報](https://www.docker.com/pricing/)を導入前に確認してください。これは法的助言ではありません。

#### Ubuntu 24.04 / 26.04（DEBまたはDocker）

Ubuntu 24.04はDEB経路を受入れ可能とします。Ubuntu 26.04のDEBは公式のネイティブ要件で明示認証されていないため、Dockerを優先し、DEBを使う場合はstageで依存関係と描画を評価します。DEBを選ぶ場合は、先に公式NGINXパッケージでNGINX 1.30以上を用意し、`nginx -v`で確認してください。ディストリビューション標準版が古い場合は、[NGINX公式パッケージ手順](https://nginx.org/en/linux_packages.html)に従います。

```bash
export ONLYOFFICE_JWT_SECRET="$(openssl rand -hex 32)"

printf '%s\n' \
  'onlyoffice-documentserver onlyoffice/ds-port select 8081' \
  'onlyoffice-documentserver onlyoffice/jwt-enabled boolean true' \
  "onlyoffice-documentserver onlyoffice/jwt-secret password ${ONLYOFFICE_JWT_SECRET}" |
  sudo debconf-set-selections

sudo apt update
sudo apt install -y ca-certificates curl gnupg ttf-mscorefonts-installer
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.onlyoffice.com/GPG-KEY-ONLYOFFICE |
  gpg --dearmor |
  sudo tee /usr/share/keyrings/onlyoffice.gpg >/dev/null
sudo chmod a+r /usr/share/keyrings/onlyoffice.gpg
echo 'deb [signed-by=/usr/share/keyrings/onlyoffice.gpg] https://download.onlyoffice.com/repo/debian squeeze main' |
  sudo tee /etc/apt/sources.list.d/onlyoffice.list >/dev/null
sudo apt update
sudo apt install -y onlyoffice-documentserver
nginx -v
```

`ds-port=8081`、JWT有効化、生成した64-hex secretはDocumentServerのインストール前にdebconfへ渡します。ネイティブのbackend `8081`はfirewallでprivateに保ち、公開時はTLS対応reverse proxyの専用originだけを公開します。公式ガイドにあるpublicな`firewalld` port openをそのまま適用しないでください。

Dockerを選ぶ場合は、Ubuntu 24.04（Noble）と26.04（Resolute）を対象に記載する[Docker Engine公式Ubuntu手順](https://docs.docker.com/engine/install/ubuntu/)に従います。既存のDocker/Podman、apt source、パッケージを確認してから進めるclean-host向けの例であり、競合するrepoを盲目的に削除しません。

```bash
sudo apt update
sudo apt install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

UBUNTU_CODENAME="$(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")"
sudo tee /etc/apt/sources.list.d/docker.sources >/dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: ${UBUNTU_CODENAME}
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo docker run --rm hello-world
```

#### AlmaLinux 9 / 10（RPMまたはDocker）

AlmaLinux 9はRHEL 9互換のRPM派生経路としてstageで検証できますが、公式一覧のCentOS Stream 9 / RHEL 8 / 9にAlmaLinuxの名前はありません。AlmaLinux 10はネイティブRPMを選ばず、Dockerまたは対応Linux VMを使います。

RPMを選ぶ場合は、[ONLYOFFICE公式RPM依存関係・導入ガイド](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-centos.aspx)に従って現行NGINX、EPEL/CRBを用意してください。NGINXのrepoや`nginx.conf`をここで推測して再掲せず、公式依存関係ガイドの内容を適用したうえで`nginx -v`が1.30以上であることを確認します。

```bash
sudo dnf install -y epel-release cabextract xorg-x11-font-utils policycoreutils-python-utils
# AlmaLinux 9のCRB有効化と現行NGINX repo/configは公式依存関係ガイドに従う。
sudo dnf install -y https://download.onlyoffice.com/repo/centos/main/noarch/onlyoffice-repo.noarch.rpm
sudo rpm -i https://sourceforge.net/projects/mscorefonts2/files/rpms/msttcore-fonts-installer-2.6-1.noarch.rpm
sudo dnf install -y onlyoffice-documentserver
sudo semanage port -a -t http_port_t -p tcp 8081 2>/dev/null || \
  sudo semanage port -m -t http_port_t -p tcp 8081
sudo setsebool -P httpd_can_network_connect on
sudo documentserver-configure.sh --dsport 8081 --jwtenabled true
nginx -v
```

`documentserver-configure.sh`の実行時もbackend portは8081にし、外部公開せず、firewallとreverse proxyでprivate backendとして扱います。SELinuxは無効化せず、8081をNGINXがlistenできる`http_port_t`へ登録し、NGINXのupstream接続を許可します。公式ガイドにあるpublic `80/tcp`のfirewalld例は適用しません。RPMのARM64は[ONLYOFFICE公式ARM64手順](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-centos-arm64.aspx)を別途確認し、対象環境で受入れ検証します。

Dockerを選ぶ場合は、AlmaLinuxの公式サポートを意味しないRHEL/CentOS互換経路です。既存repoやPodmanとの競合を確認し、削除や置換を自動化しないclean-host向けの例にします。初回のDockerパッケージ導入では`-y`を付けず、表示されたDocker GPG keyのfingerprintを[Docker公式CentOS手順](https://docs.docker.com/engine/install/centos/)の現在のfingerprintと照合してから承認してください。

```bash
sudo dnf install -y curl
sudo curl -fsSL https://download.docker.com/linux/centos/docker-ce.repo \
  -o /etc/yum.repos.d/docker-ce.repo
sudo dnf install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo docker run --rm hello-world
```

#### Arch Linux（公式SnapまたはDocker）

Arch LinuxにはONLYOFFICEの公式Arch-native DEB/RPMがありません。公式SnapはDockerを使わない経路です。AURの`onlyoffice-documentserver-bin`は第三者パッケージなので、本番の推奨経路にしません。snapdの導入は公式Snap手順と[ArchWiki](https://wiki.archlinux.org/title/Snap)を確認してください。

```bash
export ONLYOFFICE_JWT_SECRET="$(openssl rand -hex 32)"
sudo snap install onlyoffice-ds
sudo snap set onlyoffice-ds \
  onlyoffice.ds-port=8081 \
  onlyoffice.loopback=true \
  onlyoffice.jwt-enabled=true \
  onlyoffice.jwt-secret="$ONLYOFFICE_JWT_SECRET"
sudo snap restart onlyoffice-ds
sudo snap services onlyoffice-ds
sudo snap get onlyoffice-ds
```

Snapのloopback設定は`true`のままにし、backend `8081`をfirewallでprivateに保ちます。Dockerを選ぶ場合は、Archを先に全体更新してからDockerを導入し、[ArchWikiのDocker手順](https://wiki.archlinux.org/title/Docker)に従います。

```bash
sudo pacman -Syu --needed docker docker-buildx docker-compose
sudo systemctl enable --now docker.service
sudo docker run --rm hello-world
```

#### Docker共通の事前確認と起動

Docker公式ガイドのDocumentServer要件は、amd64 Linux、2 GHz以上のdual-core CPU、RAM 4 GB以上、空きディスク40 GB以上、swap 4 GB以上です。Docker Desktopでは前記のResources設定を使います。

次の事前確認blockは**Linux Docker Engine専用**です。macOSでは`nproc`、`free`、`swapon`やホスト上の`/var/lib/docker`を使わず、Docker DesktopのSettings > Resourcesと`docker info`で確認します。LinuxではDocker導入後に次を確認します。swapの作成方法はOSとファイルシステムに依存するため、Btrfsでは各OSの公式手順に従ってください。

```bash
uname -m
nproc
free -h
swapon --show
df -h /var/lib/docker
sudo docker version
```

host-devでは通常のbridgeである`jin-internal`を使えますが、これはegress bridgeであり、隔離を意味しません。Linux rootfulでは`sudo docker`を使い、Docker Desktopでは`sudo`を付けません。productionでコンテナ化したLaravelとDocumentServerを隔離する場合は、専用の内部networkを作ります。

```bash
# host-dev（通常のegress bridge）
sudo docker network inspect jin-internal >/dev/null 2>&1 || sudo docker network create jin-internal

# production container network（Linux rootfulの例）
sudo docker network create --internal jin-production-internal
```

production例では、後述の`docker run`の`--network jin-internal`を`--network jin-production-internal`へ読み替え、Laravelも同じnetworkへ接続します。外部依存が必要なproduction構成で`--internal`を使わない場合は、DocumentServerとLaravelのegressを必要な宛先へallowlistしてください。既存の`jin-production-internal`を削除・再作成するコマンドは載せていません。環境に既存networkがある場合は、接続先とegress policyを先に確認します。

次の例は、Docker Engineでは`sudo`を付け、Docker Desktopでは外して実行します。`ONLYOFFICE_JWT_SECRET`はJinの`.env`にも同じ値を設定し、ログやREADMEへ書きません。DockerのJWTは`local.json`を編集せず、必ず環境変数で指定します。

```bash
export ONLYOFFICE_JWT_SECRET="$(openssl rand -hex 32)"

docker run -d \
  --name onlyoffice-documentserver \
  --restart unless-stopped \
  --network jin-internal \
  -p 127.0.0.1:8081:80 \
  -e JWT_ENABLED=true \
  -e JWT_SECRET="$ONLYOFFICE_JWT_SECRET" \
  -e PLUGINS_ENABLED=false \
  -e ALLOW_PRIVATE_IP_ADDRESS=true \
  -v onlyoffice-data:/var/www/onlyoffice/Data \
  -v onlyoffice-logs:/var/log/onlyoffice \
  -v onlyoffice-cache:/var/lib/onlyoffice \
  -v onlyoffice-db:/var/lib/postgresql \
  onlyoffice/documentserver:9.4.0.1
```

`-p 127.0.0.1:8081:80`はhost loopbackだけへの公開です。LinuxでホストLaravelへ到達させる場合だけ、起動引数に`--add-host host.docker.internal:host-gateway`を追加します。Docker Desktopでは`host.docker.internal`が組み込みで解決されるため、この引数とLinux bridge IPの手動設定は不要です。

Dockerのpublished portは環境によってufw/firewalldの意図したフィルタを迂回することがありますが、上記はpublic interfaceへ開きません。本番では`127.0.0.1:8081`をTLS対応reverse proxyの`https://office.example.com`へ接続します。DocumentServerを直接インターネット公開せず、WebSocketの`Upgrade`/`Connection`ヘッダー、十分なproxy timeout、大容量responseを扱える設定をONLYOFFICE公式例に合わせます。パスprefixではなく専用originを推奨します。

`ALLOW_PRIVATE_IP_ADDRESS=true`は、DocumentServerがDockerサービス名やRFC1918アドレスのLaravelへ原本を取りに行く構成でだけ必要です。メタデータIPを許可する`ALLOW_META_IP_ADDRESS`や、自己署名証明書を無条件に許可する`USE_UNAUTHORIZED_STORAGE=true`は使わず、信頼できるCAを設定します。

#### ネイティブパッケージのJWT

DEBのdebconfまたはSnapの設定でJWTを有効にした後、Linuxネイティブパッケージの設定を変更する場合は、既存の`/etc/onlyoffice/documentserver/local.json`へ値をmergeします。ファイル全体を置換せず、次の公式キーを設定してください。

```bash
export ONLYOFFICE_JWT_SECRET="$(openssl rand -hex 32)"
sudoedit /etc/onlyoffice/documentserver/local.json
sudo systemctl restart ds-converter ds-docservice ds-metrics
```

```json
{
    "services": {
        "CoAuthoring": {
            "token": {
                "enable": {
                    "request": { "inbox": true, "outbox": true },
                    "browser": true
                }
            },
            "secret": {
                "inbox": { "string": "<same-64-hex-secret>" },
                "outbox": { "string": "<same-64-hex-secret>" },
                "session": { "string": "<same-64-hex-secret>" }
            }
        }
    }
}
```

RPMでは上の`ONLYOFFICE_JWT_SECRET`の値を3か所の`<same-64-hex-secret>`へ安全に設定します。ネイティブのsecretはJinの`ONLYOFFICE_JWT_SECRET`と同じ値にし、秘密鍵をコミット・ログ・ブラウザへ出しません。Dockerではこの`local.json`編集を行わず、コンテナ再作成時の`JWT_ENABLED`/`JWT_SECRET`環境変数を更新します。

#### 全方式共通の起動確認

DocumentServerの初回初期化には時間がかかるため、DEB/RPM/Snap/Dockerのいずれでもone-shotの確認にせず、接続2秒・応答3秒のcurl timeoutを設定して最大120秒待ちます。deadlineまでreadyにならない場合は非0で終了し、各方式のservice/container logを確認してください。

```bash
ready=false
deadline=$((SECONDS + 120))

while ((SECONDS < deadline)); do
  if curl -fsS --connect-timeout 2 --max-time 3 \
    http://127.0.0.1:8081/healthcheck >/dev/null; then
    ready=true
    break
  fi
  sleep 2
done

if [ "$ready" != true ]; then
  echo 'DocumentServer healthcheck did not become ready within 120 seconds.' >&2
  exit 1
fi
```

### Laravelの環境変数

JinとDocumentServerには**同じJWT秘密鍵**を設定します。秘密鍵は`.env`へ置き、コミット、ログ出力、ブラウザ返却をしません。

```dotenv
ONLYOFFICE_ENABLED=true
ONLYOFFICE_DOCUMENT_SERVER_URL=http://127.0.0.1:8081
ONLYOFFICE_PUBLIC_URL=https://office.example.com
APP_ONLYOFFICE_INTERNAL_URL=http://host.docker.internal:8000
ONLYOFFICE_JWT_SECRET=replace-with-the-same-64-hex-secret
ONLYOFFICE_INTERNAL_DOWNLOAD_TTL=300
ONLYOFFICE_ALLOW_DOWNLOAD=false
ONLYOFFICE_ALLOW_PRINT=false
ONLYOFFICE_ALLOW_COPY=true
```

設定後は`php artisan optimize:clear`を実行してください。Laravelは`firebase/php-jwt`で設定JSON全体をHS256署名します。同じOffice原本には同じ`document.key`を使い、UIの置換処理で保存pathまたはsizeが変わるとSHA-256 keyも変わります。名前変更だけではkeyを変えません。

preview列導入前から存在するOffice/PDFファイルは、デプロイ後に次の冪等コマンドで生成対象へ登録します。`pending`/`ready`の行は重複queueせず、変換失敗またはqueue enqueue失敗で`failed`になった行は再実行時に再queueします。enqueue失敗時も原本とDB行は保持され、失敗内容はapplication logへ記録されます。

```bash
php artisan stored-files:backfill-previews
```

3方向のURLは混同しないでください。

| 通信                                      | 設定                             | 例                                                                                          |
| ----------------------------------------- | -------------------------------- | ------------------------------------------------------------------------------------------- |
| ブラウザ → DocumentServer API/iframe      | `ONLYOFFICE_PUBLIC_URL`          | `https://office.example.com`                                                                |
| Laravel → DocumentServer内部経路          | `ONLYOFFICE_DOCUMENT_SERVER_URL` | ホストLaravel: `http://127.0.0.1:8081` / Docker Laravel: `http://onlyoffice-documentserver` |
| DocumentServer → Laravelの期限付き原本URL | `APP_ONLYOFFICE_INTERNAL_URL`    | ホストLaravel: `http://host.docker.internal:8000` / Docker Laravel: `http://app`            |

#### ホストLaravelとのネットワーク

ネイティブDocumentServerとLaravelが同じホストにある場合、Laravelからはloopback公開portの`ONLYOFFICE_DOCUMENT_SERVER_URL=http://127.0.0.1:8081`を使います。Docker DocumentServerからホストLaravelへ原本URLを返す場合は、Dockerから到達できるURLが別途必要です。Laravelをコンテナ化できる場合は、LaravelとDocumentServerを同じDocker networkへ接続し、サービス名を使う構成を優先します。

Linux rootfulでホストLaravelを使う一時検証では、bridge gatewayを`sudo docker network inspect`で確認し、そのIPにだけLaravel開発serverをbindします。`composer dev`の`localhost` bindはDocumentServerコンテナから到達できません。外部interfaceへ無制限にbindしないでください。

```bash
DOCKER_HOST_GATEWAY="$(sudo docker network inspect bridge --format '{{(index .IPAM.Config 0).Gateway}}')"
php artisan serve --host="$DOCKER_HOST_GATEWAY" --port=8000
```

この場合は`APP_ONLYOFFICE_INTERNAL_URL=http://host.docker.internal:8000`とし、DocumentServer起動時にLinuxだけ`--add-host host.docker.internal:host-gateway`を指定します。macOSではDocker VMのbridge IPへPHPをbindせず、`host.docker.internal`の到達性を確認してください。ホストlistenerを使うのはDocker Desktopから到達でき、かつfirewall/EDRで開発元を制限できる場合だけです。本番でPHPを`0.0.0.0`へ公開する構成にはしません。

常設環境ではLaravelもコンテナ化して両者を同じnetworkへ接続し、`ONLYOFFICE_DOCUMENT_SERVER_URL=http://onlyoffice-documentserver`、`APP_ONLYOFFICE_INTERNAL_URL=http://app`のようなサービス名を使うか、Docker networkからのみ到達できる既存reverse proxyを使用します。productionのnetworkは`docker network create --internal`で作った専用network、または明示的なegress allowlistを使います。DocumentServer側で`localhost`を指定するとDocumentServer自身を指すため、Laravelへ到達しません。

DocumentServer向け原本URLは、ログインsessionに依存しない相対署名URLです。有効期限、StoredFile ID、原本versionを署名対象にし、private storageからストリームします。URLや保存pathを利用者入力から受け取りません。設定APIは通常のプロジェクトPolicyを通り、レスポンスは`private, no-store`です。

既存CSPを有効にする場合は、`ONLYOFFICE_PUBLIC_URL`のHTTPS originを少なくとも`script-src`と`frame-src`へ、同じoriginのHTTPS/WSSを`connect-src`へ追加してください。ワイルドカードで全外部originを許可しないでください。

### ONLYOFFICEトラブルシューティング

- **Download failed**: DocumentServerのhostまたはコンテナ内から`APP_ONLYOFFICE_INTERNAL_URL`へ到達できるか確認します。署名URLは300秒で失効し、ファイル置換後の古いversionは`410 Gone`です。調査ログへ署名URL全文を残さないでください。Dockerで内部IPを使う場合はnetwork policyと`ALLOW_PRIVATE_IP_ADDRESS=true`を確認します。
- **document key error / 古い文書内容が表示される**: Jinの「新しいバージョンで置き換える」を使うと保存pathとkeyが変わります。storage上の同一pathをDB外から直接上書きしないでください。DocumentServerとLaravel双方の再起動前に、設定cacheを`php artisan optimize:clear`で消します。
- **JWT validation failed**: Jinの`ONLYOFFICE_JWT_SECRET`と、Dockerの`JWT_SECRET`またはネイティブ`local.json`の3つのsecretが完全一致し、JWT validationが有効であることを確認して両方を再起動します。JWT全文や秘密鍵をログへ出さないでください。
- **Mixed Content**: JinがHTTPSなら`ONLYOFFICE_PUBLIC_URL`もHTTPSにします。内部URLがHTTPでもブラウザへ直接ロードされないため、公開URLと混同しません。
- **CORS**: 設定APIはJinのsame-originです。DocumentServer側のCORSを`*`で開放せず、公開origin、リバースプロキシ、WebSocketヘッダー、CSPを確認します。
- **DocumentServerからLaravelへ到達できない**: コンテナ内の`localhost`は使わず、Dockerサービス名または`host.docker.internal`を使います。Laravel側のIP制限、DNS、内部TLSのCA、firewallも確認します。
- **ブラウザからDocumentServerへ到達できない**: `${ONLYOFFICE_PUBLIC_URL}/web-apps/apps/api/documents/api.js`へブラウザから到達できるか、DNS/TLS/CSPとリバースプロキシを確認します。Jinのファイル一覧だけではこのAPIへ接続しません。
- **プレビュー領域の高さが0**: 標準componentは`min-height: 70dvh`を確保します。独自レイアウトで親要素へ`height: 0`や`display: none`を指定していないか確認します。
- **Viewer初期化失敗 / DocumentServer停止**: dialogの「既存プレビューを開く」またはダウンロードを使います。DocumentServerの`/healthcheck`、container log、ブラウザconsoleを確認します。ユーザー画面には内部URL、JWT、秘密鍵を表示しません。

### 公式リファレンス

- [ONLYOFFICE公式DEBインストールガイド](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-ubuntu.aspx)
- [ONLYOFFICE公式RPMインストールガイド](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-centos.aspx)
- [ONLYOFFICE公式RPM ARM64インストールガイド](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-centos-arm64.aspx)
- [ONLYOFFICE公式Snapインストールガイド](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-from-snap.aspx)
- [ONLYOFFICE公式JWT設定ガイド](https://helpcenter.onlyoffice.com/docs/installation/docs-configure-jwt.aspx)
- [ONLYOFFICE公式Dockerインストールガイド](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-docker.aspx)
- [Docker Desktop for Macの公式インストール手順](https://docs.docker.com/desktop/setup/install/mac-install/)
- [Docker Engine公式インストール一覧](https://docs.docker.com/engine/install/)
- [Docker Engine公式Ubuntu手順](https://docs.docker.com/engine/install/ubuntu/)
- [Docker Engine公式CentOSリポジトリ手順](https://docs.docker.com/engine/install/centos/)
- [NGINX公式パッケージ手順](https://nginx.org/en/linux_packages.html)
- [ArchWiki Snap](https://wiki.archlinux.org/title/Snap)
- [ArchWiki Docker](https://wiki.archlinux.org/title/Docker)
- [Dockerの料金・サブスクリプション情報](https://www.docker.com/pricing/)

## ローカルセットアップ

### 1. 依存関係と環境ファイルを準備する

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
npm install
```

日本語UIを使うため、`.env` の次の値を変更してください。

```dotenv
APP_NAME=Jin
APP_LOCALE=ja
APP_FALLBACK_LOCALE=ja
APP_FAKER_LOCALE=ja_JP
```

### 2. データベースを作成する

```bash
php artisan migrate --seed
```

Seederは同じメールアドレスのユーザーを再作成しないため、次のコマンドは繰り返し実行できます。

```bash
php artisan db:seed
```

### 3. 開発サーバーを起動する

通常のWebサーバー、Vite、Queue、ログ表示をまとめて起動します。

```bash
composer run dev
```

リアルタイムチャット用のReverbは別ターミナルで起動します。

```bash
php artisan reverb:start
```

期限通知と定期チェックインを動かす場合は、さらに別ターミナルでスケジューラを起動します。

```bash
php artisan schedule:work
```

ブラウザで <http://localhost:8000> を開いてください。

### サンプルユーザー

`php artisan migrate --seed` または `php artisan db:seed` の実行後、次のアカウントでログインできます。

```text
メールアドレス: test@example.com
パスワード: password
```

この認証情報はローカル開発専用です。本番環境ではSeederを実行しないか、直ちにユーザーとパスワードを変更してください。

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

## ReverbとQueue

開発環境の初期値は `.env.example` にあります。`REVERB_HOST` と `REVERB_ALLOWED_ORIGINS` はブラウザから利用するホスト名に合わせてください。

```dotenv
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_ALLOWED_ORIGINS=localhost
```

チャットの即時配信に必要なプロセスは次の2つです。

```bash
php artisan queue:work
php artisan reverb:start
```

`composer run dev` を使っている場合、Queueはすでに起動しているため、追加で必要なのは `php artisan reverb:start` だけです。

本番環境では、プレビュー生成の最大実行時間（240秒）より長くなるよう、database / Redis / Beanstalkd の `retry_after` を少なくとも300秒に設定してください。

```dotenv
DB_QUEUE_RETRY_AFTER=300
REDIS_QUEUE_RETRY_AFTER=300
BEANSTALKD_QUEUE_RETRY_AFTER=300
```

Worker側にも同じ前提で `php artisan queue:work --timeout=240` を設定し、LibreOffice、Poppler、ImageMagickの実行時間が重なってもジョブが再取得されないようにします。

## テストと品質確認

PHP側のフォーマット、静的解析、Pestをまとめて実行します。

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

## 本番運用

本番では少なくともWebサーバーに加え、Queue、Reverb、スケジューラを常駐または定期実行してください。

```bash
php artisan queue:work --tries=3
php artisan reverb:start
php artisan schedule:work
```

デプロイ前に本番用の `.env` を設定し、安全性チェックを実行します。

```bash
php artisan app:production-check
```

このコマンドは、HTTPS、デバッグ無効化、Queue、Reverb、IP制限、Cookie、非公開ストレージなどの必須設定に問題がある場合に失敗します。Reverbの `local-*` 認証情報は本番で使用しないでください。

代表的なデプロイ手順:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
php artisan app:production-check
```

プロセスの再起動方法、TLS終端、バックアップ、メール配信、監視はデプロイ先に合わせて設定してください。

## トラブルシューティング

### すべての画面が404になる

`IP_RESTRICTION_ENABLED` と `ALLOWED_IPS` を確認してください。プロキシ配下では `TRUSTED_PROXIES` が正しくないと、Laravelが利用者のIPを解決できません。

### チャットが別ブラウザへ即時反映されない

次を確認してください。

1. `php artisan reverb:start` が起動している
2. `composer run dev` または `php artisan queue:work` が起動している
3. `REVERB_ALLOWED_ORIGINS` にブラウザのホスト名が含まれている
4. ViteのコンソールとLaravelログに接続エラーがない

### 招待メールや通知が届かない

初期設定の `MAIL_MAILER=log` はメールを送信しません。ローカルでは `storage/logs/laravel.log` を確認し、本番ではSMTPなどのメールドライバーを設定してください。

### 画面変更が反映されない

開発中は `composer run dev` を起動してください。本番相当の確認では次を実行します。

```bash
npm run build
php artisan optimize:clear
```
