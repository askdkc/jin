# Jin

[English](README_en.md) | [日本語](../README.md) | [Français](README_fr.md) | [Deutsch](README_de.md) | [简体中文](README_zh_CN.md) | [繁體中文](README_zh_TW.md) | [Español](README_es.md) | [Português (Brasil)](README_pt_BR.md) | [Português (Portugal)](README_pt_PT.md) | [한국어](README_ko.md)

Jin(陣)은 여러 팀이 프로젝트 단위로 협업할 수 있는 셀프 호스팅 그룹웨어입니다. 메시지, 할 일, 일정, 파일, 실시간 채팅, 카드 테이블, 정기 체크인을 하나의 프로젝트 작업 공간에 모읍니다.

## Ubuntu 24.04 / 26.04 운영 환경

amd64 Ubuntu Server 24.04 / 26.04에서 `setup.sh`를 실행하면 완전한 Jin 운영 환경이 구성됩니다. Laravel과 데이터베이스를 수동으로 설치하거나 설정할 필요가 없습니다.

이 스크립트는 필수 APT 패키지, PHP 8.5, Composer, Node.js 24를 설치하고 Jin을 clone 또는 update하며, Laravel 의존성 설치, `.env`와 애플리케이션 키 생성, migration, frontend build를 수행합니다. 또한 PostgreSQL/PGroonga, 데이터베이스, 역할, peer 인증을 구성하고 Redis, RabbitMQ, Queue Worker, Reverb, Scheduler, Supervisor, Nginx, PHP-FPM, ONLYOFFICE Docs 및 파일 처리 도구를 설치합니다. `--no-ssl`을 지정하지 않으면 Let's Encrypt 인증서를 발급하고 HTTPS redirect와 자동 갱신도 설정합니다. 최소 2 CPU, RAM 4 GB, swap 4 GB와 충분한 디스크 공간이 필요하며 외부에는 80/443과 선택한 SSH 포트만 공개하십시오.

```bash
sudo apt update
sudo apt install -y git
cd "$HOME"
git clone https://github.com/askdkc/jin.git
cd jin
./setup.sh
```

sudo를 사용할 수 있는 일반 사용자로 실행하십시오. `setup.sh`가 들어 있는 clone된 repository(예: `/home/ubuntu/jin`)가 기본 애플리케이션 directory이며, 별도 대상이 필요할 때만 `--app-dir /var/www/jin`을 사용합니다. 대화형 설치는 sudo 인증, 공개 domain, 선택적인 Let's Encrypt email, IP 제한, 기본 언어, PostgreSQL password를 묻습니다. sudo password는 `sudo -v`가 직접 처리하며 Jin은 읽거나 저장하지 않습니다. PostgreSQL password를 비워 두면 안전한 값을 생성합니다. 이 script는 사용자를 sudoers에 추가하지 않습니다. 의도적으로 비밀번호 없는 sudo를 설정했다면 안내에 따라 `--sudo-nopasswd`를 사용하고, `--no-ssl`은 격리 환경에서만 사용하십시오. 생성된 database password는 `.env`와 `/etc/jin/database-password`(`root:root`, 권한 `0600`)에 저장됩니다. 배포 OS 사용자와 같은 이름의 PostgreSQL 관리자 역할은 애플리케이션 `DB_USER`와 분리하십시오.

최초 대화형 실행에서 설치 프로그램은 원본 IP를 기준으로 Jin 접근을 제한할지 묻습니다. 아무것도 입력하지 않고 Enter를 누르면 `IP_RESTRICTION_ENABLED=false`가 설정되어 모든 IP 주소의 접근을 허용합니다. 제한을 활성화한 경우 IP 주소 또는 CIDR 범위를 쉼표로 구분하여 입력하십시오. 여러 로컬 네트워크를 허용하려면 예를 들어 `192.168.1.0/24, 10.0.0.0/8`을 입력합니다. 설치 프로그램은 공백을 제거하고 상태 확인과 ONLYOFFICE 내부 통신에 필요한 `127.0.0.1,::1`을 앞에 자동으로 추가한 뒤 `.env`의 `ALLOWED_IPS`에 저장합니다.

이어서 설치 프로그램은 열 가지 언어를 표시하고 기본 언어를 선택하도록 요청합니다. 번호 또는 로캘 코드로 일본어, 영어, 프랑스어, 독일어, 중국어 간체, 중국어 번체, 스페인어, 브라질 포르투갈어, 유럽 포르투갈어 또는 한국어를 선택하십시오. 선택 없이 Enter를 누르면 일본어가 기본값입니다. 선택한 값은 `.env`의 `APP_LOCALE`에 저장됩니다.

TTY가 없는 환경에서는 IP 접근 제한 없음과 일본어가 기본값입니다. 자동 설치에서는 다음 환경 변수를 전달하십시오. `ALLOWED_IPS`만 지정해도 IP 제한이 활성화됩니다.

```bash
IP_RESTRICTION_ENABLED=true \
ALLOWED_IPS='192.168.1.0/24, 10.0.0.0/8' \
APP_LOCALE=ko \
./setup.sh --platform ubuntu --domain jin.example.com
```

이후 실행에서는 기존 `.env`의 `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS`, `APP_LOCALE`를 재사용하며 같은 질문을 반복하지 않습니다.

배포 후 확인:

```bash
curl -fsS https://jin.example.com/up
curl -fsS https://jin.example.com/onlyoffice/healthcheck
sudo supervisorctl status
sudo systemctl --no-pager --full status nginx php8.5-fpm postgresql redis-server onlyoffice-documentserver
sudo ss -ltnp
```

80/443 이외의 Jin 또는 ONLYOFFICE 서비스가 `0.0.0.0`이나 `[::]`에서 수신하면 안 됩니다.

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

## 문제 해결

- **모든 페이지가 404를 반환함:** `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS`, `TRUSTED_PROXIES`를 확인합니다.
- **채팅이 즉시 동기화되지 않음:** Reverb, Queue Worker, Vite, `REVERB_ALLOWED_ORIGINS`, 브라우저 콘솔, Laravel 로그를 확인합니다.
- **이메일이 발송되지 않음:** `MAIL_MAILER=log`는 `storage/logs/laravel.log`에만 기록합니다. 운영 환경에서는 실제 메일 드라이버를 설정하십시오.
- **화면 변경이 보이지 않음:** 개발 중에는 `npm run dev`를 사용하거나 `npm run build` 후 `php artisan optimize:clear`를 실행합니다.
- **ONLYOFFICE 오류:** 세 가지 통신 URL, 공유 JWT 비밀키, healthcheck, 콜백 접근성을 확인하되 전체 JWT를 로그에 남기지 마십시오.

모든 상세 운영 절차는 [일본어 README](../README.md)를 참고하십시오.

## 개발자 정보

이하 섹션은 로컬 개발, 구현 세부 사항, 연동 기능 및 품질 검사를 다룹니다. Ubuntu Server에 Jin을 배포하는 것만이 목적이라면 일반적으로 읽을 필요가 없습니다.

### 기술 구성

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

### 요구 사항

- PHP 8.5 이상과 Composer 2
- Node.js 22.12 이상과 npm
- 로컬 개발 및 일반 테스트용 PHP SQLite 확장
- 파일 본문 검색을 사용할 때 Python 3.10 이상과 MarkItDown
- PDF 첫 페이지 썸네일용 ImageMagick과 Poppler
- 운영 환경 파일 본문 검색용 PostgreSQL과 PGroonga
- Office 읽기 전용 미리보기용 ONLYOFFICE Docs

로컬 개발은 SQLite FTS5를 사용합니다. 운영 환경은 PostgreSQL과 PGroonga를 사용하며, MySQL은 운영 환경 파일 본문 검색 구성으로 지원하지 않습니다.

### 빠른 로컬 설치

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

### macOS와 ONLYOFFICE

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

### MCP 연동

로컬 Jin MCP 서버는 지정한 Jin 사용자 권한으로 전체 프로세스를 실행합니다.

```bash
php artisan jin:mcp user@example.com
```

신뢰할 수 있는 로컬 환경에서 최소 권한의 전용 계정으로만 실행하십시오. 해당 사용자의 팀 및 프로젝트 권한이 모든 MCP 도구에 적용됩니다.

### 파일 본문 검색

MarkItDown 비동기 추출 후 Everything은 `docx`, `xlsx`, `pptx`, `pdf`의 본문을 검색합니다. 이전 Office 및 OpenDocument 형식, 암호화되거나 비밀번호로 보호된 파일, 추출 실패, 크기 제한 초과 파일은 파일명만 검색합니다. Jin은 문서 비밀번호를 받거나 저장하거나 추출기에 전달하지 않습니다.

```bash
php artisan stored-files:backfill-content
php artisan stored-files:backfill-content --retry-failed
php artisan stored-files:backfill-content --reindex
```

### 테스트와 품질 확인

```bash
composer ci:check
composer test
npm run lint:check
npm run format:check
npm run types:check
npm run build
```
