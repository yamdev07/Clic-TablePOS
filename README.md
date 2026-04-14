# Clic&Table — Backend API

> Laravel 11 REST API powering the Clic&Table restaurant management system.  
> Sanctum authentication · Reverb WebSocket · Spatie RBAC · PostgreSQL

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Architecture](#architecture)
- [API Overview](#api-overview)
- [Authentication & Roles](#authentication--roles)
- [WebSocket Broadcasting](#websocket-broadcasting)
- [Testing](#testing)
- [Code Style](#code-style)

---

## Requirements

| Dependency | Version |
|------------|---------|
| PHP | 8.2+ |
| Composer | 2.x |
| PostgreSQL | 15+ (SQLite for local dev) |
| Redis | Optional (queue/cache in prod) |

PHP extensions required: `pdo_pgsql`, `mbstring`, `xml`, `bcmath`, `redis`, `pcntl` (Octane)

---

## Installation

```bash
# 1. Dependencies
composer install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Database
php artisan migrate --seed

# 4. Dev server
php artisan serve
# or via Octane
php artisan octane:start --server=swoole --port=8000

# 5. WebSocket (separate process)
php artisan reverb:start --host=0.0.0.0 --port=8080

# 6. Queue worker (if using database/redis driver)
php artisan queue:work
```

---

## Configuration

Key `.env` variables:

```dotenv
APP_ENV=local
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=clicettable
DB_USERNAME=postgres
DB_PASSWORD=secret

BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database
CACHE_STORE=database

REVERB_APP_ID=clicettable
REVERB_APP_KEY=clicettable-key
REVERB_APP_SECRET=clicettable-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

For local development with SQLite, set `DB_CONNECTION=sqlite` and remove the other `DB_*` vars.

---

## Architecture

```
app/
├── Events/                    # Broadcast events
│   ├── NewOrderReceived.php   # → private-kitchen.{id}
│   ├── OrderStatusChanged.php # → private-restaurant.{id}
│   └── OrderItemStatusChanged.php
├── Http/
│   ├── Controllers/Api/       # 10 resource controllers
│   └── Middleware/
│       └── GzipResponse.php   # Compress payloads > 1 KB
├── Models/                    # 10 Eloquent models
│   ├── Restaurant, User
│   ├── Table, Order, OrderItem
│   ├── Category, MenuItem
│   ├── Payment, ActivityLog
│   └── KitchenDisplay
└── Services/
    └── LogService.php         # Unified activity logging

routes/
├── api.php                    # 40+ REST endpoints
└── channels.php               # Reverb private channel auth
```

### Broadcast resilience

All `broadcast()` calls are wrapped in `tryBroadcast()` to ensure API endpoints never fail when Reverb is unavailable:

```php
private function tryBroadcast(callable $fn): void
{
    try { $fn(); } catch (\Throwable) {}
}
```

### Response compression

`GzipResponse` middleware automatically compresses JSON responses larger than 1 KB with gzip level 6, reducing payload size by 70–80%.

---

## API Overview

Base path: `/api` — all routes require `Authorization: Bearer <token>` except `/login`.

| Domain | Endpoints |
|--------|-----------|
| Auth | `POST /login`, `POST /logout`, `GET /me` |
| Tables | Full CRUD + `PATCH /tables/{id}/status` |
| Menu | `GET /menu` (nested), full CRUD on categories & items, `PATCH .../availability` |
| Orders | Open, add items, send to kitchen, advance status |
| Kitchen | Read pending items, patch `cooking / ready / serve / rupture` |
| Payments | `POST /orders/{id}/payments`, `GET /stats/z-report` |
| Stats | `GET /stats` — cached 15 s via `Cache::remember()` |
| Users | Admin-only CRUD |
| Logs | Admin-only activity log |

Full documentation available via Swagger at `/api/documentation` (requires `l5-swagger` artisan publish).

---

## Authentication & Roles

Authentication is handled by **Laravel Sanctum** (token-based, no cookies for API clients).

Four roles managed by **Spatie Laravel Permission**:

| Role | Scope |
|------|-------|
| `admin` | Full access |
| `manager` | Orders, menu, tables, stats |
| `waiter` | Own tables + order creation |
| `kitchen` | KDS read + status updates only |

### Channel authorisation

```php
// routes/channels.php
Broadcast::channel('restaurant.{id}', fn ($user, $id) =>
    in_array($user->role, ['admin', 'manager', 'waiter'])
    && $user->restaurant_id === (int) $id
);

Broadcast::channel('kitchen.{id}', fn ($user, $id) =>
    in_array($user->role, ['admin', 'kitchen'])
    && $user->restaurant_id === (int) $id
);
```

---

## WebSocket Broadcasting

Three events broadcast in real time:

| Event | Channel | Payload |
|-------|---------|---------|
| `NewOrderReceived` | `private-kitchen.{restaurant_id}` | Full order + items |
| `OrderItemStatusChanged` | `private-kitchen.{restaurant_id}` | Item id, status, order info |
| `OrderStatusChanged` | `private-restaurant.{restaurant_id}` | Order id, old/new status |

---

## Testing

```bash
# Run all tests (parallel)
php artisan test --parallel

# Run specific suite
php artisan test --testsuite=Feature

# With coverage
php artisan test --coverage --min=80
```

The CI pipeline uses a dedicated PostgreSQL 15 service container. A `.env.testing` is generated automatically in CI:

```dotenv
DB_CONNECTION=pgsql
DB_DATABASE=clicettable_test
QUEUE_CONNECTION=sync
CACHE_STORE=array
BROADCAST_CONNECTION=log
```

---

## Code Style

This project uses **Laravel Pint** (PHP CS Fixer preset):

```bash
# Check
./vendor/bin/pint --test

# Fix
./vendor/bin/pint
```

Pint runs as a required check in CI — PRs with style violations are blocked.
