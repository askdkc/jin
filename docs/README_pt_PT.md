# Jin

## Idiomas

[English](README_en.md) | [日本語](../README.md) | [Français](README_fr.md) | [Deutsch](README_de.md) | [简体中文](README_zh_CN.md) | [繁體中文](README_zh_TW.md) | [Español](README_es.md) | [Português (Brasil)](README_pt_BR.md) | [Português (Portugal)](README_pt_PT.md) | [한국어](README_ko.md)

Jin (陣) é uma plataforma colaborativa autoalojada para várias equipas trabalharem por projeto. Reúne mensagens, tarefas, agenda, ficheiros, conversa em tempo real, quadros de cartões e pontos de situação recorrentes num único espaço de projeto.

## Funcionalidades principais

- Criar e alternar equipas, convidar membros e gerir permissões
- Criar, editar, definir datas e âmbito e arquivar projetos
- Quadro de mensagens com comentários, subscrições, itens afixados e anexos
- Texto formatado com Trix e sanitização de HTML no servidor
- Listas de tarefas, responsáveis, prazos, subtarefas, comentários, anexos e ordenação por arrastar e largar
- Agendas por projeto e vistas globais diária, semanal, mensal e Gantt
- Carregamento por arrastar e largar e transferências autorizadas
- Conversa de projeto em tempo real com Laravel Reverb
- Quadros de cartões Kanban
- Pontos de situação recorrentes e histórico de respostas
- Atividade, relatórios, lineup e pesquisa global, incluindo conteúdo de ficheiros compatíveis
- Subscrições iCalendar assinadas e só de leitura
- Verificação de correio eletrónico, autenticação de dois fatores e passkeys
- Restrições globais por IPv4, IPv6 e CIDR
- Interface adaptável a computador e dispositivos móveis

## Tecnologias

| Área            | Tecnologia                                                       |
|-----------------|------------------------------------------------------------------|
| Backend         | PHP 8.5 / Laravel 13                                             |
| Frontend        | Inertia.js 3 / Svelte 5 / TypeScript                             |
| Interface       | Tailwind CSS 4                                                   |
| Texto formatado | Trix 2 / Symfony HTML Sanitizer 8                                |
| Tempo real      | Laravel Reverb / Laravel Echo                                    |
| Autenticação    | Laravel Fortify / Passkeys / 2FA                                 |
| Rotas tipadas   | Laravel Wayfinder                                                |
| Filas           | Laravel database queue (desenvolvimento) / Redis (produção)      |
| Base de dados   | SQLite FTS5 (desenvolvimento) / PostgreSQL + PGroonga (produção) |
| Testes          | Pest 4 / PHPStan / Svelte Check / ESLint                         |

## Requisitos

- PHP 8.5 ou posterior e Composer 2
- Node.js 22.12 ou posterior e npm
- Extensão SQLite do PHP para desenvolvimento local e testes habituais
- Python 3.10 ou posterior e MarkItDown para pesquisa no conteúdo dos ficheiros
- ImageMagick e Poppler para miniaturas da primeira página de PDF
- PostgreSQL e PGroonga para pesquisa de conteúdo em produção
- ONLYOFFICE Docs para pré-visualização de documentos Office só de leitura

O desenvolvimento local utiliza SQLite FTS5. A produção utiliza PostgreSQL com PGroonga; MySQL não é suportado para pesquisa de conteúdo de ficheiros em produção.

## Instalação local rápida

Num clone novo:

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

Inicie cada processo num terminal separado:

```bash
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
php artisan queue:work
php artisan reverb:start
```

Inicie também `php artisan schedule:work` se precisar de avisos de prazo e pontos de situação recorrentes. Abra <http://localhost:8000>. Após o seed, a conta exclusiva de desenvolvimento é `test@example.com`, com a palavra-passe `password`. Não a utilize em produção.

## macOS e ONLYOFFICE

O método suportado em macOS utiliza Homebrew e Apple [`container`](https://github.com/apple/container), que requer Apple silicon e macOS 26 ou posterior.

```bash
brew install php composer node@22 python imagemagick poppler
container system start
./setup.sh --platform macos --app-dir "$PWD" --no-nginx
```

Ao ativar ONLYOFFICE, defina um segredo aleatório com pelo menos 32 caracteres e nunca o submeta ao Git:

```dotenv
ONLYOFFICE_ENABLED=true
ONLYOFFICE_JWT_SECRET=replace-with-at-least-32-random-characters
```

Consulte as [instruções canónicas para macOS](../README.md#macos開発環境) sobre mapeamento DNS de localhost, ciclo de vida do contentor e recuperação.

## Integração MCP

O servidor MCP local do Jin executa todo o processo com as permissões de um utilizador Jin fixo:

```bash
php artisan jin:mcp user@example.com
```

Utilize apenas num ambiente local de confiança e com uma conta dedicada de privilégio mínimo. As permissões de equipa e projeto desse utilizador aplicam-se a todas as ferramentas MCP.

## Produção em Ubuntu 24.04 / 26.04

`setup.sh` instala Jin, PHP, PostgreSQL/PGroonga, Redis, Node.js, MarkItDown, Nginx, Supervisor, TLS e ONLYOFFICE Docs. O destino suportado é Ubuntu Server amd64 com pelo menos 2 CPUs, 4 GB de RAM, 4 GB de swap e espaço suficiente em disco. Exponha publicamente apenas 80/443 e a porta SSH escolhida.

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

Adicione `--email admin@example.com` para Let's Encrypt. Utilize `--no-ssl` apenas num ambiente isolado. Quando sudo sem palavra-passe estiver configurado de forma intencional, use `--sudo-nopasswd` quando o instalador indicar. A palavra-passe gerada da base de dados fica em `.env` e `/etc/jin/database-password` (`root:root`, modo `0600`). Mantenha o papel administrativo do PostgreSQL associado ao utilizador do sistema separado do `DB_USER` da aplicação.

Na primeira execução interativa, o instalador pergunta se o acesso ao Jin deve ser restringido pelo IP de origem. Premir Enter sem introduzir nada define `IP_RESTRICTION_ENABLED=false` e permite o acesso a partir de qualquer endereço IP. Se a restrição for ativada, introduza endereços IP ou intervalos CIDR separados por vírgulas. Para permitir várias redes locais, utilize por exemplo `192.168.1.0/24, 10.0.0.0/8`. O instalador remove os espaços e acrescenta `127.0.0.1,::1` no início, pois estes endereços são necessários para as verificações de estado e a comunicação interna com o ONLYOFFICE, antes de guardar o resultado em `ALLOWED_IPS` no `.env`.

Em seguida, o instalador apresenta dez idiomas e solicita o idioma predefinido. Selecione japonês, inglês, francês, alemão, chinês simplificado, chinês tradicional, espanhol, português do Brasil, português europeu ou coreano pelo número ou código de idioma. Premir Enter sem selecionar utiliza japonês. O valor escolhido é guardado em `APP_LOCALE` no `.env`.

Sem TTY, as predefinições são acesso IP sem restrições e japonês. Para uma instalação automatizada, forneça as variáveis de ambiente abaixo. Definir apenas `ALLOWED_IPS` também ativa a restrição por IP.

```bash
IP_RESTRICTION_ENABLED=true \
ALLOWED_IPS='192.168.1.0/24, 10.0.0.0/8' \
APP_LOCALE=pt_PT \
./setup.sh --platform ubuntu --domain jin.example.com --app-dir /var/www/jin
```

As execuções seguintes reutilizam `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS` e `APP_LOCALE` do `.env` existente e não repetem estas perguntas.

Verificações após a instalação:

```bash
curl -fsS https://jin.example.com/up
curl -fsS https://jin.example.com/onlyoffice/healthcheck
sudo supervisorctl status
sudo systemctl --no-pager --full status nginx php8.5-fpm postgresql redis-server onlyoffice-documentserver
sudo ss -ltnp
```

Nenhum serviço do Jin ou ONLYOFFICE, além de 80/443, deve escutar em `0.0.0.0` ou `[::]`.

## Pesquisa no conteúdo de ficheiros

Após a extração assíncrona com MarkItDown, Everything pesquisa em `docx`, `xlsx`, `pptx` e `pdf`. Formatos antigos do Office e OpenDocument, ficheiros encriptados ou protegidos por palavra-passe, extrações falhadas e ficheiros demasiado grandes são pesquisados apenas pelo nome. Jin não recebe, guarda nem envia palavras-passe de documentos ao extrator.

```bash
php artisan stored-files:backfill-content
php artisan stored-files:backfill-content --retry-failed
php artisan stored-files:backfill-content --reindex
```

## Limites de segurança

- Os ficheiros permanecem em `storage/app/private` e são fornecidos por rotas de transferência autorizadas.
- A visibilidade de Client é definida por projeto.
- URLs assinados de subscrição de calendário devem ser tratados como palavras-passe.
- Segredos JWT do ONLYOFFICE e URLs assinados completos nunca devem ser registados nem versionados.
- As restrições de IP também abrangem o início de sessão e `/up`.

```dotenv
IP_RESTRICTION_ENABLED=true
ALLOWED_IPS=203.0.113.10,198.51.100.0/24,2001:db8::/32
TRUSTED_PROXIES=10.0.0.0/8
```

Não utilize `*` para proxies de confiança. Execute `php artisan optimize:clear` depois de alterar estes valores.

## Testes e qualidade

```bash
composer ci:check
composer test
npm run lint:check
npm run format:check
npm run types:check
npm run build
```

## Resolução de problemas

- **Todas as páginas devolvem 404:** verifique `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS` e `TRUSTED_PROXIES`.
- **A conversa não aparece de imediato:** verifique Reverb, o worker da fila, Vite, `REVERB_ALLOWED_ORIGINS`, a consola do navegador e os registos do Laravel.
- **Os e-mails não são enviados:** `MAIL_MAILER=log` grava em `storage/logs/laravel.log`; configure um transporte real em produção.
- **As alterações da interface não aparecem:** utilize `npm run dev`, ou execute `npm run build` seguido de `php artisan optimize:clear`.
- **Erro do ONLYOFFICE:** verifique os três URLs de comunicação, o segredo JWT partilhado, o healthcheck e o callback sem registar o JWT completo.

Para todos os procedimentos operacionais detalhados, consulte o [README em japonês](../README.md).
