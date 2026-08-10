# Jin

## Sprachen

[English](README_en.md) | [日本語](../README.md) | [Français](README_fr.md) | [Deutsch](README_de.md) | [简体中文](README_zh_CN.md) | [繁體中文](README_zh_TW.md) | [Español](README_es.md) | [Português (Brasil)](README_pt_BR.md) | [Português (Portugal)](README_pt_PT.md) | [한국어](README_ko.md)

Jin (陣) ist eine selbst gehostete Groupware, mit der mehrere Teams projektbezogen zusammenarbeiten. Nachrichten, Aufgaben, Termine, Dateien, Echtzeit-Chat, Kartentabellen und regelmäßige Check-ins werden in einem Projektarbeitsbereich zusammengeführt.

## Hauptfunktionen

- Teams beziehungsweise Arbeitsbereiche erstellen und wechseln, Mitglieder einladen und Rechte verwalten
- Projekte erstellen, bearbeiten, terminieren, archivieren und auf Teilnehmer begrenzen
- Nachrichtenboard mit Kommentaren, Abonnements, Pins und Anhängen
- Rich-Text-Eingabe mit Trix und serverseitiger HTML-Bereinigung
- Aufgabenlisten, Zuständige, Fristen, Unteraufgaben, Kommentare, Anhänge und Drag-and-drop-Sortierung
- Projekttermine sowie projektübergreifende Tages-, Wochen-, Monats- und Gantt-Ansichten
- Datei-Uploads per Drag-and-drop und autorisierte Downloads
- Echtzeit-Projektchat mit Laravel Reverb
- Kartentabellen im Kanban-Stil
- Wiederkehrende Check-ins mit Antwortverlauf
- Aktivitäten, Berichte, Lineup und globale Suche einschließlich unterstützter Dateiinhalte
- Signierte, schreibgeschützte iCalendar-Abonnements
- E-Mail-Bestätigung, Zwei-Faktor-Authentifizierung und Passkeys
- Seitenweite IPv4-, IPv6- und CIDR-Beschränkungen
- Responsive Oberfläche für Desktop und Mobilgeräte

## Technik

| Bereich           | Technologie                                                    |
|-------------------|----------------------------------------------------------------|
| Backend           | PHP 8.5 / Laravel 13                                           |
| Frontend          | Inertia.js 3 / Svelte 5 / TypeScript                           |
| UI                | Tailwind CSS 4                                                 |
| Rich Text         | Trix 2 / Symfony HTML Sanitizer 8                              |
| Echtzeit          | Laravel Reverb / Laravel Echo                                  |
| Authentifizierung | Laravel Fortify / Passkeys / 2FA                               |
| Typisierte Routen | Laravel Wayfinder                                              |
| Queue             | Laravel database queue (Entwicklung) / Redis (Produktion)      |
| Datenbank         | SQLite FTS5 (Entwicklung) / PostgreSQL + PGroonga (Produktion) |
| Tests             | Pest 4 / PHPStan / Svelte Check / ESLint                       |

## Voraussetzungen

- PHP 8.5 oder neuer und Composer 2
- Node.js 22.12 oder neuer und npm
- PHP-SQLite-Erweiterung für lokale Entwicklung und reguläre Tests
- Python 3.10 oder neuer und MarkItDown für die Dateiinhaltsuche
- ImageMagick und Poppler für PDF-Vorschaubilder
- PostgreSQL und PGroonga für die Dateiinhaltsuche in Produktion
- ONLYOFFICE Docs für schreibgeschützte Office-Vorschauen

Lokal wird SQLite FTS5 verwendet. In Produktion wird PostgreSQL mit PGroonga eingesetzt; MySQL wird für die produktive Dateiinhaltsuche nicht unterstützt.

## Lokale Schnellinstallation

In einem frischen Klon:

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

Die Prozesse in getrennten Terminals starten:

```bash
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
php artisan queue:work
php artisan reverb:start
```

Für Fristbenachrichtigungen und regelmäßige Check-ins zusätzlich `php artisan schedule:work` starten und <http://localhost:8000> öffnen. Das ausschließlich für die Entwicklung bestimmte Beispielkonto lautet `test@example.com` mit dem Passwort `password`. Niemals in Produktion verwenden.

## macOS und ONLYOFFICE

Der unterstützte macOS-Weg verwendet Homebrew und Apple [`container`](https://github.com/apple/container). Apple Container erfordert Apple Silicon und macOS 26 oder neuer.

```bash
brew install php composer node@22 python imagemagick poppler
container system start
./setup.sh --platform macos --app-dir "$PWD" --no-nginx
```

Für ONLYOFFICE einen zufälligen, mindestens 32 Zeichen langen Schlüssel setzen und niemals committen:

```dotenv
ONLYOFFICE_ENABLED=true
ONLYOFFICE_JWT_SECRET=replace-with-at-least-32-random-characters
```

Die [maßgeblichen macOS-Anweisungen](../README.md#macos開発環境) enthalten localhost-DNS-Mapping, Container-Lebenszyklus und Fehlerbehebung.

## MCP-Integration

Der lokale Jin-MCP-Server wird vollständig mit den Berechtigungen eines festgelegten Jin-Benutzers ausgeführt:

```bash
php artisan jin:mcp user@example.com
```

Nur in einer vertrauenswürdigen lokalen Umgebung und mit einem dedizierten Konto mit minimalen Rechten ausführen. Dessen Team- und Projektberechtigungen gelten für jedes MCP-Werkzeug.

## Produktion auf Ubuntu 24.04 / 26.04

`setup.sh` richtet Jin, PHP, PostgreSQL/PGroonga, Redis, Node.js, MarkItDown, Nginx, Supervisor, TLS und ONLYOFFICE Docs ein. Unterstützt wird Ubuntu Server amd64 mit mindestens 2 CPUs, 4 GB RAM, 4 GB Swap und ausreichend Speicherplatz. Öffentlich dürfen nur 80/443 und der gewählte SSH-Port erreichbar sein.

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

Für Let's Encrypt `--email admin@example.com` ergänzen. `--no-ssl` ist nur für isolierte Umgebungen gedacht. Bei absichtlich passwortlosem sudo auf Aufforderung `--sudo-nopasswd` verwenden. Das erzeugte Datenbankpasswort liegt in `.env` und `/etc/jin/database-password` (`root:root`, Modus `0600`). Die PostgreSQL-Administratorrolle des Betriebssystembenutzers muss von `DB_USER` der Anwendung getrennt bleiben.

Bei der ersten interaktiven Ausführung fragt das Installationsprogramm, ob der Zugriff auf Jin anhand der Quell-IP eingeschränkt werden soll. Wenn Sie ohne Eingabe die Eingabetaste drücken, wird `IP_RESTRICTION_ENABLED=false` gesetzt und der Zugriff von allen IP-Adressen erlaubt. Bei aktivierter Einschränkung geben Sie IP-Adressen oder CIDR-Bereiche durch Kommas getrennt ein. Um mehrere lokale Netze zuzulassen, verwenden Sie beispielsweise `192.168.1.0/24, 10.0.0.0/8`. Leerzeichen werden entfernt; anschließend werden die für Zustandsprüfungen und die interne ONLYOFFICE-Kommunikation benötigten Loopback-Adressen `127.0.0.1,::1` vorangestellt und das Ergebnis als `ALLOWED_IPS` in `.env` gespeichert.

Danach zeigt das Installationsprogramm zehn Sprachen an und fragt nach der Standardsprache. Wählen Sie Japanisch, Englisch, Französisch, Deutsch, vereinfachtes Chinesisch, traditionelles Chinesisch, Spanisch, brasilianisches Portugiesisch, europäisches Portugiesisch oder Koreanisch anhand der Nummer oder des Locale-Codes. Ohne Eingabe wird Japanisch verwendet. Der gewählte Wert wird als `APP_LOCALE` in `.env` gespeichert.

Ohne TTY gelten uneingeschränkter IP-Zugriff und Japanisch als Standard. Übergeben Sie für eine automatisierte Installation die folgenden Umgebungsvariablen. Auch die alleinige Angabe von `ALLOWED_IPS` aktiviert die IP-Einschränkung.

```bash
IP_RESTRICTION_ENABLED=true \
ALLOWED_IPS='192.168.1.0/24, 10.0.0.0/8' \
APP_LOCALE=de \
./setup.sh --platform ubuntu --domain jin.example.com --app-dir /var/www/jin
```

Bei späteren Ausführungen werden `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS` und `APP_LOCALE` aus der vorhandenen `.env` wiederverwendet; die Fragen werden nicht erneut gestellt.

Prüfungen nach der Bereitstellung:

```bash
curl -fsS https://jin.example.com/up
curl -fsS https://jin.example.com/onlyoffice/healthcheck
sudo supervisorctl status
sudo systemctl --no-pager --full status nginx php8.5-fpm postgresql redis-server onlyoffice-documentserver
sudo ss -ltnp
```

Außer 80/443 darf kein Jin- oder ONLYOFFICE-Dienst an `0.0.0.0` oder `[::]` gebunden sein.

## Dateiinhaltsuche

Nach asynchroner Extraktion mit MarkItDown durchsucht Everything `docx`, `xlsx`, `pptx` und `pdf`. Alte Office- und OpenDocument-Formate, verschlüsselte oder passwortgeschützte Dateien, fehlgeschlagene Extraktionen und zu große Dateien werden nur anhand des Dateinamens gefunden. Jin nimmt Dokumentpasswörter weder an noch speichert oder übermittelt sie.

```bash
php artisan stored-files:backfill-content
php artisan stored-files:backfill-content --retry-failed
php artisan stored-files:backfill-content --reindex
```

## Sicherheitsgrenzen

- Dateien bleiben unter `storage/app/private` und werden nur über autorisierte Downloadrouten ausgeliefert.
- Client-Zugriff wird pro Projekt festgelegt.
- Signierte Kalender-Abonnement-URLs sind wie Passwörter zu behandeln.
- ONLYOFFICE-JWT-Schlüssel und vollständig signierte URLs dürfen nie protokolliert oder committed werden.
- IP-Beschränkungen gelten auch für die Anmeldung und `/up`.

```dotenv
IP_RESTRICTION_ENABLED=true
ALLOWED_IPS=203.0.113.10,198.51.100.0/24,2001:db8::/32
TRUSTED_PROXIES=10.0.0.0/8
```

Für vertrauenswürdige Proxys niemals `*` verwenden. Nach Änderungen `php artisan optimize:clear` ausführen.

## Tests und Qualitätsprüfungen

```bash
composer ci:check
composer test
npm run lint:check
npm run format:check
npm run types:check
npm run build
```

## Fehlerbehebung

- **Alle Seiten liefern 404:** `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS` und `TRUSTED_PROXIES` prüfen.
- **Chat erscheint nicht sofort:** Reverb, Queue Worker, Vite, `REVERB_ALLOWED_ORIGINS`, Browserkonsole und Laravel-Logs prüfen.
- **E-Mails werden nicht versendet:** `MAIL_MAILER=log` schreibt nach `storage/logs/laravel.log`; in Produktion einen echten Mail-Transport konfigurieren.
- **UI-Änderungen fehlen:** `npm run dev` verwenden oder `npm run build` und `php artisan optimize:clear` ausführen.
- **ONLYOFFICE-Fehler:** drei Kommunikations-URLs, gemeinsamen JWT-Schlüssel, Healthcheck und Callback-Erreichbarkeit prüfen, ohne vollständige JWTs zu protokollieren.

Alle ausführlichen Betriebsanweisungen stehen in der [japanischen README](../README.md).
