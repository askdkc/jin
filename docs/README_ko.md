# Jin

## 언어

[English](README_en.md) | [日本語](../README.md) | [Français](README_fr.md) | [Deutsch](README_de.md) | [简体中文](README_zh_CN.md) | [繁體中文](README_zh_TW.md) | [Español](README_es.md) | [Português (Brasil)](README_pt_BR.md) | [Português (Portugal)](README_pt_PT.md) | [한국어](README_ko.md)

Jin(陣)은 여러 팀이 프로젝트 단위로 협업할 수 있는 셀프 호스팅 그룹웨어입니다. 메시지, 할 일, 일정, 파일, 실시간 채팅, 카드 테이블, 정기 체크인을 하나의 프로젝트 작업 공간에 모읍니다.

정보 구조는 Basecamp를 참고했지만 이름, 화면, 색상, 아이콘은 독자적으로 설계했습니다. [일본어 README](../README.md)가 완전한 공식 운영 문서입니다. 이 번역본은 제품 기능, 요구 사항, 설치 경로, 보안 경계, 검증 명령을 설명합니다.

## 주요 기능

- 팀(작업 공간) 생성 및 전환, 멤버 초대, 권한 관리
- 프로젝트 생성, 편집, 기간 및 공개 범위 설정, 보관
- 댓글, 구독, 고정, 첨부 파일을 지원하는 메시지 보드
- Trix 서식 입력과 서버 측 HTML 정화
- 할 일 목록, 담당자, 마감일, 하위 작업, 댓글, 첨부, 드래그 앤 드롭 정렬
- 프로젝트 일정과 프로젝트 전체 일간·주간·월간·간트 보기
- 드래그 앤 드롭 파일 업로드와 권한이 적용된 다운로드
- Laravel Reverb 기반 실시간 프로젝트 채팅
- 드래그 앤 드롭 카드 테이블(Kanban)
- 정기 체크인과 답변 기록
- 활동 내역, 보고서, 라인업, 지원 파일 본문을 포함한 전체 검색
- 서명된 읽기 전용 iCalendar 구독
- 이메일 인증, 2단계 인증, Passkey
- 사이트 전체 IPv4, IPv6, CIDR 제한
- 데스크톱과 모바일에 대응하는 반응형 UI

## 기술 구성

| 영역           | 기술                                            |
| -------------- | ----------------------------------------------- |
| Backend        | PHP 8.5 / Laravel 13                            |
| Frontend       | Inertia.js 3 / Svelte 5 / TypeScript            |
| UI             | Tailwind CSS 4                                  |
| Rich text      | Trix 2 / Symfony HTML Sanitizer 8               |
| Realtime       | Laravel Reverb / Laravel Echo                   |
| Authentication | Laravel Fortify / Passkeys / 2FA                |
| Typed routes   | Laravel Wayfinder                               |
| Queue          | Laravel database queue(개발) / Redis(운영)      |
| Database       | SQLite FTS5(개발) / PostgreSQL + PGroonga(운영) |
| Test           | Pest 4 / PHPStan / Svelte Check / ESLint        |

## 요구 사항

- PHP 8.5 이상과 Composer 2
- Node.js 22.12 이상과 npm
- 로컬 개발 및 일반 테스트용 PHP SQLite 확장
- 파일 본문 검색을 사용할 때 Python 3.10 이상과 MarkItDown
- PDF 첫 페이지 썸네일용 ImageMagick과 Poppler
- 운영 환경 파일 본문 검색용 PostgreSQL과 PGroonga
- Office 읽기 전용 미리보기용 ONLYOFFICE Docs

로컬 개발은 SQLite FTS5를 사용합니다. 운영 환경은 PostgreSQL과 PGroonga를 사용하며, MySQL은 운영 환경 파일 본문 검색 구성으로 지원하지 않습니다.

## 빠른 로컬 설치

새로 복제한 저장소에서 실행합니다.

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

각 프로세스를 별도 터미널에서 시작합니다.

```bash
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
php artisan queue:work
php artisan reverb:start
```

마감 알림과 정기 체크인이 필요하면 `php artisan schedule:work`도 실행하고 <http://localhost:8000>을 엽니다. 시드 실행 후 개발 전용 예제 계정은 `test@example.com`, 비밀번호는 `password`입니다. 운영 환경에서는 사용하지 마십시오.

## macOS와 ONLYOFFICE

지원하는 macOS 구성은 Homebrew와 Apple [`container`](https://github.com/apple/container)를 사용합니다. Apple Container는 Apple silicon과 macOS 26 이상이 필요합니다.

```bash
brew install php composer node@22 python imagemagick poppler
container system start
./setup.sh --platform macos --app-dir "$PWD" --no-nginx
```

ONLYOFFICE를 활성화할 때는 32자 이상의 무작위 비밀키를 설정하고 절대 Git에 커밋하지 마십시오.

```dotenv
ONLYOFFICE_ENABLED=true
ONLYOFFICE_JWT_SECRET=replace-with-at-least-32-random-characters
```

localhost DNS 매핑, 컨테이너 수명 주기, 복구 방법은 [공식 macOS 설명](../README.md#macos開発環境)을 참고하십시오.

## MCP 연동

로컬 Jin MCP 서버는 지정한 Jin 사용자 권한으로 전체 프로세스를 실행합니다.

```bash
php artisan jin:mcp user@example.com
```

신뢰할 수 있는 로컬 환경에서 최소 권한의 전용 계정으로만 실행하십시오. 해당 사용자의 팀 및 프로젝트 권한이 모든 MCP 도구에 적용됩니다.

## Ubuntu 24.04 / 26.04 운영 환경

`setup.sh`는 Jin, PHP, PostgreSQL/PGroonga, Redis, Node.js, MarkItDown, Nginx, Supervisor, TLS, ONLYOFFICE Docs를 구성합니다. 지원 대상은 amd64 Ubuntu Server이며 최소 2 CPU, RAM 4 GB, swap 4 GB와 충분한 디스크 공간이 필요합니다. 외부에는 80/443과 선택한 SSH 포트만 공개하십시오.

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

Let's Encrypt 등록과 만료 알림에는 `--email admin@example.com`을 추가합니다. `--no-ssl`은 공개 인증서를 발급할 수 없는 격리 환경에서만 사용하십시오. 의도적으로 비밀번호 없는 sudo를 구성했다면 설치 프로그램의 안내에 따라 `--sudo-nopasswd`를 사용합니다. 생성된 데이터베이스 비밀번호는 `.env`와 `/etc/jin/database-password`(`root:root`, 권한 `0600`)에 저장됩니다. 배포 OS 사용자와 같은 이름의 PostgreSQL 관리자 역할은 애플리케이션 `DB_USER`와 분리하십시오.

배포 후 확인:

```bash
curl -fsS https://jin.example.com/up
curl -fsS https://jin.example.com/onlyoffice/healthcheck
sudo supervisorctl status
sudo systemctl --no-pager --full status nginx php8.5-fpm postgresql redis-server onlyoffice-documentserver
sudo ss -ltnp
```

80/443 이외의 Jin 또는 ONLYOFFICE 서비스가 `0.0.0.0`이나 `[::]`에서 수신하면 안 됩니다.

## 파일 본문 검색

MarkItDown 비동기 추출 후 Everything은 `docx`, `xlsx`, `pptx`, `pdf`의 본문을 검색합니다. 이전 Office 및 OpenDocument 형식, 암호화되거나 비밀번호로 보호된 파일, 추출 실패, 크기 제한 초과 파일은 파일명만 검색합니다. Jin은 문서 비밀번호를 받거나 저장하거나 추출기에 전달하지 않습니다.

```bash
php artisan stored-files:backfill-content
php artisan stored-files:backfill-content --retry-failed
php artisan stored-files:backfill-content --reindex
```

## 보안 경계

- 파일은 `storage/app/private`에 보관되며 권한이 적용된 다운로드 경로로만 제공됩니다.
- Client 공개 범위는 프로젝트 단위로 정합니다.
- 서명된 캘린더 구독 URL은 비밀번호처럼 취급해야 합니다.
- ONLYOFFICE JWT 비밀키와 전체 서명 URL은 절대 로그나 Git에 남기지 마십시오.
- IP 제한은 로그인 화면과 `/up`에도 적용됩니다.

```dotenv
IP_RESTRICTION_ENABLED=true
ALLOWED_IPS=203.0.113.10,198.51.100.0/24,2001:db8::/32
TRUSTED_PROXIES=10.0.0.0/8
```

신뢰할 프록시에 `*`를 사용하지 마십시오. 값을 바꾼 뒤 `php artisan optimize:clear`를 실행합니다.

## 테스트와 품질 확인

```bash
composer ci:check
composer test
npm run lint:check
npm run format:check
npm run types:check
npm run build
```

## 문제 해결

- **모든 페이지가 404를 반환함:** `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS`, `TRUSTED_PROXIES`를 확인합니다.
- **채팅이 즉시 동기화되지 않음:** Reverb, Queue Worker, Vite, `REVERB_ALLOWED_ORIGINS`, 브라우저 콘솔, Laravel 로그를 확인합니다.
- **이메일이 발송되지 않음:** `MAIL_MAILER=log`는 `storage/logs/laravel.log`에만 기록합니다. 운영 환경에서는 실제 메일 드라이버를 설정하십시오.
- **화면 변경이 보이지 않음:** 개발 중에는 `npm run dev`를 사용하거나 `npm run build` 후 `php artisan optimize:clear`를 실행합니다.
- **ONLYOFFICE 오류:** 세 가지 통신 URL, 공유 JWT 비밀키, healthcheck, 콜백 접근성을 확인하되 전체 JWT를 로그에 남기지 마십시오.

모든 상세 운영 절차는 [일본어 README](../README.md)를 참고하십시오.
