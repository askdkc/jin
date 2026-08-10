# Jin

## Idiomas

[English](README_en.md) | [日本語](../README.md) | [Français](README_fr.md) | [Deutsch](README_de.md) | [简体中文](README_zh_CN.md) | [繁體中文](README_zh_TW.md) | [Español](README_es.md) | [Português (Brasil)](README_pt_BR.md) | [Português (Portugal)](README_pt_PT.md) | [한국어](README_ko.md)

Jin (陣) es un sistema de trabajo colaborativo autoalojado para que varios equipos cooperen por proyectos. Reúne mensajes, tareas, calendarios, archivos, chat en tiempo real, tableros de tarjetas y revisiones periódicas en un único espacio de proyecto.

La arquitectura de la información está inspirada en Basecamp, pero los nombres, las pantallas, los colores y los iconos son propios. El [README en japonés](../README.md) es la guía operativa completa y canónica. Esta traducción cubre el producto, los requisitos, la instalación, los límites de seguridad y los comandos de verificación.

## Funciones principales

- Crear y cambiar equipos, invitar miembros y administrar permisos
- Crear, editar, fechar, limitar y archivar proyectos
- Tablón de mensajes con comentarios, suscripciones, elementos fijados y adjuntos
- Texto enriquecido con Trix y saneamiento HTML en el servidor
- Listas de tareas, responsables, vencimientos, subtareas, comentarios, adjuntos y ordenación mediante arrastrar y soltar
- Calendarios de proyecto y vistas globales diaria, semanal, mensual y Gantt
- Carga de archivos mediante arrastrar y soltar y descargas autorizadas
- Chat de proyecto en tiempo real con Laravel Reverb
- Tableros de tarjetas Kanban
- Revisiones periódicas e historial de respuestas
- Actividad, informes, planificación y búsqueda global, incluido el contenido de archivos compatibles
- Suscripciones iCalendar firmadas y de solo lectura
- Verificación de correo, autenticación de dos factores y passkeys
- Restricciones globales IPv4, IPv6 y CIDR
- Interfaz adaptable a escritorio y dispositivos móviles

## Tecnologías

| Área              | Tecnología                                                    |
| ----------------- | ------------------------------------------------------------- |
| Backend           | PHP 8.5 / Laravel 13                                          |
| Frontend          | Inertia.js 3 / Svelte 5 / TypeScript                          |
| Interfaz          | Tailwind CSS 4                                                |
| Texto enriquecido | Trix 2 / Symfony HTML Sanitizer 8                             |
| Tiempo real       | Laravel Reverb / Laravel Echo                                 |
| Autenticación     | Laravel Fortify / Passkeys / 2FA                              |
| Rutas tipadas     | Laravel Wayfinder                                             |
| Colas             | Laravel database queue (desarrollo) / Redis (producción)      |
| Base de datos     | SQLite FTS5 (desarrollo) / PostgreSQL + PGroonga (producción) |
| Pruebas           | Pest 4 / PHPStan / Svelte Check / ESLint                      |

## Requisitos

- PHP 8.5 o posterior y Composer 2
- Node.js 22.12 o posterior y npm
- Extensión SQLite de PHP para desarrollo local y pruebas habituales
- Python 3.10 o posterior y MarkItDown para buscar dentro de archivos
- ImageMagick y Poppler para miniaturas de la primera página de archivos PDF
- PostgreSQL y PGroonga para la búsqueda de contenido en producción
- ONLYOFFICE Docs para vistas previas de Office en modo de solo lectura

El desarrollo local usa SQLite FTS5. Producción usa PostgreSQL con PGroonga; MySQL no está admitido para la búsqueda de contenido de archivos en producción.

## Instalación local rápida

Desde un clon nuevo:

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

Inicia cada proceso en un terminal distinto:

```bash
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
php artisan queue:work
php artisan reverb:start
```

Inicia también `php artisan schedule:work` si necesitas avisos de vencimiento y revisiones periódicas. Abre <http://localhost:8000>. Tras cargar los datos iniciales, la cuenta exclusiva para desarrollo es `test@example.com` con contraseña `password`. No la uses en producción.

## macOS y ONLYOFFICE

La configuración compatible con macOS usa Homebrew y Apple [`container`](https://github.com/apple/container), que requiere Apple silicon y macOS 26 o posterior.

```bash
brew install php composer node@22 python imagemagick poppler
container system start
./setup.sh --platform macos --app-dir "$PWD" --no-nginx
```

Al activar ONLYOFFICE, define un secreto aleatorio de al menos 32 caracteres y nunca lo confirmes en Git:

```dotenv
ONLYOFFICE_ENABLED=true
ONLYOFFICE_JWT_SECRET=replace-with-at-least-32-random-characters
```

Consulta las [instrucciones canónicas para macOS](../README.md#macos開発環境) para el mapeo DNS de localhost, el ciclo de vida del contenedor y la recuperación.

## Integración MCP

El servidor MCP local de Jin ejecuta todo el proceso con los permisos de un usuario Jin fijo:

```bash
php artisan jin:mcp user@example.com
```

Úsalo solo en un entorno local de confianza y con una cuenta dedicada de privilegios mínimos. Los permisos de equipo y proyecto de ese usuario se aplican a todas las herramientas MCP.

## Producción en Ubuntu 24.04 / 26.04

`setup.sh` instala Jin, PHP, PostgreSQL/PGroonga, Redis, Node.js, MarkItDown, Nginx, Supervisor, TLS y ONLYOFFICE Docs. El destino compatible es Ubuntu Server amd64 con al menos 2 CPU, 4 GB de RAM, 4 GB de swap y espacio en disco suficiente. Solo los puertos 80/443 y el puerto SSH elegido deben exponerse públicamente.

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

Añade `--email admin@example.com` para Let's Encrypt. Usa `--no-ssl` únicamente en un entorno aislado. Si sudo sin contraseña está configurado intencionadamente, usa `--sudo-nopasswd` cuando el instalador lo indique. La contraseña de base de datos generada se guarda en `.env` y `/etc/jin/database-password` (`root:root`, modo `0600`). Mantén el rol administrador de PostgreSQL del usuario del sistema separado del `DB_USER` de la aplicación.

Comprobaciones posteriores:

```bash
curl -fsS https://jin.example.com/up
curl -fsS https://jin.example.com/onlyoffice/healthcheck
sudo supervisorctl status
sudo systemctl --no-pager --full status nginx php8.5-fpm postgresql redis-server onlyoffice-documentserver
sudo ss -ltnp
```

Ningún servicio de Jin u ONLYOFFICE aparte de 80/443 debe escuchar en `0.0.0.0` o `[::]`.

## Búsqueda en archivos

Después de la extracción asíncrona con MarkItDown, Everything busca dentro de `docx`, `xlsx`, `pptx` y `pdf`. Los formatos antiguos de Office y OpenDocument, los archivos cifrados o protegidos por contraseña, las extracciones fallidas y los archivos demasiado grandes se buscan solo por nombre. Jin no acepta, guarda ni envía contraseñas de documentos al extractor.

```bash
php artisan stored-files:backfill-content
php artisan stored-files:backfill-content --retry-failed
php artisan stored-files:backfill-content --reindex
```

## Límites de seguridad

- Los archivos permanecen en `storage/app/private` y se sirven mediante rutas de descarga autorizadas.
- La visibilidad de Client se define por proyecto.
- Las URL firmadas de suscripción al calendario deben tratarse como contraseñas.
- Los secretos JWT de ONLYOFFICE y las URL firmadas completas nunca deben registrarse ni confirmarse.
- Las restricciones IP también cubren el inicio de sesión y `/up`.

```dotenv
IP_RESTRICTION_ENABLED=true
ALLOWED_IPS=203.0.113.10,198.51.100.0/24,2001:db8::/32
TRUSTED_PROXIES=10.0.0.0/8
```

No uses `*` para proxies de confianza. Ejecuta `php artisan optimize:clear` después de modificar estos valores.

## Pruebas y calidad

```bash
composer ci:check
composer test
npm run lint:check
npm run format:check
npm run types:check
npm run build
```

## Solución de problemas

- **Todas las páginas devuelven 404:** revisa `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS` y `TRUSTED_PROXIES`.
- **El chat no aparece de inmediato:** comprueba Reverb, el worker de cola, Vite, `REVERB_ALLOWED_ORIGINS`, la consola del navegador y los registros de Laravel.
- **No se envían correos:** `MAIL_MAILER=log` escribe en `storage/logs/laravel.log`; configura un transporte real en producción.
- **No aparecen los cambios de interfaz:** usa `npm run dev`, o ejecuta `npm run build` seguido de `php artisan optimize:clear`.
- **Error de ONLYOFFICE:** revisa las tres URL de comunicación, el secreto JWT compartido, el healthcheck y el acceso al callback sin registrar nunca el JWT completo.

Para todos los procedimientos operativos detallados, consulta el [README en japonés](../README.md).
