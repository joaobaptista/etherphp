# Ethernity DMs (Edição PHP / Laravel 13 + React 19) — Documentação Técnica e Relatório da Migração

> **Versão:** `v1.0.0` · **Data:** 20/11/2026 · **Revisado:** 21/11/2026  
> **Stack:** PHP 8.4 (mínimo 8.3) + Laravel 13 + PostgreSQL 17 + React 19 + Vite 8 + Tailwind CSS  
> **Referência Arquitetural:** Padrão de monólito organizado orientado a domínio do projeto `no_brakes` (`Actions`, `DTOs Spatie Data`, `Contracts/Services`, `Enums`, `Policies`, `Observers`, `Pest`, `Larastan nível 7`, `Laravel Pint`).  
> **Clientes:** API REST JWT consumida pelo frontend **React 19** (mesma origem). O aplicativo iOS (`ethernityios`) **não** usa esta API hoje (ver seção 9).

---

## 1. Visão Geral da Migração (Node.js/Express → PHP/Laravel)

O backend original em Node.js/Express (`ethernity/backend`) foi reescrito integralmente em **PHP 8.4 + Laravel 13** neste repositório próprio (`ethernityphp`). O projeto Node.js original continua intacto no repositório `ethernity`.

| Dimensão | Antes (Node.js / Express) | Agora (PHP 8.4 / Laravel 13) |
| :--- | :--- | :--- |
| **Runtime / Framework** | Node.js 20+, Express 4 | PHP 8.4 no Docker (`composer.json` aceita 8.3+), Laravel 13 (`13.43`) |
| **Organização Backend** | Rotas + serviços em `backend/src/` | Monólito orientado a domínio: `Actions`, `Data` (DTOs), `Contracts`, `Services`, `Enums`, `Policies`, `Observers`, `FormRequests`, `Resources`, `Controllers` finos |
| **Frontend Web / PWA** | Pasta `frontend/` separada (Vite) | Integrado em `resources/js/` + `resources/views/app.blade.php` via `laravel-vite-plugin` + `@vitejs/plugin-react` (`/api` na mesma origem) |
| **Banco de Dados** | `mysql2` + `initDatabase()` no boot | Migrations oficiais Laravel + Eloquent ORM (**PostgreSQL exclusivo** para produção, dev e testes) |
| **Autenticação** | JWT HS256 (`jsonwebtoken`) | JWT HS256 (`firebase/php-jwt`) via middleware `JwtAuthenticate` (resolução por `uid`, Bearer padrão + `jwt:allow-query` apenas em mídia e `/uploads/*`) |
| **Cofre Criptográfico** | `node:crypto` (`vaultEncryptionService.js`) | `VaultCryptoService` (`openssl` AES-256-GCM + `hash_pbkdf2` + Chunked AEAD 64 KB `EVLT` + passthrough `.cenc` Fase 3) — **compatível byte-a-byte** |
| **Processamento de Mídia** | `sharp` + `@ffmpeg-installer` | `Intervention Image v4` (GD/WebP/300 DPI) + `FfmpegVideoAudioProcessorService` (`symfony/process`, opcional; dispensado no fluxo Zero-Knowledge Fase 3) |
| **Tempo Real** | `socket.io` (processo persistente) | `RealtimeBroadcasterContract` + polling HTTP (`/api/notifications`) — compatível com hospedagem compartilhada e pronto para plugar **Laravel Reverb** |
| **Filas & Agendamento** | Tabela `outbox_events` manual | Laravel Queue (`database` driver) + `routes/console.php` (`schedule:run` via cron) |
| **Qualidade & Testes** | `node --test` | **Laravel Pint** + **Larastan / PHPStan nível 7** + **Pest PHP 4** + **Vitest 4** + **Oxlint** |

---

## 2. Estrutura de Diretórios Criada

```text
ethernityphp/
├── app/
│   ├── Actions/                      # 40 Actions (1 caso de uso por classe, método execute())
│   │   ├── Admin/                    # ListUsers, UpdateUserRole, DeleteUser, BatchDeleteUsers, GetAdminMetrics
│   │   ├── Auth/                     # RegisterUser, LoginUser, HandleGoogleAuthCallback
│   │   ├── Capsules/                 # UpsertCapsule, UpdateCapsule, DeleteCapsule
│   │   ├── Guardians/                # CreateGuardian, UpdateGuardian, DeleteGuardian, UploadGuardianPhoto, DeleteGuardianPhoto
│   │   ├── Memories/                 # CreateMemory, UpdateMemory, DeleteMemory
│   │   ├── Messages/                 # CreateMessage, UpdateMessage, DeleteMessage
│   │   ├── Notifications/            # BuildNotifications
│   │   ├── Profile/                  # UpdateProfile, UploadProfileAvatar
│   │   ├── Subscriptions/            # GetCurrentSubscription, ActivateSubscription, StartCheckout, SyncCheckout,
│   │   │                             # ApplyApprovedPayment, HandleMercadoPagoNotification, CancelAutoRenew, ExpireSubscriptions
│   │   ├── Uploads/                  # StoreUpload
│   │   └── Vault/                    # CreateVaultKeys, RotateVaultKeys, FindAuthorizedMemory,
│   │                                 # UploadClientSealedMedia, UploadServerEncryptedMedia, DeleteVaultMedia
│   ├── Contracts/                    # 9 interfaces de infraestrutura (bindings em AppServiceProvider)
│   ├── Data/                         # 8 DTOs tipados (spatie/laravel-data)
│   ├── Enums/                        # 12 Backed String Enums
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── SpaController.php     # Shell React SPA + entrega protegida de /uploads/*
│   │   │   └── Api/                  # 14 Controllers finos (Auth, Profile, Capsule, Memory, Message,
│   │   │                             # Guardian, Notification, Subscription, Upload, VaultKey,
│   │   │                             # VaultMedia, GooglePhotos, Admin, Health)
│   │   ├── Middleware/               # JwtAuthenticate, EnsureAdmin, SecurityHeaders
│   │   ├── Requests/                 # 12 FormRequests com validação dedicada
│   │   └── Resources/                # 6 JsonResources (contrato JSON consumido pelo frontend React)
│   ├── Models/                       # 17 Models Eloquent limpos (persistência, casts e relações)
│   ├── Observers/                    # CapsuleObserver, MemoryObserver (crypto-shredding em cascata)
│   ├── Policies/                     # CapsulePolicy, MemoryPolicy, MessagePolicy, GuardianPolicy (Anti-IDOR)
│   ├── Providers/AppServiceProvider.php
│   ├── Services/                     # 9 implementações concretas dos Contracts
│   └── Support/RootAdmin.php         # Proteção de administradores raiz
├── bootstrap/app.php                 # Middlewares, exceções JSON para /api/*, rotas web/api/console
├── config/
│   ├── ethernity.php                 # Configuração unificada (JWT, Vault, Google, Mercado Pago, Planos, Segurança)
│   ├── cors.php                      # CORS para clientes em outras origens (expondo Content-Range, Accept-Ranges)
│   └── filesystems.php               # Discos privados `vault` e `uploads`, além de `s3` (MinIO/R2/AWS)
├── database/
│   ├── factories/UserFactory.php
│   ├── migrations/                   # 7 arquivos: 18 tabelas de domínio + 7 de infraestrutura Laravel
│   └── seeders/DatabaseSeeder.php    # Ambiente virgem (zero dados mockados)
├── docker/                           # docker-compose.yml, php.Dockerfile (PHP 8.4 + GD/Imagick + FFmpeg), php.ini, nginx/default.conf, postgres/init-test-db.sql
├── public/                           # index.php, build/ (Vite), models/ (face-api.js), manifest.json, sw.js, ícones PWA
├── resources/
│   ├── views/app.blade.php           # Shell HTML único carregando React 19 via @viteReactRefresh + @vite
│   └── js/                           # Frontend React 19 completo (core/, modules/, styles/, App.jsx, main.jsx)
├── routes/
│   ├── web.php                       # /health, /metrics, /uploads/{path} e catch-all SPA
│   ├── api.php                       # Agregador modular carregando routes/api/*.php
│   ├── console.php                   # Scheduler (expiração horária de sessões Google Photos Picker)
│   └── api/                          # 10 arquivos de rotas por domínio (72 endpoints REST)
├── tests/
│   ├── Pest.php
│   ├── Unit/Services/VaultCryptoServiceTest.php
│   └── Feature/                      # AuthAndProfile, CapsulesMemoriesMessagesGuardians,
│                                     # VaultZeroKnowledgeAndMedia, GooglePhotosSubscriptionsAdmin,
│                                     # SecurityAndRegression, MercadoPagoPayments
├── ARCHITECTURE.md
├── DOCUMENTACAO.md
├── README.md
├── phpstan.neon                      # Larastan nível 7
├── pint.json                         # Preset Laravel
├── composer.json
├── package.json
└── vite.config.js
```

---

## 3. Banco de Dados (Migrations — 25 Tabelas)

As migrations em `database/migrations/` reproduzem integralmente o schema unificado do Ethernity com suporte exclusivo a **PostgreSQL**: 18 tabelas de domínio (as 15 do `database.sql` do Node + `user_vault_keys`, `payment_checkouts` e `subscription_payments`) e 7 de infraestrutura do Laravel (`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`):

1. `0001_01_01_000000_create_users_table.php` — `users` (ID `VARCHAR(64)`, `password_hash` nullable para contas Google, `plan_id`, `is_admin`, `notifications_read_at`, `encryption_public_key`), `password_reset_tokens`, `sessions`.
2. `0001_01_01_000001_create_cache_table.php` — `cache`, `cache_locks`.
3. `0001_01_01_000002_create_jobs_table.php` — `jobs`, `job_batches`, `failed_jobs`.
4. `2026_11_20_000001_create_capsules_and_guardians_tables.php` — `capsules` (`title` TEXT para `enc:v1:`, `recipient` JSON, `metadata` JSON), `capsule_guardians`, `guardians` (com `avatar_url`), `circle_connections`.
5. `2026_11_20_000002_create_memories_messages_and_vault_keys_tables.php` — `memories` (com `encryption_mode` suportando `SINGLE_BLOCK_GCM`, `CHUNKED_AEAD` e `CLIENT_CHUNKED_V1`, `variants_json`, `waveform_peaks`), `messages` (`title` e `recipient_name` TEXT para `enc:v1:`), `user_vault_keys` (Cofre Zero-Knowledge Fase 1).
6. `2026_11_20_000003_create_subscriptions_integrations_and_support_tables.php` — `subscriptions`, `integration_connections`, `google_photos_picker_sessions`, `device_tokens`, `notifications`, `outbox_events`, `drive_folders`, `drive_files`.
7. `2026_11_21_000001_create_payment_checkouts_and_subscription_payments_tables.php` — colunas `gateway_subscription_id`, `gateway_status`, `auto_renew` e `canceled_at` em `subscriptions`; `payment_checkouts` (cada checkout iniciado; o `id` é o `external_reference` enviado ao Mercado Pago) e `subscription_payments` (cada pagamento aprovado; a chave primária é o id do pagamento no Mercado Pago, o que impede aplicar o mesmo pagamento duas vezes).

---

## 4. Camada de Serviços e Contratos (`AppServiceProvider`)

Todos os serviços de infraestrutura estão desacoplados atrás de interfaces em `app/Contracts/`:

| Contract | Implementação | Responsabilidade |
| :--- | :--- | :--- |
| `JwtServiceContract` | `JwtService` | Emissão e verificação JWT HS256 (`sub` = e-mail, `uid` = ID) e tokens `state` anti-CSRF do Google OAuth |
| `VaultCryptoServiceContract` | `VaultCryptoService` | PBKDF2-SHA256 (100k iterações), AES-256-GCM `[IV 12][TAG 16][Ciphertext]` com AAD, Chunked AEAD 64 KB (`EVLT`) com Range HTTP 206, cifragem de credenciais JSON OAuth e crypto-shredding |
| `VaultStorageServiceContract` | `LocalVaultStorageService` | Armazenamento isolado por usuário/mídia no disco `vault` e arquivos privados em `uploads` (pronto para S3/MinIO) |
| `ImageProcessorContract` | `InterventionImageProcessorService` | Geração multi-tier WebP (`master` 4096px Q92, `display` 1920x1080 Q85, `thumb` 400x400 Q80), normalização de avatares e exportação 300 DPI |
| `VideoAudioProcessorContract` | `FfmpegVideoAudioProcessorService` | Transcodificação MP4 H.264+AAC / M4A e extração de pôster via FFmpeg/FFprobe (degradação graciosa se ausente) |
| `GoogleOAuthServiceContract` | `GoogleOAuthService` | URL de login OIDC, troca de `code`, consulta `userinfo`, cache local de avatar e detecção de avatar gerenciado pelo Google |
| `GooglePhotosPickerContract` | `GooglePhotosPickerService` | OAuth com escopo `photospicker.mediaitems.readonly`, tokens cifrados AES-256-GCM, renovação automática de `refresh_token`, sessões Picker, polling, importação e remoção de sessão |
| `PaymentGatewayContract` | `MercadoPagoPaymentGateway` | Cliente REST do Mercado Pago: assinatura recorrente (`/preapproval`), PIX (`/v1/payments`), consulta de pagamentos/cobranças e cancelamento (ver seção 10) |
| `RealtimeBroadcasterContract` | `NullRealtimeBroadcaster` | Ponto de extensão para Laravel Reverb / Echo sem acoplamento nas Actions |

---

## 5. Mapa Completo da API REST (72 Rotas em `/api/*`)

**Documentação interativa (OpenAPI 3.1):** `GET /docs/api` (Swagger UI, para ler e testar as rotas) e `GET /docs/api.json` (especificação, para importar no Postman/Insomnia ou gerar clientes). Os endereços do Node, `/docs` e `/openapi.json`, redirecionam para eles. A especificação é gerada pelo [Scramble](https://scramble.dedoc.co) a partir das rotas, FormRequests, Resources e respostas do código, então cada rota nova entra sozinha. Títulos e descrições ficam nos docblocks dos controllers (`app/Http/Controllers/Api`); parâmetros que o Scramble não deduz são declarados com `#[QueryParameter]`/`#[BodyParameter]`, e uploads com `@requestMediaType multipart/form-data`. A tela é o Swagger UI (`swagger-ui-dist`, empacotado pelo Vite em `resources/js/docs/swagger.js`, sem CDN), com a página em `resources/views/docs/swagger.blade.php`; se você estiver logado no app no mesmo navegador, ela já usa o seu token no "Try it out". Fica aberta só com `APP_ENV=local`; em outro ambiente, só com `API_DOCS_PUBLIC=1`. Para gerar o arquivo: `php artisan scramble:export` (grava `api.json`).

Todas as rotas autenticadas aceitam `Authorization: Bearer <jwt>` (e `?token=<jwt>` em tags `<img>`/`<video>`/`<audio>`). Erros de validação e HTTP retornam `{ "detail": "..." }`, no mesmo formato que o frontend React já esperava do backend Node.js.

- **Saúde e Métricas:** `GET /health`, `GET /metrics`, `GET /api/health`, `GET /api/metrics`.
- **Autenticação & Perfil (`routes/api/auth.php`):**
  - `POST /api/register` (rate limit `register`, senha ≥ 8 chars, plano `infinity`, HTTP 200)
  - `POST /api/login` (rate limit `login`, JWT HS256)
  - `GET /api/auth/me`, `GET /api/auth/google/url`
  - `GET/PUT /api/profile` (bloqueia colisão de e-mail com 409), `POST /api/profile/photo` (rejeita SVG, reencoda WebP)
  - `POST /api/upload` (arquivos servidos em `/uploads/{path}` com `nosniff` + CSP `sandbox`)
- **Cápsulas (`routes/api/capsules.php`):** `GET/POST /api/capsules` (upsert idempotente para sync offline), `GET/PUT/DELETE /api/capsules/{id}` (Policy anti-IDOR + expurgo em cascata com crypto-shredding via `CapsuleObserver`).
- **Memórias (`routes/api/memories.php`):** `GET/POST /api/memories` (recusa `data:`/`blob:` fora do cofre), `GET/PUT/DELETE /api/memories/{id}` (Policy anti-IDOR + `MemoryObserver`).
- **Mensagens / Cartas (`routes/api/messages.php`):** `GET/POST /api/messages`, `GET/PUT/DELETE /api/messages/{id}`, `POST /api/messages/generate-ai`.
- **Guardiões (`routes/api/guardians.php`):** `GET/POST /api/guardians`, `GET /api/guardians/circle/connections`, `GET/PUT/DELETE /api/guardians/{id}`, `POST/DELETE /api/guardians/{id}/photo`.
- **Cofre Zero-Knowledge & Mídia (`routes/api/vault.php`):**
  - **Chaves (Fase 1):** `GET/POST/PUT /api/vault/keys` (`kdf.iterations >= 600000`, validação de tamanhos base64 16/32/60 bytes, prova timing-safe `hash_equals`, rate limit `vault-proof` 10/15min).
  - **Mídias (Fase 3 + Legado):** `POST /api/vault/media/upload` (`clientEncryption=v1` → `CLIENT_CHUNKED_V1` `.cenc`; sem cofre → `SINGLE_BLOCK_GCM` / `CHUNKED_AEAD`), `GET /api/vault/media/{id}/meta`, `GET /api/vault/media/{id}` (`?variant=thumb|display|master`), `GET /api/vault/media/{id}/stream` (HTTP `206 Partial Content`), `/poster`, `/waveform`, `/live-video`, `/export`, `DELETE /api/vault/media/{id}`.
- **Google Login & Google Photos Picker (`routes/api/google_photos.php`):**
  - `GET /api/integrations/google-photos/callback` (multiplexador OAuth: login OIDC exige `email_verified === true`, preserva nome/avatar customizado e devolve JWT no fragmento `#token=`; ou conecta Photos Picker).
  - Rotas protegidas por JWT: `GET /status`, `GET/POST /authorization-url`, `POST /sessions`, `GET /sessions/{sessionId}`, `POST /sessions/{sessionId}/import`, `POST /disconnect`, além das rotas sob `/api/capsules/{id}/google-photos/sessions/*`.
- **Notificações (`routes/api/notifications.php`):** `GET /api/notifications` (derivadas exclusivamente dos registros reais do usuário, com suporte a títulos cifrados `enc:v1:`), `POST /api/notifications/read`.
- **Assinaturas (`routes/api/subscriptions.php`):** `GET /plans` (inclui `paymentsEnabled` e `simulationEnabled`), `GET /current` (com métricas reais de uso, `autoRenew` e expiração), `POST /checkout` (`method=card` → assinatura recorrente com `initPoint`; `method=pix` → QR code PIX de um período), `GET /checkouts/{id}` (consulta e sincroniza com o Mercado Pago), `POST /cancel` (cancela a renovação automática), `POST /webhook` (notificações do Mercado Pago, com validação de assinatura) e `POST /simulate-payment` (bloqueado em `APP_ENV=production`). Detalhes na seção 10.
- **App iOS (`routes/api/ios.php`, POC das fotos do iCloud):** `POST /api/ios/v1/login` (mesmo e-mail e senha do site; token de 30 dias, `IOS_TOKEN_TTL_SECONDS`), `GET /api/ios/v1/health` (quem entrou e quantas fotos esperam cápsula) e `PUT /api/ios/v1/photos/{sha256}.jpg` (corpo = JPEG; 201 nova, 200 repetida, 422 hash diferente, 415 não é JPEG). A foto entra no cofre com `source=ICLOUD`, sem cápsula, e aparece em Memórias → iCloud Photos → "Ver fotos do iPhone", com o mesmo "Adicionar à Cápsula" do Google Fotos. O app só precisa do endereço do site (local, ex. `http://nome-do-mac.local:3333`, ou produção, ex. `https://app.seudominio.com`) e completa `/api/ios`. O `mediaUrl` da resposta já vem completo, com o mesmo host e esquema que o app chamou. Como testar com o iPhone: README do repositório `ethernityios`.
- **Administração (`routes/api/admin.php`, middleware `jwt` + `admin`):** `GET /metrics`, `GET /users`, `PATCH /users/{id}/role`, `DELETE /users/{id}`, `POST /users/batch-delete` (com proteção contra remoção/rebaixamento de administradores raiz e autoexclusão).

---

## 6. Adaptações Realizadas no Frontend React 19 (`resources/js/`)

Todo o frontend React 19 do Ethernity foi copiado para `resources/js/` (e ativos públicos + modelos do `face-api.js` para `public/`) com apenas 6 ajustes cirúrgicos para operar integrado ao monólito Laravel:

1. `resources/views/app.blade.php`: shell SPA carregando `@viteReactRefresh` e `@vite(['resources/js/main.jsx'])`.
2. `vite.config.js`: integração `laravel-vite-plugin` + `@vitejs/plugin-react` com `__APP_VERSION__` e HMR compatível com Docker.
3. `resources/js/core/api/apiUrl.js` e `httpClient.js`: fallback padrão alterado para `/api` (mesma origem).
4. `resources/js/core/hooks/useNetworkStatus.js` e `core/services/googlePhotosService.js`: fallback padrão para mesma origem (`/health` e `''`).
5. `resources/js/core/services/socketService.js`: substituído por shim leve sem dependência de `socket.io-client` (compatível com hospedagem compartilhada).
6. `resources/js/core/appVersion.test.js`: caminho relativo ajustado para `../../../package.json`.

---

## 7. Validação e Qualidade Executada

Resultado dos portões de qualidade na revisão de 21/11/2026:

| Verificação | Comando | Resultado |
| :--- | :--- | :--- |
| **Formatação PHP** | `./vendor/bin/pint --test` | ✅ Passou (0 desvios) |
| **Análise Estática (Nível 7)** | `./vendor/bin/phpstan analyse` | ✅ **0 erros** em `app/`, `bootstrap/app.php`, `config/`, `database/`, `routes/` |
| **Testes Backend (Pest 4)** | `php artisan test` | ✅ **48/48 testes** (**361 assertions**) |
| **Testes Frontend (Vitest 4)** | `npm test` | ✅ **33/33 suítes** (**196/196 testes**) |
| **Lint Frontend** | `npm run lint` | ✅ 0 erros (28 avisos, a maioria variáveis/imports sem uso) |
| **Build de Produção React/Vite** | `npm run build` | ✅ Compila (`public/build/manifest.json`) |
| **Rotas Registradas** | `php artisan route:list --path=api` | ✅ **72 rotas API** ativas |

---

## 8. Execução e Produção

### Setup inicial
```bash
git clone https://github.com/vameri/ethernityphp.git
cd ethernityphp
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
npm install && npm run build
```

### Desenvolvimento local
```bash
# Servidor Laravel (API + SPA)
php artisan serve --port=8000

# HMR React 19 (em outro terminal)
npm run dev
```

### Docker (PHP 8.4-FPM + FFmpeg + Imagick + Nginx + PostgreSQL 17 + Mailpit + MinIO)
```bash
docker compose -f docker/docker-compose.yml up -d --build
```

### Checklist mínimo de produção (VPS ou Hospedagem Compartilhada)
Passo a passo completo de um servidor (pacotes, `.env`, Nginx, HTTPS, cron, backup, conferência e atualização): [IMPLANTACAO.md](IMPLANTACAO.md).

1. Definir `APP_ENV=production`, `APP_DEBUG=false` e `APP_URL=https://app.seudominio.com`. Esse é o único endereço que muda entre local e produção: `FRONTEND_URL`, `CORS_ORIGINS`, o retorno do Google Fotos e o webhook do Mercado Pago usam o `APP_URL` quando ficam vazios. Se o HTTPS terminar num proxy (balanceador, Cloudflare, Traefik), defina `TRUSTED_PROXIES=*` (ou os IPs do proxy); sem isso o Laravel acha que está em `http://` e monta links errados.
2. Definir segredos fortes: `APP_KEY`, `JWT_SECRET` (≥ 64 chars), `VAULT_MASTER_KEY` (≥ 64 chars — nunca alterar após uso; se houver mídias vindas do Node, ver seção 9).
3. Para Google Login / Google Fotos: preencher `GOOGLE_PHOTOS_CLIENT_ID`, `GOOGLE_PHOTOS_CLIENT_SECRET`, `GOOGLE_PHOTOS_REDIRECT_URI` (vazio = `APP_URL` + `/api/integrations/google-photos/callback`) e `GOOGLE_PHOTOS_TOKEN_ENCRYPTION_KEY` (32 bytes base64), e configurar o Google Cloud conforme a seção "Google Fotos em produção" abaixo.
4. Configurar o Cron Job da hospedagem/servidor para o Laravel Scheduler:
   ```text
   * * * * * php /caminho/para/o/projeto/artisan schedule:run >> /dev/null 2>&1
   ```
5. Para cobrar de verdade: configurar o Mercado Pago conforme a seção 10.
6. A documentação da API (`/docs/api`) fica fechada em produção. Para abri-la, use `API_DOCS_PUBLIC=1`; ela mostra todas as rotas, inclusive as de administração.
7. App iOS: no app, em Ajustes → Servidor, coloque só o endereço do site (`https://app.seudominio.com`), igual ao `APP_URL`. O app completa `/api/ios` sozinho. Fora da rede local, ele só aceita HTTPS.


### Google Fotos em produção

A integração usa a **Google Photos Picker API**: o usuário conecta a conta (OAuth), escolhe as fotos na
tela do próprio Google e o servidor importa as escolhidas para o cofre. A conexão é **por usuário**: cada
conta Ethernity guarda os tokens (cifrados) da própria conta Google, e a tela mostra o e-mail que o servidor
tem para aquele usuário.

**Para qualquer pessoa conseguir conectar**, o app OAuth precisa estar **publicado e verificado** pelo Google
(o escopo do Google Fotos exige verificação). Enquanto estiver em modo *Teste*, só os e-mails cadastrados como
usuários de teste (até 100) conseguem conectar; os demais recebem "acesso bloqueado".

1. **Dados legais no `.env` do servidor:** `LEGAL_ENTITY_NAME`, `LEGAL_ENTITY_DOCUMENT` (CPF/CNPJ) e
   `PRIVACY_CONTACT_EMAIL`. Eles aparecem em `/privacidade` e `/termos`; enquanto vazios, as páginas mostram
   "a definir" em destaque. **Revisar o texto das duas páginas com o jurídico** antes de publicar
   (`resources/views/legal/`).
2. **Google Search Console:** verificar a propriedade do domínio de produção.
3. **Google Cloud Console → APIs e serviços → Biblioteca:** ativar a **Google Photos Picker API**.
4. **Tela de consentimento OAuth (Google Auth Platform):**
   - Nome do app, logo e e-mail de suporte;
   - Página inicial: `https://app.seudominio.com/sobre` (página pública que descreve o app sem login);
   - Política de privacidade: `https://app.seudominio.com/privacidade`;
   - Termos de serviço: `https://app.seudominio.com/termos`;
   - Domínio autorizado: `seudominio.com`;
   - Escopos: `openid`, `email`, `profile` e `https://www.googleapis.com/auth/photospicker.mediaitems.readonly`.
5. **Credenciais → client OAuth → URIs de redirecionamento autorizados:** adicionar
   `https://app.seudominio.com/api/integrations/google-photos/callback` (o mesmo endereço serve para o login com
   Google e para conectar o Google Fotos).
6. **Publicar o app** ("Publicar app" / "Em produção") e **enviar para verificação**. O Google costuma pedir uma
   justificativa de uso do escopo e um vídeo mostrando o fluxo (login → conectar Google Fotos → escolher fotos →
   fotos na cápsula). Prazo típico: de alguns dias a algumas semanas.

**Fila de processamento (obrigatória para a importação):** a importação das fotos roda em segundo plano
(`ImportGooglePhotosSessionJob`, driver `database`). O cron do scheduler (item 4 do checklist) já processa a fila a
cada minuto (`ethernity:drain-queue`), o que basta em hospedagem compartilhada. Numa VPS, pode-se manter um
`php artisan queue:work --timeout=1800` sob supervisor. Em desenvolvimento local, rode `php artisan queue:work` em
um terminal separado, ou a importação fica parada em "Importando…".

Comportamento da importação: o seletor do Google limita a escolha a `GOOGLE_PHOTOS_MAX_ITEMS_PER_SESSION` itens
(padrão 50); todas as páginas de itens escolhidos são importadas; **apenas fotos** são importadas (vídeos escolhidos
são contados e o usuário é avisado); repetir a importação não duplica fotos; se o usuário remover o acesso na conta
Google, o app passa a mostrar o Google Fotos como desconectado e pede para conectar de novo.

O que o app já atende para a verificação: acesso só às fotos escolhidas no seletor (Picker API, sem acesso à
biblioteca inteira), tokens e mídias guardados cifrados, revogação no Google ao desconectar, página inicial
pública (`/sobre`), links para "Sobre", política e termos na tela de login e no card do Google Fotos, e a
declaração de Uso Limitado na política.

No servidor: as variáveis do item 3 do checklist acima e o cron do scheduler (item 4), que expira as
sessões de seleção abandonadas. Teste final: conectar com uma conta, abrir o seletor, escolher 1 foto e
conferir que ela aparece importada na cápsula.

---

## 9. Compatibilidade com o Backend Node.js e Limitações Conhecidas

### Dados vindos do Node.js

- **Formato criptográfico verificado:** mídias cifradas pelo `vaultEncryptionService.js` do Node (bloco único AES-GCM e Chunked AEAD com leitura por faixa) e credenciais do Google cifradas pelo Node são decifradas pelo PHP. Teste feito cifrando no Node e decifrando no PHP.
- **Chave do cofre:** o `VAULT_MASTER_KEY` precisa ser o valor que o Node **efetivamente** usava: o `VAULT_MASTER_KEY` do Node, se existia, ou então o **`JWT_SECRET` do Node** (era o valor de reserva). Com outra chave, as mídias antigas respondem HTTP 500 e não há como recuperá-las. O `GOOGLE_PHOTOS_TOKEN_ENCRYPTION_KEY` também deve ser o mesmo do Node.
- **Senhas:** hashes `bcryptjs` (`$2a$` / `$2b$`) são aceitos no login e regravados no formato do PHP (`$2y$`) no primeiro acesso.
- **Tokens JWT antigos** (só com `sub` = e-mail) continuam válidos se o `JWT_SECRET` for o mesmo.
- **Arquivos:** o layout em disco é o mesmo do Node (`vault/<userId>/<mediaId>/…` e `uploads/…`), em `storage/app/vault` e `storage/app/uploads`.
- **Migração realizada:** os dados de desenvolvimento do MySQL do Node foram importados para o PostgreSQL em 21/11/2026 (datas convertidas de `America/Sao_Paulo` para UTC; cápsulas com `status`/`delivery_type` fora dos Enums normalizadas para `LOCKED`/`date`). O importador foi removido em seguida e o projeto não tem mais nenhuma dependência de MySQL.

### Limitações conhecidas

- **Limites dos planos não são aplicados no backend:** `maxCapsules`, `maxGuardians` e `storageGb` são informados ao frontend, mas nenhuma rota recusa criar cápsulas, guardiões ou mídias além do limite do plano.
- **Rotas do Node não migradas:** as rotas antigas `/google-photos/*` (`auth-url`, `userinfo`, `live-photos`, `status`, `connect`, `session`, `photos`, `import`), `POST /reset` e o alias `/api/uploads`. O frontend não usa nenhuma delas; a integração atual fica em `/api/integrations/google-photos/*`. As antigas `POST /subscriptions/create-preference` e `POST /subscriptions/pay-card` foram removidas (esta última recebia o número do cartão no servidor).

---

## 10. Pagamentos (Mercado Pago)

### Como funciona

| Forma | O que o usuário faz | Renovação | Endpoint da API Mercado Pago |
| :--- | :--- | :--- | :--- |
| **Cartão** | É levado à página do Mercado Pago (`initPoint`), cadastra o cartão lá e volta ao app (`?checkout=<id>`) | **Automática**, mensal ou a cada 12 meses | `POST /preapproval` (assinatura sem plano, `status: pending`) |
| **PIX** | Paga o QR code/copia e cola exibido no modal; o app confirma sozinho | Nenhuma: paga **um período** | `POST /v1/payments` (`payment_method_id: pix`, `X-Idempotency-Key`) |

- **O cartão nunca passa pelo servidor do Ethernity** (fica na página do Mercado Pago), então não há dado de cartão a proteger aqui.
- **O preço sempre vem de `config/ethernity.php`**, nunca do navegador.
- **O plano só é liberado por um pagamento aprovado lido da API do Mercado Pago.** O corpo do webhook serve só para saber *qual* recurso mudou: o servidor busca o pagamento (`/v1/payments/{id}`), a cobrança recorrente (`/authorized_payments/{id}`) ou a assinatura (`/preapproval/{id}`) com o access token e confere o valor. Um webhook forjado, no máximo, provoca uma consulta.
- **Cada pagamento é aplicado uma vez só** (`subscription_payments`, chave = id do pagamento), mesmo que chegue pelo webhook `payment`, pelo `subscription_authorized_payment` e pela consulta do PIX.
- **Renovação estende a partir do fim do período atual**, não de "hoje".
- **Cancelar** (`POST /api/subscriptions/cancel` → `PUT /preapproval/{id}` com `status: "canceled"`) interrompe as cobranças; o plano fica ativo até o fim do período pago.
- **Expiração**: o agendador (`ethernity:expire-subscriptions`, de hora em hora) volta o usuário ao plano gratuito quando o período pago acaba. Assinaturas com renovação automática ganham `MERCADO_PAGO_RENEWAL_GRACE_DAYS` (padrão 10) dias extras, porque o Mercado Pago tenta de novo uma cobrança recusada antes de cancelar.
- Para evitar cobrança dupla, não é possível iniciar outro checkout enquanto houver renovação automática ativa: o usuário cancela a renovação e depois troca de plano.

### Configuração

```dotenv
MERCADO_PAGO_ACCESS_TOKEN=APP_USR-...      # vazio = pagamentos desligados (a API responde 503)
MERCADO_PAGO_WEBHOOK_SECRET=...            # "assinatura secreta" do painel; vazio = assinatura não é exigida
MERCADO_PAGO_NOTIFICATION_URL=https://app.seudominio.com/api/subscriptions/webhook   # padrão: APP_URL + /api/subscriptions/webhook
MERCADO_PAGO_BACK_URL=https://app.seudominio.com/                                     # padrão: FRONTEND_URL
MERCADO_PAGO_PIX_EXPIRATION_MINUTES=30
MERCADO_PAGO_RENEWAL_GRACE_DAYS=10
```

No painel do Mercado Pago (**Suas integrações → Webhooks**), cadastre a URL
`https://app.seudominio.com/api/subscriptions/webhook` com os eventos **Pagamentos**,
**Planos e assinaturas** (`subscription_preapproval`) e **Pagamentos recorrentes**
(`subscription_authorized_payment`), e copie a assinatura secreta para
`MERCADO_PAGO_WEBHOOK_SECRET`. O PIX também envia `notification_url` em cada cobrança
(só quando a URL é `https://`).

### Validar no sandbox antes de cobrar de verdade

A documentação do Mercado Pago é contraditória ou omissa em alguns pontos, então eles
**precisam ser conferidos com credenciais de teste** antes de liberar em produção:

1. **Webhooks de assinatura chegam e passam na validação.** A documentação diz que
   assinaturas não são configuradas pelo painel e não deixa claro se esses avisos vêm
   assinados com a mesma chave. Se o log mostrar `Rejected Mercado Pago webhook with
   invalid signature` para `subscription_*`, ajuste antes de seguir.
2. **O `data.id` do evento `subscription_preapproval` é o id da assinatura** (o código
   assume isso; a documentação não diz).
3. **O e-mail do pagador:** a assinatura é criada com o e-mail da conta Ethernity
   (`payer_email`). Confirme o comportamento quando o usuário paga com uma conta Mercado
   Pago de outro e-mail.
4. **A primeira cobrança do cartão** acontece cerca de 1 hora depois da assinatura,
   segundo a documentação. Até lá o checkout fica `pending` e o app avisa que o plano será
   liberado após a confirmação.
5. **O PIX aprovado ativa o plano** pelo webhook e também pela consulta do modal.

Os testes automatizados (`tests/Feature/MercadoPagoPaymentsTest.php`) simulam a API do
Mercado Pago e cobrem: corpo das requisições, PIX reaproveitado, assinatura do webhook
(mesmo algoritmo dos SDKs oficiais), pagamento aplicado uma vez, valor abaixo do preço
recusado, renovação, cancelamento dos dois lados, consulta do PIX, retentativa (503) e
expiração.
