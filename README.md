# Ethernity DMs — Edição PHP / Laravel 13 + React 19 + PostgreSQL

Versão migrada do backend Node.js/Express para **PHP 8.4 + Laravel 13 + PostgreSQL 17**, seguindo arquitetura orientada a domínio (`Actions`, `DTOs Spatie Data`, `Contracts/Services`, `Enums`, `Policies`, `Observers`, `Pest`, `Larastan nível 7`, `Pint`) com frontend **React 19 + Vite** integrado no mesmo repositório, consumindo a API REST JWT na mesma origem.

> O aplicativo iOS (`ethernityios`) usa esta mesma API, em `/api/ios`, com o mesmo endereço do site. Para colocar em produção, veja [IMPLANTACAO.md](IMPLANTACAO.md).

## Setup Rápido

```bash
cp .env.example .env
composer install
php artisan key:generate
docker compose -f docker/docker-compose.yml up -d ethernity-db
php artisan migrate
npm install
npm run build
```

## Desenvolvimento Local

```bash
# Terminal 1: Laravel API + SPA shell (porta 8000)
php artisan serve --port=8000

# Terminal 2: Vite HMR (React 19)
npm run dev
```

Ou stack completa via Docker (`docker/docker-compose.yml` com PHP 8.4-FPM + FFmpeg + GD/Imagick, Nginx, PostgreSQL 17, Mailpit e MinIO):

```bash
docker compose -f docker/docker-compose.yml up -d --build
```

## Testes e Qualidade

```bash
composer test   # Pint + PHPStan (nível 7) + Pest (PostgreSQL ethernity_test)
npm run test    # Vitest (frontend React)
npm run lint    # Oxlint
npm run build   # Build de produção React + Vite
```

Veja [ARCHITECTURE.md](ARCHITECTURE.md) para detalhes de camadas, criptografia e deploy.
