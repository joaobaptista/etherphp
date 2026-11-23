# Implantação em produção — Ethernity

Passo a passo para colocar o Ethernity (site, API e o que o app iOS usa) num servidor Linux (VPS). Os exemplos usam Ubuntu 24.04, Nginx e PHP-FPM. Em outras distribuições, os nomes dos pacotes mudam, mas os passos são os mesmos.

Onde aparece `app.seudominio.com`, use o domínio de produção.

---

## 1. Como funciona

Tudo fica num endereço só. O site (React), a API (`/api`) e as rotas do app iOS (`/api/ios`) são servidos pelo mesmo Laravel, no mesmo domínio:

```text
https://app.seudominio.com           → site
https://app.seudominio.com/api/...   → API do site
https://app.seudominio.com/api/ios   → app iOS
```

Por isso, **o único endereço a configurar é o `APP_URL`**. O retorno do Google, o webhook do Mercado Pago, o CORS e os links das fotos saem dele. O app iOS recebe o mesmo endereço em Ajustes → Servidor.

| Peça | Para quê |
| --- | --- |
| Nginx + PHP-FPM 8.4 | Site e API |
| PostgreSQL 17 | Banco de dados, fila e cache |
| Cron (a cada minuto) | Tarefas agendadas e fila |
| `storage/app/vault` e `storage/app/uploads` | Fotos e arquivos (criptografados no cofre) |
| Certificado HTTPS | Obrigatório para o app iOS, o Google e o Mercado Pago |

## 2. Antes de começar: domínio e HTTPS

Sem domínio com HTTPS, não funcionam:

- **App iOS** fora da rede local: o iOS só aceita HTTPS na internet.
- **Login com Google e Google Fotos**: o Google exige domínio verificado e endereço de retorno em HTTPS.
- **Webhook do Mercado Pago**: só é enviado a um endereço HTTPS público.

Dá para subir o servidor e testar o site pelo IP antes de ter domínio. Quando o domínio existir:

1. Crie um registro DNS `A` (e `AAAA`, se houver IPv6) de `app.seudominio.com` para o IP do servidor.
2. Siga a seção 6 para o HTTPS.
3. Troque o `APP_URL` e rode `php artisan config:cache` (seção 5).

## 3. Pacotes do servidor

```bash
sudo apt update
sudo apt install -y nginx postgresql-17 git unzip curl ffmpeg imagemagick libheif1 \
  php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip \
  php8.4-bcmath php8.4-intl php8.4-gd php8.4-imagick
# Composer: https://getcomposer.org/download/
# Node.js 20 ou mais novo, só para compilar o site (npm run build)
```

- `ffmpeg` processa vídeos e áudios.
- `imagemagick`, `libheif1` e `php8.4-imagick` abrem fotos HEIC do iPhone. Sem eles, o PHP usa o GD, que não lê HEIC.
- PHP 8.3 também roda, mas o projeto é testado no 8.4.

Se o Ubuntu não tiver o PHP 8.4 ou o PostgreSQL 17, adicione os repositórios `ppa:ondrej/php` e `apt.postgresql.org`.

### Limites do PHP

Fotos de 48 MP e vídeos passam fácil de 8 MB, o limite padrão do PHP. Copie os valores de [docker/php.ini](docker/php.ini) para `/etc/php/8.4/fpm/conf.d/99-ethernity.ini`:

```ini
upload_max_filesize = 150M
post_max_size       = 160M
max_file_uploads    = 20
max_execution_time  = 300
max_input_time      = 300
memory_limit        = 512M
```

Depois: `sudo systemctl restart php8.4-fpm`.

## 4. Banco de dados

```bash
sudo -u postgres psql <<'SQL'
CREATE USER ethernity WITH PASSWORD 'troque-esta-senha';
CREATE DATABASE ethernity OWNER ethernity;
SQL
```

O PostgreSQL deve ouvir só em `localhost`, que é o padrão. Não abra a porta 5432 para a internet.

## 5. Código e `.env`

```bash
sudo mkdir -p /var/www/ethernity && sudo chown "$USER":www-data /var/www/ethernity
git clone https://github.com/vameri/ethernityphp.git /var/www/ethernity
cd /var/www/ethernity
cp .env.example .env
```

Edite o `.env`. Estas são as linhas que mudam em produção. As outras podem ficar como no exemplo.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.seudominio.com

LOG_LEVEL=warning

DB_HOST=127.0.0.1
DB_DATABASE=ethernity
DB_USERNAME=ethernity
DB_PASSWORD=troque-esta-senha

# Vazios: usam o APP_URL
FRONTEND_URL=
CORS_ORIGINS=
# Só se o HTTPS terminar num proxy (seção 6.2). Acesso direto: deixe vazio
TRUSTED_PROXIES=

# Segredos (como gerar: logo abaixo)
JWT_SECRET=
VAULT_MASTER_KEY=

# Limite de tentativas (login, envios). O exemplo vem com 0, que desliga
RATE_LIMIT_ENABLED=1
SESSION_SECURE_COOKIE=true

# E-mails extras de administrador, separados por vírgula (opcional)
ADMIN_EMAILS=
```

### Segredos

```bash
php artisan key:generate                              # APP_KEY
openssl rand -base64 48                               # JWT_SECRET (64 caracteres)
openssl rand -base64 48                               # VAULT_MASTER_KEY (64 caracteres)
php -r 'echo base64_encode(random_bytes(32)), "\n";'  # GOOGLE_PHOTOS_TOKEN_ENCRYPTION_KEY
```

> **`VAULT_MASTER_KEY` nunca pode mudar depois que houver fotos.** Ela decifra o cofre. Com outra chave, as fotos respondem erro 500 e não há como recuperá-las. Guarde uma cópia dela fora do servidor, num cofre de senhas. Se os dados vieram do backend Node.js, use a mesma chave de lá (seção 9 de [DOCUMENTACAO.md](DOCUMENTACAO.md)).

Trocar o `JWT_SECRET` só desconecta todo mundo, inclusive o app iOS. Ninguém perde dados.

O que fica desligado sozinho com `APP_ENV=production`:

- o login de demonstração;
- a simulação de pagamento;
- a documentação da API (`/docs/api`).

Para abrir algum deles de propósito, use `ENABLE_DEMO_LOGIN=1`, `ENABLE_PAYMENT_SIMULATION=1` ou `API_DOCS_PUBLIC=1`.

### Instalar e compilar

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build              # lê o VITE_GOOGLE_CLIENT_ID do .env: rode depois de preenchê-lo
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

sudo chown -R "$USER":www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

> Com `config:cache`, o Laravel não lê mais o `.env` a cada requisição. **Depois de mudar o `.env`, rode `php artisan config:cache` de novo.**

## 6. Nginx e HTTPS

### 6.1 HTTPS no próprio servidor (mais simples)

Crie `/etc/nginx/sites-available/ethernity` só com a porta 80. O certbot acrescenta o HTTPS e o redirecionamento depois.

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name app.seudominio.com;
    root /var/www/ethernity/public;
    index index.php;
    charset utf-8;

    client_max_body_size 160m;

    location = /sw.js         { add_header Cache-Control "no-cache"; }
    location = /manifest.json { add_header Cache-Control "no-cache"; }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_buffering off;
        fastcgi_read_timeout 600s;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/ethernity /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

# Com o DNS já apontando para o servidor:
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d app.seudominio.com --redirect
```

O certbot cria o bloco 443, manda todo HTTP para HTTPS e renova o certificado sozinho. Para conferir: `sudo certbot renew --dry-run`.

- `client_max_body_size 160m` precisa bater com o `post_max_size` do PHP. Abaixo disso, fotos grandes voltam com erro 413.
- Mantenha `fastcgi_buffering off`: os vídeos do cofre são entregues em partes.

### 6.2 HTTPS num proxy na frente (Cloudflare, balanceador, Traefik)

Se o HTTPS termina antes do servidor, o Laravel recebe HTTP puro e monta links com `http://`. Para ele confiar no aviso do proxy (`X-Forwarded-Proto`), defina:

```dotenv
TRUSTED_PROXIES=*                  # ou só os IPs/faixas do proxy, separados por vírgula
```

Depois, `php artisan config:cache`.

- Use `*` só se o servidor **não** puder ser acessado direto pela internet, só pelo proxy. Sem isso, qualquer um pode fingir ser o proxy. Com acesso direto aberto, liste os IPs do proxy.
- **Cloudflare (plano grátis ou Pro):** o limite de envio é de 100 MB por requisição. Arquivos maiores são recusados pela própria Cloudflare. Fotos de iPhone ficam bem abaixo disso, mas vídeos longos não.

## 7. Cron e fila

Uma linha no cron do usuário do PHP-FPM (`www-data`) faz tudo. Abra o cron com `sudo crontab -u www-data -e`:

```text
* * * * * cd /var/www/ethernity && php artisan schedule:run >> /dev/null 2>&1
```

Ele roda como `www-data` para que os arquivos que a fila grava no cofre tenham o mesmo dono dos que o site grava.

Ela roda a cada minuto:

- a fila, que inclui a importação do Google Fotos;
- a expiração das sessões do seletor do Google;
- a expiração dos planos vencidos e dos PIX não pagos.

Sem o cron, a importação fica parada em "Importando…" e os planos não vencem. Para conferir: `php artisan schedule:list`.

### Opcional: fila sempre ligada

Com o worker sempre ligado, a importação começa na hora, sem esperar o minuto seguinte. Arquivo `/etc/supervisor/conf.d/ethernity-queue.conf`:

```ini
[program:ethernity-queue]
command=php /var/www/ethernity/artisan queue:work --timeout=1800 --tries=1
user=www-data
autostart=true
autorestart=true
stopwaitsecs=1800
stdout_logfile=/var/www/ethernity/storage/logs/queue.log
```

```bash
sudo apt install -y supervisor
sudo supervisorctl reread && sudo supervisorctl update
```

O cron continua necessário. Ele só encontra a fila vazia.

## 8. Integrações

| Integração | O que configurar | Detalhes |
| --- | --- | --- |
| Google (login e Google Fotos) | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_PHOTOS_TOKEN_ENCRYPTION_KEY`, `VITE_GOOGLE_CLIENT_ID` (rode `npm run build` depois). No Google Cloud, o retorno é `https://app.seudominio.com/api/integrations/google-photos/callback` | [DOCUMENTACAO.md](DOCUMENTACAO.md), "Google Fotos em produção" |
| Páginas legais | `LEGAL_ENTITY_NAME`, `LEGAL_ENTITY_DOCUMENT`, `PRIVACY_CONTACT_EMAIL`, exibidos em `/privacidade` e `/termos` | Mesma seção |
| Mercado Pago | `MERCADO_PAGO_ACCESS_TOKEN`, `MERCADO_PAGO_PUBLIC_KEY`, `MERCADO_PAGO_WEBHOOK_SECRET`. O webhook é `https://app.seudominio.com/api/subscriptions/webhook` | [DOCUMENTACAO.md](DOCUMENTACAO.md), seção 10 |
| App iOS | Nada no servidor além do HTTPS. No app: Ajustes → Servidor = `https://app.seudominio.com` (o app completa `/api/ios`) | Seção 8.1 |

### 8.1 App iOS

- **No servidor:** o app usa `https://app.seudominio.com/api/ios/v1/...`, as mesmas rotas do ambiente local. Não há nada a ligar. O token do app dura 30 dias (`IOS_TOKEN_TTL_SECONDS`).
- **No iPhone:** Ajustes → Servidor → `https://app.seudominio.com`. Depois, entre com o e-mail e a senha do site.
- **Endereço já preenchido no app:** no build Release do `ethernityios`, defina a build setting `ETHERNITY_SERVER_URL`:

  ```bash
  xcodebuild -configuration Release ETHERNITY_SERVER_URL=https://app.seudominio.com ...
  ```

  Ou defina no Xcode: target EthernityPhotos → Build Settings.

## 9. Backup

O backup precisa de três partes. Sem qualquer uma delas, as outras não servem:

1. **Banco:** `pg_dump -Fc ethernity > ethernity-$(date +%F).dump`
2. **Arquivos:** `storage/app/vault` e `storage/app/uploads`.
3. **`.env`**, principalmente a `VAULT_MASTER_KEY`, guardado à parte (veja a seção 5).

Exemplo diário, no cron do usuário `postgres`:

```text
30 3 * * * pg_dump -Fc ethernity > /var/backups/ethernity/db-$(date +\%F).dump
```

Copie os backups para fora do servidor (outro provedor ou armazenamento S3) e teste a restauração de vez em quando.

## 10. Conferir depois de subir

```bash
curl -s https://app.seudominio.com/api/health      # {"status":"healthy","database":"connected",...}
curl -sI https://app.seudominio.com/ | grep -i strict-transport-security
curl -s -o /dev/null -w '%{http_code}\n' https://app.seudominio.com/docs/api   # 403 (fechada)
php artisan schedule:list
php artisan about --only=environment               # Environment: production, Debug Mode: OFF
```

Depois, pelo navegador e pelo iPhone:

- [ ] Criar uma conta e entrar no site.
- [ ] Enviar uma foto para uma cápsula e abri-la.
- [ ] No app iOS: Ajustes → Servidor → `https://app.seudominio.com`, entrar e tocar em **Testar conexão**. Deve aparecer "Conectado como …".
- [ ] Enviar uma foto pelo app e conferir que ela aparece no site, em Memórias → iCloud Photos → Ver fotos do iPhone.
- [ ] Se usar o Google: conectar o Google Fotos, escolher 1 foto e ver a importação terminar.
- [ ] Se usar o Mercado Pago: um pagamento de teste (seção 10 de [DOCUMENTACAO.md](DOCUMENTACAO.md)).

## 11. Atualizar uma versão

```bash
cd /var/www/ethernity
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart          # o worker passa a usar o código novo
sudo systemctl reload php8.4-fpm
php artisan up
```

## 12. E o Docker?

O [docker/docker-compose.yml](docker/docker-compose.yml) é para desenvolvimento. Ele:

- monta o código do computador dentro dos contêineres;
- usa senhas fixas no banco e no MinIO;
- sobe o Mailpit;
- expõe as portas 5173, 1025, 8025, 9000 e 9001.

Para usar contêineres em produção, aproveite o [docker/php.Dockerfile](docker/php.Dockerfile), que já tem as extensões, o FFmpeg e o ImageMagick. Monte um compose próprio:

- sem montar o código (copie-o na imagem e rode `composer install --no-dev` e `npm run build` no build);
- com senhas vindas do `.env`;
- sem Mailpit e sem MinIO, se não forem usados;
- com volumes para `storage/` e para o banco;
- com HTTPS num proxy na frente (Traefik, Caddy ou Nginx) e `TRUSTED_PROXIES` definido.

## 13. Problemas comuns

| Sintoma | Causa e solução |
| --- | --- |
| Envio de foto grande dá **413** | `client_max_body_size` (Nginx) ou `post_max_size` (PHP) baixos. Veja as seções 3 e 6. Atrás da Cloudflare grátis, o limite é 100 MB. |
| Links e fotos com `http://` em vez de `https://` | HTTPS num proxy sem `TRUSTED_PROXIES` (seção 6.2), ou `APP_URL` com `http://`. Rode `php artisan config:cache` depois de corrigir. |
| Mudei o `.env` e nada mudou | Falta `php artisan config:cache`. |
| Fotos antigas dão **500** | A `VAULT_MASTER_KEY` não é a mesma de quando as fotos foram guardadas. |
| App iOS diz "… não é da rede local: use https://" | O endereço em Ajustes está com `http://`. Use `https://app.seudominio.com`. |
| App iOS diz "O servidor mudou: entre de novo na conta" | O endereço foi trocado (por exemplo, do Mac para a produção). Entre de novo em Ajustes. |
| App iOS dá **401** depois de um tempo | O token venceu (30 dias) ou o `JWT_SECRET` mudou. Entre de novo. |
| Google diz `redirect_uri_mismatch` | O endereço de retorno no Google Cloud não é `APP_URL` + `/api/integrations/google-photos/callback`. |
| Importação do Google parada em "Importando…" | O cron (seção 7) não está rodando. |
| Erros em geral | `storage/logs/laravel.log`, `/var/log/nginx/error.log` e `journalctl -u php8.4-fpm`. |
