# Jin

## Idiomas

[English](README_en.md) | [日本語](../README.md) | [Français](README_fr.md) | [Deutsch](README_de.md) | [简体中文](README_zh_CN.md) | [繁體中文](README_zh_TW.md) | [Español](README_es.md) | [Português (Brasil)](README_pt_BR.md) | [Português (Portugal)](README_pt_PT.md) | [한국어](README_ko.md)

Jin (陣) é um sistema colaborativo auto-hospedado para várias equipes trabalharem por projeto. Ele reúne mensagens, tarefas, agenda, arquivos, chat em tempo real, quadros de cartões e check-ins recorrentes em um único espaço de projeto.

## Principais recursos

- Criar e alternar equipes, convidar integrantes e gerenciar permissões
- Criar, editar, definir datas e escopo e arquivar projetos
- Quadro de mensagens com comentários, assinaturas, itens fixados e anexos
- Texto rico com Trix e sanitização de HTML no servidor
- Listas de tarefas, responsáveis, prazos, subtarefas, comentários, anexos e ordenação por arrastar e soltar
- Agendas por projeto e visões globais diária, semanal, mensal e Gantt
- Upload por arrastar e soltar e downloads autorizados
- Chat de projeto em tempo real com Laravel Reverb
- Quadros de cartões Kanban
- Check-ins recorrentes e histórico de respostas
- Atividades, relatórios, lineup e busca global, inclusive no conteúdo de arquivos compatíveis
- Assinaturas iCalendar somente leitura com URL assinada
- Verificação de e-mail, autenticação de dois fatores e passkeys
- Restrições globais por IPv4, IPv6 e CIDR
- Interface responsiva para desktop e dispositivos móveis

## Tecnologias

| Área           | Tecnologia                                                       |
|----------------|------------------------------------------------------------------|
| Backend        | PHP 8.5 / Laravel 13                                             |
| Frontend       | Inertia.js 3 / Svelte 5 / TypeScript                             |
| Interface      | Tailwind CSS 4                                                   |
| Texto rico     | Trix 2 / Symfony HTML Sanitizer 8                                |
| Tempo real     | Laravel Reverb / Laravel Echo                                    |
| Autenticação   | Laravel Fortify / Passkeys / 2FA                                 |
| Rotas tipadas  | Laravel Wayfinder                                                |
| Filas          | Laravel database queue (desenvolvimento) / Redis (produção)      |
| Banco de dados | SQLite FTS5 (desenvolvimento) / PostgreSQL + PGroonga (produção) |
| Testes         | Pest 4 / PHPStan / Svelte Check / ESLint                         |

## Requisitos

- PHP 8.5 ou posterior e Composer 2
- Node.js 22.12 ou posterior e npm
- Extensão SQLite do PHP para desenvolvimento local e testes comuns
- Python 3.10 ou posterior e MarkItDown para busca no conteúdo dos arquivos
- ImageMagick e Poppler para miniaturas da primeira página de PDFs
- PostgreSQL e PGroonga para busca de conteúdo em produção
- ONLYOFFICE Docs para visualização de documentos Office em modo somente leitura

O desenvolvimento local usa SQLite FTS5. A produção usa PostgreSQL com PGroonga; MySQL não é compatível com a busca de conteúdo de arquivos em produção.

## Instalação local rápida

Em um clone novo:

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

Inicie cada processo em um terminal separado:

```bash
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
php artisan queue:work
php artisan reverb:start
```

Inicie também `php artisan schedule:work` se precisar de avisos de prazo e check-ins recorrentes. Abra <http://localhost:8000>. Depois do seed, a conta exclusiva de desenvolvimento é `test@example.com`, com a senha `password`. Não a utilize em produção.

## macOS e ONLYOFFICE

O caminho compatível com macOS usa Homebrew e Apple [`container`](https://github.com/apple/container), que exige Apple silicon e macOS 26 ou posterior.

```bash
brew install php composer node@22 python imagemagick poppler
container system start
./setup.sh --platform macos --app-dir "$PWD" --no-nginx
```

Ao ativar o ONLYOFFICE, defina um segredo aleatório com pelo menos 32 caracteres e nunca faça commit dele:

```dotenv
ONLYOFFICE_ENABLED=true
ONLYOFFICE_JWT_SECRET=replace-with-at-least-32-random-characters
```

Consulte as [instruções canônicas para macOS](../README.md#macos開発環境) sobre mapeamento DNS de localhost, ciclo de vida do contêiner e recuperação.

## Integração MCP

O servidor MCP local do Jin executa todo o processo com as permissões de um usuário Jin fixo:

```bash
php artisan jin:mcp user@example.com
```

Use somente em um ambiente local confiável e com uma conta dedicada de privilégio mínimo. As permissões de equipe e projeto desse usuário valem para todas as ferramentas MCP.

## Produção no Ubuntu 24.04 / 26.04

`setup.sh` instala Jin, PHP, PostgreSQL/PGroonga, Redis, Node.js, MarkItDown, Nginx, Supervisor, TLS e ONLYOFFICE Docs. O destino compatível é Ubuntu Server amd64 com no mínimo 2 CPUs, 4 GB de RAM, 4 GB de swap e espaço em disco suficiente. Exponha publicamente apenas 80/443 e a porta SSH escolhida.

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

Adicione `--email admin@example.com` para o Let's Encrypt. Use `--no-ssl` somente em ambiente isolado. Quando sudo sem senha estiver configurado intencionalmente, use `--sudo-nopasswd` quando o instalador solicitar. A senha gerada do banco fica em `.env` e `/etc/jin/database-password` (`root:root`, modo `0600`). Mantenha o papel administrativo do PostgreSQL ligado ao usuário do sistema separado do `DB_USER` da aplicação.

Na primeira execução interativa, o instalador pergunta se o acesso ao Jin deve ser restrito pelo IP de origem. Pressionar Enter sem digitar nada define `IP_RESTRICTION_ENABLED=false` e permite acesso de qualquer endereço IP. Se a restrição for ativada, informe endereços IP ou faixas CIDR separados por vírgulas. Para permitir várias redes locais, use por exemplo `192.168.1.0/24, 10.0.0.0/8`. O instalador remove os espaços e acrescenta `127.0.0.1,::1` no início, pois esses endereços são necessários para as verificações de integridade e a comunicação interna com o ONLYOFFICE, antes de salvar o resultado em `ALLOWED_IPS` no `.env`.

Em seguida, o instalador exibe dez idiomas e solicita o idioma padrão. Selecione japonês, inglês, francês, alemão, chinês simplificado, chinês tradicional, espanhol, português do Brasil, português europeu ou coreano pelo número ou código de localidade. Pressionar Enter sem selecionar usa japonês. O valor escolhido é salvo em `APP_LOCALE` no `.env`.

Sem TTY, os padrões são acesso IP irrestrito e japonês. Para uma instalação automatizada, passe as variáveis de ambiente abaixo. Informar apenas `ALLOWED_IPS` também ativa a restrição por IP.

```bash
IP_RESTRICTION_ENABLED=true \
ALLOWED_IPS='192.168.1.0/24, 10.0.0.0/8' \
APP_LOCALE=pt_BR \
./setup.sh --platform ubuntu --domain jin.example.com --app-dir /var/www/jin
```

As execuções posteriores reutilizam `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS` e `APP_LOCALE` do `.env` existente e não repetem essas perguntas.

Verificações após a implantação:

```bash
curl -fsS https://jin.example.com/up
curl -fsS https://jin.example.com/onlyoffice/healthcheck
sudo supervisorctl status
sudo systemctl --no-pager --full status nginx php8.5-fpm postgresql redis-server onlyoffice-documentserver
sudo ss -ltnp
```

Nenhum serviço do Jin ou ONLYOFFICE, além de 80/443, deve escutar em `0.0.0.0` ou `[::]`.

## Busca no conteúdo de arquivos

Após a extração assíncrona com MarkItDown, Everything busca em `docx`, `xlsx`, `pptx` e `pdf`. Formatos antigos do Office e OpenDocument, arquivos criptografados ou protegidos por senha, extrações com falha e arquivos grandes demais são pesquisados apenas pelo nome. Jin não recebe, guarda nem envia senhas de documentos ao extrator.

```bash
php artisan stored-files:backfill-content
php artisan stored-files:backfill-content --retry-failed
php artisan stored-files:backfill-content --reindex
```

## Limites de segurança

- Os arquivos permanecem em `storage/app/private` e são fornecidos por rotas de download autorizadas.
- A visibilidade de Client é definida por projeto.
- URLs assinadas de assinatura de calendário devem ser tratadas como senhas.
- Segredos JWT do ONLYOFFICE e URLs assinadas completas nunca devem ser registrados nem versionados.
- As restrições de IP também cobrem a tela de login e `/up`.

```dotenv
IP_RESTRICTION_ENABLED=true
ALLOWED_IPS=203.0.113.10,198.51.100.0/24,2001:db8::/32
TRUSTED_PROXIES=10.0.0.0/8
```

Não use `*` para proxies confiáveis. Execute `php artisan optimize:clear` após alterar esses valores.

## Testes e qualidade

```bash
composer ci:check
composer test
npm run lint:check
npm run format:check
npm run types:check
npm run build
```

## Solução de problemas

- **Todas as páginas retornam 404:** verifique `IP_RESTRICTION_ENABLED`, `ALLOWED_IPS` e `TRUSTED_PROXIES`.
- **O chat não aparece imediatamente:** verifique Reverb, o worker da fila, Vite, `REVERB_ALLOWED_ORIGINS`, o console do navegador e os logs do Laravel.
- **E-mails não são enviados:** `MAIL_MAILER=log` grava em `storage/logs/laravel.log`; configure um transporte real em produção.
- **Mudanças na interface não aparecem:** use `npm run dev`, ou execute `npm run build` e depois `php artisan optimize:clear`.
- **Erro do ONLYOFFICE:** verifique as três URLs de comunicação, o segredo JWT compartilhado, o healthcheck e o callback sem registrar o JWT completo.

Para todos os procedimentos operacionais detalhados, consulte o [README em japonês](../README.md).
