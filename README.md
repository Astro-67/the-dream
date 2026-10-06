# The Dream

Thedream PMS: a multi-tenant hotel management SaaS for Zanzibar and mainland Tanzania, with Booking.com distribution through a channel manager and Zanzibar Revenue Authority reporting (ZAN Gateway and VFMS).

> This repository is a skeleton. Domain features are built from tickets derived from the [PRD](docs/prd/PRD.md).

## Stack

- **Backend:** Laravel 13 (PHP 8.3), a modular monolith with an Actions layer under `app/Domains/`
- **Database:** MySQL 8, ULID primary keys
- **Auth:** session auth from the Laravel React starter kit (Fortify)
- **Packages:** spatie/laravel-permission (teams mode, team = organization), spatie/laravel-activitylog
- **Queue, cache, sessions:** database drivers
- **Frontend:** Inertia.js 3, React 19, TypeScript, Tailwind CSS 4, shadcn/ui, built with Bun
- **Quality:** Pest 4, Pint, Larastan (level 6)
- **Hosting:** AWS `af-south-1` via Forge, RDS MySQL with point-in-time recovery

## Local setup

Requirements: PHP 8.3+, Composer, Bun, MySQL 8.

```bash
# 1. Database (once)
sudo mysql -e "CREATE DATABASE IF NOT EXISTS the_dream CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
  CREATE USER IF NOT EXISTS 'the_dream'@'localhost' IDENTIFIED BY 'secret'; \
  GRANT ALL PRIVILEGES ON the_dream.* TO 'the_dream'@'localhost';"

# 2. Dependencies
composer install
bun install

# 3. Environment
cp .env.example .env
php artisan key:generate

# 4. Database schema
php artisan migrate

# 5. Run (server, queue worker, logs and Vite)
composer run dev
```

## Checks

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
bun run types:check
bun run build
```

## Documentation

See [`docs/`](docs/):

- [PRD](docs/prd/PRD.md)
- [Architecture](docs/architecture.md)
- [Architecture decision records](docs/adr/)
- [Glossary](docs/GLOSSARY.md)
- [Risk register](docs/risk-register.md)
- [Runbooks](docs/runbooks/README.md)
- [Booking.com and ZRA research](docs/research/booking-com-integration.md)
