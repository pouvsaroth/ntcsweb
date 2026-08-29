# NTCSWEB — Multi-Tenant Public School Website & Management Platform

A shared-database, multi-tenant platform for schools: a public website per school (news,
events, programs, gallery) plus an admin panel for managing users, academics, and
communication. One codebase, one database, many schools — isolated from each other by
`tenant_id` at every layer, never by separate databases or schemas.

## Status

This README reflects what is actually built, updated as each phase lands.

| Phase | Area | Status |
|---|---|---|
| 1 | Inspect existing project | ✅ Done |
| 2 | Multi-tenancy foundation | ✅ Done |
| 3 | Authentication & RBAC | ✅ Done |
| 4 | API foundation (envelope, pagination, filtering) | ✅ Done |
| 5 | Academic database schema | 🟡 Teachers, students, classrooms, books, classes, class schedules, enrollments done; academic_years/semesters/programs/subjects/courses not started |
| 6 | Admin API | 🟡 Full CRUD for the Phase 5 entities above, plus homepage slider uploads; Tenants/Roles/Settings admin endpoints not started |
| 7 | Vue 3 frontend scaffold | ✅ Done |
| 8 | Admin panel UI | 🟡 Shell, Users, and Homepage Slider (image upload) pages done; Teachers/Students/Classes/etc. admin screens not built yet (backend API is ready for them) |
| 9 | Public website (Figma) | 🟡 Structure + design tokens + homepage image slider done; exact Figma visuals pending — see [frontend/README section](#frontend-design-system) |
| 10 | Performance optimization | Ongoing (`students` uses cursor pagination specifically for its millions-of-rows target; index/query decisions made per-table as built) |
| 11 | Testing | Ongoing (67 backend tests; no frontend tests yet) |
| 12 | Production/deployment prep | Not started |

See [docs/architecture.md](docs/architecture.md), [docs/multi-tenancy.md](docs/multi-tenancy.md),
[docs/database.md](docs/database.md), and [docs/api.md](docs/api.md) for detail on what exists today.

## Stack

```
Vue 3 + TypeScript (Vite) + Tailwind CSS
        ↓ REST /api/v1
Laravel 13 / PHP 8.3
        ↓
PostgreSQL 18            Redis 7
(shared DB, tenant_id)   (cache · sessions · queues)
```

Served locally via Docker: Nginx → PHP-FPM → Laravel, all behind `http://localhost:8080`.

## Repository layout

```
NTCSWEB/
├── backend/            Laravel 13 API (this is where almost everything below lives)
├── frontend/            Vue 3 + TypeScript SPA (Vite, Tailwind CSS, Pinia, Vue Router)
├── docker/
│   ├── nginx/           default.conf — proxies PHP to php-fpm:9000
│   ├── php/             Dockerfile — PHP 8.3-fpm, pdo_pgsql, redis, bcmath, zip
│   └── postgres/
├── docs/                architecture.md, multi-tenancy.md, database.md, api.md, deployment.md
└── docker-compose.yml   postgres, redis, php, queue, nginx
```

## Getting started

### Prerequisites

Docker Desktop with Docker Compose. Nothing else needs to be installed on the host —
PHP, Composer, and Postgres all run inside containers.

### First-time setup

```bash
cp backend/.env.example backend/.env
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate
docker compose exec php php artisan db:seed
docker compose exec php php artisan storage:link
```

The last step symlinks `public/storage` → `storage/app/public`, which is what makes uploaded
files (currently: homepage slider images) reachable over HTTP at all — skipping it means
every upload succeeds but every image 404s.

The seeder is idempotent — safe to re-run. It syncs the RBAC permission catalog and
ensures every tenant has its four system roles (School Admin, Teacher, Staff, Student),
but it does **not** create a tenant for you. Create the first school with:

```bash
docker compose exec php php artisan tinker
```
```php
$tenant = App\Models\Tenant::create(['name' => 'My School', 'slug' => 'my-school']);
```

Then create its first admin (a *tenant* admin — see below for the platform Super Admin
command):
```php
$user = App\Models\User::create([
    'name' => 'Admin', 'email' => 'admin@my-school.test',
    'password' => Hash::make('change-me'), 'status' => App\Models\User::STATUS_ACTIVE,
]);
$user->forceFill(['tenant_id' => $tenant->id, 'email_verified_at' => now()])->save();
$user->attachRoles(App\Models\Role::where('tenant_id', $tenant->id)->where('slug', 'school-admin')->firstOrFail());
```

A dedicated onboarding endpoint (`POST /api/v1/admin/tenants`) lands in the Admin API
phase — this manual step is temporary.

### Platform Super Admin

There is no HTTP endpoint for this — minting a `tenant_id = NULL` account with the
`super-admin` role is the most powerful action in the system and always requires shell
access:

```bash
docker compose exec php php artisan admin:create-super-admin --name="Platform Admin" --email=you@ntcsweb.com
```

### Running the app

Visit `http://localhost:8080`. `GET /up` is the health check. `GET /api/v1` confirms the
API is reachable.

### Frontend

The Vue 3 + TypeScript SPA lives in `frontend/`, built with Vite and Tailwind CSS. It runs
on the host with Node, not in Docker (the project's Docker services are deliberately kept
to postgres/redis/php/queue/nginx):

```bash
cd frontend
npm install
npm run dev
```

Open the URL Vite prints (**not** the traditional `5173`, see below). The dev server
proxies `/api` and `/sanctum` to the backend at `http://localhost:8080`, so the browser
sees one origin and Sanctum's cookie-based auth works exactly as it will in production
(where nginx does the same proxying for the built assets).

**Port note:** `vite.config.ts` pins the dev server to `5299`, not Vite's usual `5173` —
on this machine, `5173`/`5174` were already held by an unrelated process, and `5180`
failed to bind with `EACCES` for reasons unrelated to this project (a local Hyper-V/WSL
port-reservation quirk). If `5299` isn't free on your machine either, change `server.port`
in `frontend/vite.config.ts` **and** the matching `5299` references in
`backend/.env`/`backend/.env.example` (`APP_FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`,
`CORS_ALLOWED_ORIGINS`) together — they have to agree.

#### Frontend design system

Colors, typography, and spacing in `frontend/src/assets/styles/main.css` are a
**placeholder** professional palette, not the real Figma design — the Figma link
provided couldn't be rendered by any available tool (both a `figma.com/make/...` editor
link and a published `figma.site` link only serve a client-rendered JS shell with no
content in a plain HTTP fetch). Every component references named tokens
(`bg-primary-600`, `text-neutral-700`, ...), never a raw hex value, so matching the real
design is a one-file edit to that `@theme` block once screenshots or a text description
of the design are available — no component changes needed.

#### Languages

The whole SPA (public site, auth, admin) is translated into **English, Khmer (ភាសាខ្មែរ),
Chinese (中文), Korean (한국어), and Japanese (日本語)** via `vue-i18n`. A language switcher
sits in the public header, the admin header, and the login screen. Detection order: a
prior choice (`localStorage`) → the browser's language → English. See
`frontend/src/i18n/locales/en.ts` for the full message schema (every other locale is
type-checked against its exact key structure, so a missing translation is a build error,
not a silent blank string) and `frontend/src/i18n/index.ts` for the detection/persistence
logic. Fonts cover all five scripts in one stack (Inter for Latin, Noto Sans
Khmer/SC/KR/JP as per-glyph fallback) — see the comment in `index.html`.

#### What's real vs. placeholder in the frontend today

- **Real, fully wired**: routing (public + auth + admin, all responsive), the auth store
  and Login/Forgot/Reset-password flow (tested end-to-end against the live backend), the
  reusable UI component library (`src/components/ui`), server-side pagination/search/sort
  (`usePaginatedResource` — never fetches an unpaginated list, by design, for tables meant
  to hold millions of rows).
- **Built against the intended API, gracefully empty until it exists**: every public
  content page (News, Events, Gallery, Programs, Teachers, Contact form) and the admin
  Users page. Each calls the exact endpoint shape Phase 6/9 will add; today they show an
  empty/"coming soon" state instead of fake content, and need zero changes once those
  endpoints ship.
- **Nav-only placeholders**: most admin sections (Academic, Students, Teachers, Academic
  Records, Website Management, Communication) render a generic "not built yet" page —
  see `src/pages/admin/ComingSoon.vue` and `src/router/adminNav.ts`.

### Running tests

```bash
docker compose exec postgres psql -U ntcsuser -d postgres -c "CREATE DATABASE ntcsdb_testing OWNER ntcsuser;"  # once
docker compose exec php php artisan test
```

The suite runs against a **dedicated Postgres database** (`ntcsdb_testing`), not SQLite —
several migrations use Postgres-only DDL (partial unique indexes for per-tenant email
uniqueness) that SQLite can't express. Configured via `phpunit.xml`'s `<php><env>` block,
which only takes effect through `php artisan test` / `vendor/bin/phpunit` — **never** pass
`--env=testing` to a plain `artisan` command expecting it to switch databases; it doesn't
(see [docs/database.md](docs/database.md#never-use---envtesting-outside-phpunit) for why
that's dangerous).

Tenant isolation — the single most important guarantee in this codebase — is proven in
`backend/tests/Feature/Tenancy/TenantIsolationTest.php`.

## Environment variables

See `backend/.env.example` for the full, current list with inline comments. The
multi-tenancy-specific ones:

| Variable | Purpose |
|---|---|
| `TENANCY_ROOT_DOMAIN` | Schools get `{slug}.{this}` for free, no DB row needed |
| `TENANCY_CENTRAL_DOMAINS` | Hostnames that are the platform itself, never a school |
| `TENANCY_ALLOW_REQUEST_RESOLUTION` | Enables the `X-Tenant` header on central domains (local dev without DNS) |
| `SANCTUM_STATEFUL_DOMAINS` | First-party SPA origins that get cookie (not Bearer) auth |
| `CORS_ALLOWED_ORIGINS` | Exact origins allowed cross-origin, `*.{TENANCY_ROOT_DOMAIN}` is covered separately |

## Documentation

- [docs/architecture.md](docs/architecture.md) — request lifecycle, layers, why each exists
- [docs/multi-tenancy.md](docs/multi-tenancy.md) — tenant resolution, isolation, how to add a tenant-owned table
- [docs/database.md](docs/database.md) — schema, indexing decisions, migration gotchas
- [docs/api.md](docs/api.md) — response envelope, pagination, auth flows
- [docs/deployment.md](docs/deployment.md) — current state: dev-only; production checklist
