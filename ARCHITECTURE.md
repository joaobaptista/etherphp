# Arquitetura — Ethernity DMs (Laravel 13 + PHP 8.4 + PostgreSQL + React 19 / Vite)

> Monólito organizado orientado a domínio, com banco **PostgreSQL exclusivo** e API REST consumida pelo frontend React 19 na mesma origem. O aplicativo iOS (`ethernityios`) ainda não usa esta API (ver seção 9 de [DOCUMENTACAO.md](DOCUMENTACAO.md)).

## Princípios Arquiteturais

1. **Controllers finos:** Apenas validam (via `FormRequest`), constroem DTO (`spatie/laravel-data`), invocam uma `Action` e retornam um `JsonResource` ou resposta HTTP.
2. **Actions (`app/Actions/<Domain>/*Action.php`):** Um caso de uso por classe, método único `execute()`. Nenhuma Action conhece `Request` ou retorna HTTP.
3. **Models limpos (`app/Models/`):** Apenas `$fillable`, `$hidden`, `$casts` (Enums PHP 8.4) e relacionamentos. Sem camada de Repositories anêmica (Eloquent direto nas Actions).
4. **Contracts & Services (`app/Contracts/` + `app/Services/`):** Abstração para todo I/O externo e criptografia (`JwtServiceContract`, `VaultCryptoServiceContract`, `VaultStorageServiceContract`, `ImageProcessorContract`, `VideoAudioProcessorContract`, `GoogleOAuthServiceContract`, `GooglePhotosPickerContract`, `PaymentGatewayContract`, `RealtimeBroadcasterContract`), registrados em `AppServiceProvider`.
5. **Banco de Dados Exclusivo PostgreSQL:** Apenas o driver `pgsql` permanece configurado (`config/database.php`, `docker/docker-compose.yml` com `postgres:17-alpine`, `phpunit.xml` apontando para `ethernity_test`).
6. **Segurança & Governança:**
   - Autenticação JWT HS256 (`firebase/php-jwt`) com resolução por `uid` imutável e leitura de usuário no banco a cada requisição; `?token=` aceito apenas nas rotas de mídia e `/uploads/*` (`jwt:allow-query`).
   - Policies anti-IDOR (`CapsulePolicy`, `MemoryPolicy`, `MessagePolicy`, `GuardianPolicy`) + checagem de propriedade em `/uploads/*` e na vinculação de memórias/guardiões a cápsulas.
   - Observers (`CapsuleObserver`, `MemoryObserver`) para expurgo definitivo com crypto-shredding em disco.
   - Cofre Zero-Knowledge Fases 1–3 (`user_vault_keys`, textos `enc:v1:`, mídias `CLIENT_CHUNKED_V1` `.cenc`) + compatibilidade byte-a-byte com o modo legado `SINGLE_BLOCK_GCM` / `CHUNKED_AEAD`.
   - Ambiente virgem por padrão (zero dados mockados no banco) e defaults fail-closed em produção.
7. **Frontend React 19 + Vite integrado:** Montado pelo shell Blade `resources/views/app.blade.php` com `laravel-vite-plugin` + `@vitejs/plugin-react`, consumindo `/api` na mesma origem.

## Qualidade & Comandos

```bash
composer lint         # Laravel Pint (formatação PSR-12 / Laravel)
composer types:check  # Larastan / PHPStan nível 7
composer test         # Pint check + PHPStan nível 7 + Pest PHP (PostgreSQL)
npm run test          # Vitest (suíte React)
npm run lint          # Oxlint
npm run build         # Build de produção Vite + Laravel
```

## Hospedagem Compartilhada & Scheduler

- Driver de filas padrão: `database` (PostgreSQL, sem dependência obrigatória de Redis).
- Notificações sincronizadas via HTTP (`/api/notifications`), com `RealtimeBroadcasterContract` pronto para ligar Laravel Reverb em VPS.
- No cPanel / Cron Jobs, cadastrar:
  ```text
  * * * * * php /caminho/para/o/projeto/artisan schedule:run >> /dev/null 2>&1
  ```
