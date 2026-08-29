# Deployment

**Current state: development only.** Nothing in this document has been executed against a
real production environment — it's the checklist for Phase 12, recorded now so decisions
made along the way (which env vars matter, what's Postgres-specific) aren't lost by the
time that phase starts.

## What today's Docker setup is (and isn't)

`docker-compose.yml` is a **development** stack: bind-mounted source (`./backend:/var/www/html`),
`APP_DEBUG=true`, plaintext secrets in a local `.env`. It is not hardened for production —
no read-only filesystem, no non-root enforcement beyond the base image default, no
resource limits, no TLS termination (that's nginx's job in front of this stack, not
inside it).

### `vendor/` is a named volume, not part of the bind mount — this matters on Windows

On Windows, Docker Desktop's bind-mount file sharing is slow for directories with many
small files — and Composer's `vendor/` (thousands of files across every dependency) is
exactly that shape. The symptom was concrete: every single request took 8–16 seconds of
**wall-clock** time while using around 30ms of actual CPU (`time docker compose exec php ...`
showed this directly — `real` far exceeds `user`+`sys`, the signature of I/O wait, not
computation). `php artisan config:cache` shaved off maybe 25%; it did not fix it, because
the dominant cost was the autoloader reading vendor files themselves, not config parsing.

The fix (already applied): `php`/`queue` mount a named volume at `/var/www/html/vendor`
instead of inheriting it from the `./backend` bind mount, so PHP reads its dependencies
from native Docker storage. This dropped the same request from ~16s to ~0.6s — the entire
backend test suite went from multiple minutes to about 25 seconds.

**Consequence**: the named volume starts empty. `composer install` (already step one of
[first-time setup](../README.md#first-time-setup)) is what populates it — this isn't
optional the way it might look like on a from-scratch Linux/Mac setup, and if the `php`
or `queue` service is ever recreated with `docker compose up --force-recreate` or the
volume is otherwise reset, `composer install` needs to run again before anything works.
`nginx` doesn't get this treatment — it never executes PHP or reads `vendor/`, only
proxies to `php-fpm` and serves `public/`.

## Before production, in no particular order yet

- **Secrets**: move out of `.env` into whatever the target platform provides (Docker
  secrets, a vault, platform env injection). `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`
  must never be committed — confirm `.gitignore` still excludes `.env` once this project
  has an actual git remote.
- **`APP_DEBUG=false`, `APP_ENV=production`** — stack traces must never reach a client;
  confirmed by `bootstrap/app.php`'s exception handler already returning generic messages
  for the exceptions it explicitly maps, but the framework default (uncaught exceptions)
  still needs `APP_DEBUG=false` to stay generic.
- **`config:cache`, `route:cache`, `event:cache`** as part of the deploy step — currently
  a plain `php artisan migrate` in dev, with no build-time caching.
- **Queue workers** — `QUEUE_CONNECTION=redis` is already the default (see
  [docs/database.md](database.md)), but nothing runs `php artisan queue:work` as a
  supervised process yet. `ResetPasswordNotification` is already `ShouldQueue`; it will
  silently never send until a worker exists.
- **`TENANCY_ROOT_DOMAIN` and real DNS** — wildcard DNS (`*.ntcsweb.com`) pointed at the
  load balancer/ingress, so a new tenant's subdomain works the instant its row is created,
  matching `DomainTenantResolver`'s design.
- **`SANCTUM_STATEFUL_DOMAINS` / `CORS_ALLOWED_ORIGINS`** — must list the real production
  SPA origin(s); the wildcard `*.{root domain}` CORS pattern in `config/cors.php` already
  covers every tenant subdomain, only the central/admin origin needs adding explicitly.
- **`SESSION_DOMAIN`** — currently `null` (fine for a single-origin local setup). For
  cookie auth to work across `admin.ntcsweb.com` and `{tenant}.ntcsweb.com` in production,
  this needs to become `.ntcsweb.com` (leading dot) so the cookie is shared across
  subdomains — a custom tenant domain (`school.example.edu.kh`) cannot share that cookie
  at all and must use Bearer-token auth instead, which the API already supports
  transparently (see [docs/api.md](api.md#authentication)).
- **Database backups** — nothing automated exists. `postgres_data` is a named Docker
  volume with no backup policy; before real tenant data exists, a `pg_dump` schedule (or
  the hosting platform's managed-Postgres backup feature) is a hard requirement, not a
  nice-to-have. See the incident recorded in
  [docs/database.md](database.md#migration-gotchas) for exactly why this matters.
- **File storage** — the homepage slider (first upload feature, see
  [docs/database.md#file-storage](database.md#file-storage)) already uses the
  `tenants/{tenant_id}/...` convention and stores a disk-relative path rather than a full
  URL, specifically so this switch is a config change, not a data migration. What's still
  local-only: `FILESYSTEM_DISK=local` / the `public` disk in `config/filesystems.php`.
  Production needs S3-compatible storage (AWS S3 or Cloudflare R2 — `.env.example`
  already has the `AWS_*` keys wired for either) and `php artisan storage:link` replaced
  by that disk actually being public.
- **PHP image**: add `pcntl` to `docker/php/Dockerfile` — required for `queue:work`'s
  graceful restart signal handling, currently absent since no worker runs yet.
- **Health checks / readiness**: `GET /up` exists (Laravel's default) and is a reasonable
  liveness probe; nothing yet checks Postgres/Redis connectivity specifically for
  readiness.

Nothing here is scheduled — this is the list Phase 12 starts from, not a promise about
timing.
