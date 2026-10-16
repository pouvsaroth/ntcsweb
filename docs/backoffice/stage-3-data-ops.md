# Stage 3 — Data ops

```
Owns      backend: app/Platform/** (data-ops services, jobs, controllers), routes/platform.php,
          central migrations for bo_* tables, one line in routes/console.php (the scheduler tick)
          frontend: frontend/src/backoffice/** (Data ops pages)
Never     the school app's own backup page (DatabaseBackupController stays as it is)
Depends   Stages 1–2
```

Modelled on SmartGPS-V3's `data-ops` area (databases, backups and a schedule)
and its `monitor` area, cut to what NTCSWEB runs: one central database
(`bo_school`) and one database per school (`tn_<slug>`), all on the same
Postgres server.

## Why

Today:

- Backups exist only as a **download-on-demand** (school admin page, super
  admin). Nothing runs on a schedule, nothing is kept on the server, nobody
  can see when the last good backup of a school was taken.
- New tenant migrations reach the schools **only on deploy**
  (`tenants:provision-all-databases`). If one school fails there, nobody sees
  it, and there is no way to re-run it for that school alone.
- A school can be "active" with **no database at all** (the local `test`
  school is one) and nothing notices until a page throws.

## Build

### 1. Databases — one screen, every database

One row per database the platform owns: `bo_school`, then every school's
`tn_<slug>` (from `tenants`, never from `pg_database` — a database nobody
registered is not ours, and a registered one that is missing must show as
**missing**, not vanish).

For each: school + status · exists / reachable · size · **pending
migrations** (tenant migrations not yet in that database's `migrations`
table) · **last good backup** and its age.

Flags, so problems are visible at a glance:

- **missing** — an active school whose database does not exist
- **behind** — pending migrations > 0
- **no recent backup** — none newer than the schedule's interval + 6 h

Probed when the page is read, a short timeout each, so one dead database
costs its row, not the screen.

### 2. Backups

- **Back up now** — one database, or **all**. Runs as a queued job:
  `pg_dump -Fc` into `storage/app/backups/<database>/<database>_<YYYYmmdd-HHMMSS>.dump`
  (never inside a request; the existing download page keeps its own inline
  dump).
- Every run is a row in `bo_backups`: database, school, trigger
  (manual / scheduled), staff, status (running / ok / failed), size,
  duration, error message.
- **Download** a kept file (audited) and **delete** one (audited).
- **Schedule** — one setting for the platform: on/off, which weekdays, what
  time (Asia/Phnom_Penh), how many days to keep. Default: every day 02:00,
  keep 14 days. A scheduler tick every minute (`platform:backups-tick`)
  starts the run when it is due; the same tick removes files older than the
  keep-days — **but never a database's newest good backup**.
- Restore is **not** a button — a server command (decided below).

### 3. Migrations

- **Migrate** one school, or **Migrate all schools** — queued, the same
  `TenantProvisioningService::provision()` the deploy runs, one school at a
  time, each result recorded in `bo_migration_runs` (school, status,
  migrations applied, error, duration).
- A school whose database is **missing** gets **Create database** instead —
  the Stage 2 provisioning steps `database` + `seed` + `health`, without
  touching its status or owner. This is how the local `test` school gets
  its database.

### 4. Health

A small panel on the same area:

- queue: jobs waiting, failed jobs (count + the latest few, message only —
  no stack traces to staff)
- scheduler: last tick time (a tick older than 5 minutes = the scheduler is
  down, and so are scheduled backups)
- disk: free space where backups are kept, and how much the kept backups use

### 5. Permissions and tables

```
platform.data.read       see databases, backups, migration runs, health
platform.data.backup     back up now, download, delete, change the schedule
platform.data.migrate    migrate a school / all schools, create a missing database
```

New central tables: `bo_backups` (with `copied_at`, `copy_error`), `bo_backup_schedule` (one row),
`bo_migration_runs`, `bo_heartbeats` (the scheduler tick's last time).
Every write is a `bo_audit_logs` row with the school's `tenant_id`.

## As built (backend)

- Code: `app/Platform/DataOps/{PgDump,PlatformDatabases,BackupRunner,SchoolMigrator}.php`,
  jobs `RunBackup`, `RunSchoolMigration`, commands `platform:backups-tick` (every minute,
  `routes/console.php`) and `platform:restore-backup`, `DataOpsController`, routes under
  `/api/v1/platform/data/*`, migration `2026_10_16_030000_create_bo_data_ops_tables`.
  Disks `backups` and `backups-copy` in `config/filesystems.php`; `SchoolDatabase` gained
  `sizeOf()` / `appliedMigrations()`, `SchoolProvisioner` gained `createDatabase()`.
- Tests: `tests/Feature/Platform/PlatformDataOpsTest.php` (13), with `FakePgDump`.
- Proven locally for real: back up all → 4 dumps (0.45–0.8 MB), each copied, copy
  byte-identical; `pg_restore --list` reads them; `platform:restore-backup` into a scratch
  database restored all 165 migrations (scratch database dropped after). The `test` school
  got its missing database (165 migrations, 20 s). Migrate all → 4 schools, 0 pending.

### Found while running it for real — these matter on the server too

1. **The queue container had no `pg_dump`.** It was an image from before
   `postgresql-client-18` was added to `docker/php/Dockerfile`, so every backup failed with
   `pg_dump: not found` (and showed as failed on the screen, as it should). Fixed locally with
   `docker compose up -d --build queue`. **Check the server's queue container the same way**
   (`docker exec <queue> which pg_dump`) and rebuild it if missing.
2. **The scheduler container kept the old database name.** `schedule:work` is long-running and
   passes the environment it started with to every command it runs, so after `.env` changed
   (`ntcsdb_real` → `bo_school`) every scheduled command — the school app's nightly jobs too —
   failed with "database ntcsdb_real does not exist". Fixed by `docker restart ntscweb_scheduler`.
   **After any `.env` change, restart the scheduler and the queue worker.** The Health panel now
   shows this as "scheduler down".

## As built (screens)

- **Data ops** menu item, four tabs as routes: `frontend/src/backoffice/pages/data/`
  `{DataOpsLayout,DatabasesTab,BackupsTab,MigrationsTab,HealthTab}.vue` and
  `components/BackupScheduleCard.vue`. Backups and Migrations refresh themselves while a run
  is unfinished; Health every 30 s. Cards on phone, English + Khmer.
- Render-checked in headless Chrome (back up all → list refreshes until done → download →
  schedule save → migrations → health → phone → Khmer).

### Found by the render check

3. **Every download answered 404.** The queue worker (root) created backup folders with
   Flysystem's default `0700`, so php-fpm (www-data) could not see the files. The `backups` and
   `backups-copy` disks now create folders `0755` / files `0644` (still never web-served
   directly); a test pins it. Folders created before the fix were `chmod 755`'d locally — **on the
   server, do the same for `storage/app/backups*` if backups already ran there.**
4. Health showed the scheduler's time in UTC while every other date was local — the API now
   sends it with its zone.

## Not in this stage

- **Restoring** from the back office — server command only (decided below).
- Choosing the real second place for backup copies — the switch is built; the target is chosen later.
- Moving a school to another database server; deleting a school's database.
- Billing (Stage 4), support / impersonation (Stage 5).

## Done when

```bash
docker exec ntscweb_php php artisan test --filter=Platform
    # databases list: bo_school + every school; missing flagged; pending migrations counted
    # back up now → job → file on disk + bo_backups ok; a pg_dump failure → failed with its message
    # schedule: due tick starts one run per database; not due → nothing; never twice for one slot
    # retention: older files removed, the newest good one per database always kept
    # download / delete audited; data.read alone cannot back up, migrate or download
    # migrate all → a run row per school; one failing school does not stop the others
    # create database for a missing one → exists, migrated, seeded; status and owner untouched
cd frontend && npm run build
```

Then locally, for real: back up `bo_school` and every `tn_*`, download one and
check it with `pg_restore --list`; create the `test` school's missing
database from the screen; migrate all. Render-checked in headless Chrome,
desktop and phone.

## Decided (owner, 2026-10-16)

1. **Restore is a server command only.** No restore button in the back
   office. `php artisan platform:restore-backup {backup id} --into=<new
   database name>` restores into a database you name; pointing a school at
   it, or replacing a live one, stays a deliberate step on the server. The
   procedure is written in `docs/backoffice/restore.md`.
2. **Backups are kept on the server and copied to a second place.** The
   second place is chosen later, so it is built as a switch: a Laravel
   filesystem disk named by `PLATFORM_BACKUP_COPY_DISK` (empty = off). Each
   backup records whether its copy succeeded (`copied_at` / `copy_error`),
   and the Databases screen flags a backup that was not copied. Locally it is
   tested against a separate folder (`storage/app/backups-copy`); switching to
   cloud storage (S3-type) or another server (SFTP) later is configuration
   plus one composer package — no code change. The copy follows the same
   keep-days.
