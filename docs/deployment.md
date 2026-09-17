# Deployment

**Current state: live in production.** `.github/workflows/deploy.yml` deploys to a real
server on every push to `main` — confirmed working 2026-09-17 (backend tests, frontend
build, then an SSH deploy that migrated, provisioned tenant databases, reseeded, and
restarted the stack). The rest of this document previously said "development only" and
described a Phase 12 checklist that hadn't started; that was wrong by the time it was
last read — `docker-compose.prod.yml` and `docker/nginx/prod.conf` already exist and are
what the pipeline deploys. What follows is what's actually true of that setup, verified
against those files, plus what's still genuinely unverified (this machine has no access
to the production server itself or its real `backend/.env`, which is server-managed and
never committed — see `backend/.gitignore`).

## How a deploy actually happens

Push to `main` (or `workflow_dispatch`) →
1. **`backend-tests`** — full `php artisan test` against a fresh Postgres 18 service
   container.
2. **`frontend-build`** — `vue-tsc --noEmit` then `npm run build`, uploaded as an artifact.
3. **`deploy`** (only if both above pass) — `rsync`s the frontend build to the server,
   then over SSH: `git reset --hard origin/main`, `docker compose -f
   docker-compose.prod.yml up -d --build`, `composer install --no-dev`, `artisan migrate
   --force`, `artisan tenants:provision-all-databases`, four seeders
   (`RolePermissionSeeder`, `ChartOfAccountsSeeder`, `LanguageSeeder`, `BaseDataSeeder`),
   `config:clear`, then restarts `queue` and `nginx`.

The SSH step has failed transiently at least once with `kex_exchange_identification:
read: Connection reset by peer` and succeeded on a plain re-run — treat one failure there
as possibly transient, not necessarily a real break, before troubleshooting further.

## What `docker-compose.prod.yml` / `docker/nginx/prod.conf` already do

- **TLS/domain**: not handled inside this stack at all — `nginx` binds only to
  `127.0.0.1:8095`; the **host's own Apache** is the real public front door (TLS
  termination + the `newtechkh.com` vhost) and reverse-proxies into that port. So "add
  TLS" isn't a Docker-stack task here, it's an Apache-vhost task on the host.
- **`postgres`/`redis`**: no host port mapping at all — reachable only from other
  containers on the `ntcsweb` network, never from the internet (unlike the dev compose
  file, which exposes `5433`/`6380` for a local client).
- **Queue worker**: `queue` runs `php artisan queue:work redis --sleep=3 --tries=3
  --timeout=600` with `restart: unless-stopped` — it is supervised (by Docker's own
  restart policy, not a separate process supervisor like systemd/supervisord, but
  supervised nonetheless). The earlier claim that "nothing runs `queue:work` yet" was
  wrong.
- **Single-origin serving**: one `nginx` serves the built SPA as static files *and*
  proxies `/api`, `/sanctum`, `/up` to `php-fpm` — matching `APP_FRONTEND_URL`-less,
  same-origin cookie auth in production, not a separate frontend host.

## What today's Docker setup is (and isn't)

`docker-compose.yml` is a **development** stack: bind-mounted source (`./backend:/var/www/html`),
`APP_DEBUG=true`, plaintext secrets in a local `.env`. It is not hardened for production —
no read-only filesystem, no non-root enforcement beyond the base image default, no
resource limits, no TLS termination. `docker-compose.prod.yml` is the actual production
counterpart (see above) — TLS there is the host's Apache, not anything inside either
compose file.

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

## Still worth checking — status unverified from this workstation

This machine has no SSH/console access to the production server, so none of the below
could actually be confirmed while writing this — only inferred from what's committed.
Whoever next has server access should verify each one directly rather than trust this
list.

- ~~**Queue workers**~~ — **done**: `docker-compose.prod.yml`'s `queue` service already
  runs `php artisan queue:work redis --sleep=3 --tries=3 --timeout=600` with `restart:
  unless-stopped`, and the deploy pipeline restarts it every deploy.
- ~~**TLS**~~ — **done, but outside this repo**: the host's own Apache terminates TLS and
  reverse-proxies to `nginx` on `127.0.0.1:8095` (see `docker/nginx/prod.conf`'s comment).
  That Apache vhost config isn't tracked here, so it can't be verified from the repo —
  confirm on the server directly if TLS/cert renewal ever needs debugging.
- **Secrets**: `backend/.env` on the server is untracked (correctly — `.gitignore` excludes
  it) and this workstation has never seen it, so whether `APP_KEY`/`DB_PASSWORD`/
  `REDIS_PASSWORD` are strong, unique, and out of shell history is unverified.
- **`APP_DEBUG=false`, `APP_ENV=production`** — unverified; stack traces must never reach
  a client. `bootstrap/app.php`'s exception handler already returns generic messages for
  the exceptions it explicitly maps, but the framework default (uncaught exceptions)
  still needs `APP_DEBUG=false` to stay generic.
- **`config:cache`, `route:cache`, `event:cache`** — the deploy step runs `config:clear`,
  not `config:cache`; there is still no build-time config/route/event caching.
- **`TENANCY_ROOT_DOMAIN` and DNS** — the one confirmed live tenant (NewTech) is served on
  its own custom domain (`newtechkh.com`), not a `{slug}.ntcsweb.com` subdomain, so it's
  unclear whether the subdomain path (`DomainTenantResolver`, wildcard DNS) has been set
  up or exercised at all yet, or whether every tenant so far uses its own custom domain.
- **`SANCTUM_STATEFUL_DOMAINS` / `CORS_ALLOWED_ORIGINS`** — must list the real production
  SPA origin(s); unverified from here. The wildcard `*.{root domain}` CORS pattern in
  `config/cors.php` covers subdomains, but a custom domain like `newtechkh.com` needs
  adding explicitly if cookie auth (not Bearer) is what it actually uses.
- **`SESSION_DOMAIN`** — a custom tenant domain can't share a `.ntcsweb.com` cookie at all
  and must use Bearer-token auth instead (already supported transparently, see
  [docs/api.md](api.md#authentication)) — worth confirming which mode `newtechkh.com`
  is actually running in, since that changes what `SESSION_DOMAIN` even needs to be.
- **Database backups** — nothing automated is visible from this repo. `postgres_data` is a
  named Docker volume with no backup policy tracked here; given real tenant data already
  exists in production, a `pg_dump` schedule (or
  the hosting platform's managed-Postgres backup feature) is a hard requirement, not a
  nice-to-have. See the incident recorded in
  [docs/database.md](database.md#migration-gotchas) for exactly why this matters.
- **File storage** — whether production actually uses `FILESYSTEM_DISK=local` (still the
  `.env.example` default) or has already been switched to S3-compatible storage is
  unverified from here. The homepage slider (first upload feature, see
  [docs/database.md#file-storage](database.md#file-storage)) already uses the
  `tenants/{tenant_id}/...` convention and stores a disk-relative path rather than a full
  URL, specifically so that switch (to AWS S3 or Cloudflare R2 — `.env.example` already
  has the `AWS_*` keys wired for either) is a config change, not a data migration, if it
  hasn't happened yet.
- **PHP image**: `docker/php/Dockerfile` still has no `pcntl` extension — and unlike when
  this line was first written, `queue` is now an actually-running worker, so this is a
  live gap, not a deferred one: `queue:work` can't handle graceful restart signals without
  it, meaning a container restart (every deploy restarts `queue`) risks killing an
  in-flight job mid-execution instead of letting it finish first.
- **Health checks / readiness**: `GET /up` exists (Laravel's default) and is a reasonable
  liveness probe; nothing yet checks Postgres/Redis connectivity specifically for
  readiness.

Production is live — this is no longer a "before Phase 12 starts" list, it's a punch list
of what to verify or harden against the server that already exists.
