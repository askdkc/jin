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

MySQLやPostgreSQLを使う場合は、対応するPHP拡張と `.env` のDB設定も必要です。

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
