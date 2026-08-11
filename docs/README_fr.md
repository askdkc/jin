# Jin

[English](README_en.md) | [日本語](../README.md) | [Français](README_fr.md) | [Deutsch](README_de.md) | [简体中文](README_zh_CN.md) | [繁體中文](README_zh_TW.md) | [Español](README_es.md) | [Português (Brasil)](README_pt_BR.md) | [Português (Portugal)](README_pt_PT.md) | [한국어](README_ko.md)

Jin (陣) est un collecticiel auto-hébergé permettant à plusieurs équipes de travailler ensemble par projet. Il réunit messages, tâches, calendrier, fichiers, chat en temps réel, tableaux Kanban et points de suivi récurrents dans un même espace.

<p align="center">
  <img width="100%" alt="Présentation du service Jin" src="./assets/jin-service-introduction.gif" />
</p>

## Production sur Ubuntu 24.04 / 26.04

Sur Ubuntu Server 24.04 / 26.04 amd64, l'exécution de `setup.sh` construit un environnement public Jin complet. Laravel et la base de données ne nécessitent aucune installation ni configuration manuelle.

Le script installe les paquets APT requis, PHP 8.5, Composer et Node.js 24 ; clone ou met à jour Jin ; installe les dépendances Laravel ; génère `.env` et la clé d'application ; exécute les migrations et la compilation frontend ; configure PostgreSQL/PGroonga, la base de données, les rôles et l'authentification peer ; puis installe Redis, RabbitMQ, les workers de file d'attente, Reverb, Scheduler, Supervisor, Nginx, PHP-FPM, ONLYOFFICE Docs et les outils de traitement de fichiers. Sauf avec `--no-ssl`, il obtient également un certificat Let's Encrypt et configure la redirection HTTPS ainsi que le renouvellement automatique. La cible nécessite au moins 2 CPU, 4 Go de RAM, 4 Go de swap et suffisamment d'espace disque. N'exposez publiquement que les ports 80/443 et le port SSH choisi.

```bash
sudo apt update
sudo apt install -y git
cd "$HOME"
git clone https://github.com/askdkc/jin.git
cd jin
./setup.sh
```

Exécutez ces commandes en tant qu'utilisateur ordinaire autorisé à utiliser sudo. Le dépôt cloné contenant `setup.sh`, par exemple `/home/ubuntu/jin`, est le répertoire d'application par défaut ; n'utilisez `--app-dir /var/www/jin` que pour une destination distincte. L'installation interactive demande l'authentification sudo, le domaine public, une adresse e-mail Let's Encrypt facultative, les restrictions IP, la langue par défaut et le mot de passe PostgreSQL. `sudo -v` traite directement le mot de passe sudo, que Jin ne lit ni ne conserve. Laisser vide le mot de passe PostgreSQL en génère un de façon sûre. Le script n'ajoute aucun utilisateur à sudoers. Avec un sudo volontairement sans mot de passe, utilisez `--sudo-nopasswd` lorsque demandé ; `--no-ssl` est réservé aux environnements isolés. Le mot de passe de base de données généré est conservé dans `.env` et `/etc/jin/database-password` (`root:root`, mode `0600`). Le rôle PostgreSQL d'administration correspondant à l'utilisateur système doit rester distinct du `DB_USER` de l'application.

Lors de la première exécution interactive, le programme d'installation demande si l'accès à Jin doit être limité selon l'adresse IP source. Appuyer sur Entrée sans rien saisir définit `IP_RESTRICTION_ENABLED=false` et autorise toutes les adresses IP. Si la restriction est activée, saisissez les adresses IP ou les plages CIDR séparées par des virgules. Pour autoriser plusieurs réseaux locaux, utilisez par exemple `192.168.1.0/24, 10.0.0.0/8`. Les espaces sont supprimés, puis `127.0.0.1,::1`, nécessaires aux contrôles d'état et aux communications internes avec ONLYOFFICE, sont ajoutées au début avant l'enregistrement dans `ALLOWED_IPS` du fichier `.env`.

Le programme affiche ensuite dix langues et demande la langue par défaut. Sélectionnez le japonais, l'anglais, le français, l'allemand, le chinois simplifié, le chinois traditionnel, l'espagnol, le portugais brésilien, le portugais européen ou le coréen par numéro ou code de langue. Sans saisie, le japonais est utilisé. La valeur choisie est enregistrée dans `APP_LOCALE` du fichier `.env`.

Sans TTY, les valeurs par défaut sont l'accès IP sans restriction et le japonais. Pour une installation automatisée, transmettez les variables d'environnement suivantes. Définir uniquement `ALLOWED_IPS` active également la restriction IP.

```bash
IP_RESTRICTION_ENABLED=true \
ALLOWED_IPS='192.168.1.0/24, 10.0.0.0/8' \
APP_LOCALE=fr \
./setup.sh --platform ubuntu --domain jin.example.com
```

Les exécutions suivantes réutilisent `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS` et `APP_LOCALE` du fichier `.env` existant et ne reposent pas ces questions.

Vérifications après déploiement :

```bash
curl -fsS https://jin.example.com/up
curl -fsS https://jin.example.com/onlyoffice/healthcheck
sudo supervisorctl status
sudo systemctl --no-pager --full status nginx php8.5-fpm postgresql redis-server onlyoffice-documentserver
sudo ss -ltnp
```

Aucun service Jin ou ONLYOFFICE autre que 80/443 ne doit écouter sur `0.0.0.0` ou `[::]`.

## Fonctionnalités principales

- Création et changement d'équipe, invitations et gestion des autorisations
- Création, modification, datation, archivage et contrôle d'accès des projets
- Tableau de messages avec commentaires, abonnements, épingles et pièces jointes
- Texte enrichi avec Trix et assainissement HTML côté serveur
- Listes de tâches, responsables, échéances, sous-tâches, commentaires et tri par glisser-déposer
- Calendriers par projet et vues transversales jour, semaine, mois et Gantt
- Téléversement de fichiers par glisser-déposer et téléchargements authentifiés
- Chat de projet en temps réel avec Laravel Reverb
- Tableaux de cartes Kanban par glisser-déposer
- Questions périodiques et historique des réponses
- Activité, recherche globale incluant le contenu des fichiers compatibles, rapports et planification
- Abonnements iCalendar signés et en lecture seule
- Vérification d'adresse e-mail, authentification à deux facteurs et passkeys
- Restriction globale par IPv4, IPv6 et CIDR
- Interface adaptative pour ordinateur et mobile

## Limites de sécurité

- Les fichiers restent sous `storage/app/private` et passent par des routes de téléchargement autorisées.
- L'accès Client est défini projet par projet.
- Les URL d'abonnement calendrier signées doivent être traitées comme des mots de passe.
- Les secrets JWT ONLYOFFICE et les URL signées complètes ne doivent jamais être journalisés ou versionnés.
- Les restrictions IP couvrent également la page de connexion et `/up`.

```dotenv
IP_RESTRICTION_ENABLED=true
ALLOWED_IPS=203.0.113.10,198.51.100.0/24,2001:db8::/32
TRUSTED_PROXIES=10.0.0.0/8
```

N'utilisez pas `*` pour les proxys de confiance. Exécutez `php artisan optimize:clear` après toute modification.

## Dépannage

- **Toutes les pages renvoient 404 :** vérifiez `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS` et `TRUSTED_PROXIES`.
- **Le chat n'arrive pas immédiatement :** vérifiez Reverb, le worker de file d'attente, Vite, `REVERB_ALLOWED_ORIGINS`, la console du navigateur et les journaux Laravel.
- **Les e-mails ne partent pas :** `MAIL_MAILER=log` écrit dans `storage/logs/laravel.log` ; configurez un transport réel en production.
- **Les changements d'interface n'apparaissent pas :** utilisez `npm run dev`, ou `npm run build` puis `php artisan optimize:clear`.
- **Erreur ONLYOFFICE :** contrôlez les trois URL de communication, le secret JWT partagé, le healthcheck et l'accès au callback sans jamais journaliser le JWT complet.

Pour toutes les procédures détaillées, utilisez le [README japonais](../README.md).

## Informations pour les développeurs

Les sections suivantes concernent le développement local, les détails d’implémentation, les intégrations et les contrôles qualité. Elles ne sont normalement pas nécessaires pour déployer uniquement Jin sur Ubuntu Server.

### Pile technique

| Domaine          | Technologie                                                      |
| ---------------- | ---------------------------------------------------------------- |
| Backend          | PHP 8.5 / Laravel 13                                             |
| Frontend         | Inertia.js 3 / Svelte 5 / TypeScript                             |
| Interface        | Tailwind CSS 4                                                   |
| Texte enrichi    | Trix 2 / Symfony HTML Sanitizer 8                                |
| Temps réel       | Laravel Reverb / Laravel Echo                                    |
| Authentification | Laravel Fortify / Passkeys / 2FA                                 |
| Routes typées    | Laravel Wayfinder                                                |
| Files d'attente  | Laravel database queue (développement) / Redis (production)      |
| Base de données  | SQLite FTS5 (développement) / PostgreSQL + PGroonga (production) |
| Tests            | Pest 4 / PHPStan / Svelte Check / ESLint                         |

### Prérequis

- PHP 8.5 ou supérieur et Composer 2
- Node.js 22.12 ou supérieur et npm
- Extension PHP SQLite pour le développement et les tests usuels
- Python 3.10 ou supérieur et MarkItDown pour la recherche dans le contenu des fichiers
- ImageMagick et Poppler pour les miniatures de la première page des PDF
- PostgreSQL et PGroonga pour la recherche de contenu en production
- ONLYOFFICE Docs pour l'aperçu en lecture seule des documents Office

Le développement local utilise SQLite FTS5. La production utilise PostgreSQL avec PGroonga ; MySQL n'est pas une configuration de production prise en charge pour la recherche dans les fichiers.

### Installation locale rapide

Depuis un clone neuf :

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

Lancez les processus dans des terminaux séparés :

```bash
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
php artisan queue:work
php artisan reverb:start
```

Ajoutez `php artisan schedule:work` pour les rappels d'échéance et les points de suivi. Ouvrez <http://localhost:8000>. Après l'amorçage, le compte de démonstration réservé au développement est `test@example.com` avec le mot de passe `password`. Ne l'utilisez jamais en production.

### macOS et ONLYOFFICE

La configuration macOS prise en charge utilise Homebrew et Apple [`container`](https://github.com/apple/container), qui exige une puce Apple et macOS 26 ou supérieur.

```bash
brew install php composer node@22 python imagemagick poppler
container system start
./setup.sh --platform macos --app-dir "$PWD" --no-nginx
```

Pour activer ONLYOFFICE, configurez un secret aléatoire d'au moins 32 caractères et ne le versionnez jamais :

```dotenv
ONLYOFFICE_ENABLED=true
ONLYOFFICE_JWT_SECRET=replace-with-at-least-32-random-characters
```

Consultez les [instructions macOS canoniques](../README.md#macos開発環境) pour le mappage DNS localhost, le cycle de vie du conteneur et la récupération.

### Intégration MCP

Le serveur MCP local exécute tout le processus avec les autorisations d'un utilisateur Jin fixe :

```bash
php artisan jin:mcp user@example.com
```

Utilisez un compte dédié avec le minimum de privilèges, uniquement dans un environnement local de confiance. Les autorisations d'équipe et de projet de cet utilisateur s'appliquent à tous les outils MCP.

### Recherche dans les fichiers

Après extraction asynchrone avec MarkItDown, Everything recherche dans `docx`, `xlsx`, `pptx` et `pdf`. Les formats anciens, les formats OpenDocument, les fichiers chiffrés ou protégés par mot de passe, les extractions échouées et les fichiers trop volumineux restent recherchables uniquement par nom. Jin ne collecte ni ne transmet les mots de passe des documents.

```bash
php artisan stored-files:backfill-content
php artisan stored-files:backfill-content --retry-failed
php artisan stored-files:backfill-content --reindex
```

### Tests et qualité

```bash
composer ci:check
composer test
npm run lint:check
npm run format:check
npm run types:check
npm run build
```
