# Stage 4 — Where a school's data lives (shared, dedicated, separate server)

```
Owns      backend: app/Platform/** (placement, connections, provisioning per mode),
          central migration for bo_* tables, App\Models\Tenant::database() (one method)
          frontend: frontend/src/backoffice/** (New school dialog, school Database tab)
Never     the school tables (database/migrations/tenant) — they do not change
Depends   Stages 1–3
```

Modelled on SmartGPS-V3's `docs/tracks/tenant-provisioning.md` (database mode
`shared` / `dedicated`, isolation `database` / `instance`), adapted to what
NTCSWEB's school tables allow.

> Billing moves to Stage 5, Support to Stage 6.

## Decided (owner, 2026-10-16)

1. **Shared = the SmartGPS model**: shared schools' rows sit in the same
   tables, separated by `tenant_id`. The shared database is **`school_share`**.
2. **A template database** (`tn_template`, kept migrated) from which every
   dedicated database is cloned (`CREATE DATABASE … TEMPLATE tn_template`).
3. **Default for a new school: Shared** (as SmartGPS).
4. **Separate server**: staff create the database and its login on that
   server and paste the connection; the back office never holds that
   server's admin password.
5. **Moving a school between placements is in this stage.**

## What "shared tables" means for NTCSWEB — measured

| Part of the school app | Count |
|---|---|
| tables in a school database | 138, **none** with `tenant_id` |
| unique indexes that must become unique *per school* | 70 |
| models on the school connection | 131 |
| raw query-builder calls (8 files) | 42 |
| `unique` / `exists` validation rules (121 files) | 230 |

Editing all of that by hand is weeks of work and would miss something. So the
**database enforces it**, as SmartGPS does with row-level security (RLS):

- Every school table gets `tenant_id bigint NOT NULL DEFAULT
  current_setting('app.tenant_id')::bigint` — inserts fill it themselves.
- RLS on every school table, `FORCE`d, policy `USING / WITH CHECK
  (tenant_id = current_setting('app.tenant_id')::bigint)` — every query
  (Eloquent, raw, validation rules) only ever sees and writes the current
  school's rows. No setting → no rows (fails closed).
- Every opening of the school connection runs `set app.tenant_id = <school>`
  (requests, queue jobs, seeders, migrations).
- **The same applies to dedicated and separate-server databases**: one code
  path for all three placements, and a school can move between them by
  copying rows.
- The 70 unique indexes become `(tenant_id, …)`.
- **Superusers bypass RLS.** The app today connects as `ntcsuser`, the
  Postgres superuser. School connections therefore switch to a new login
  **`ntcs_app`** (no superuser, no BYPASSRLS) — a server `.env` change, done
  with a step-by-step like the rename. The central database keeps using the
  owner login.

## The three placements

| Placement | Data lives in | Created by |
|---|---|---|
| **Shared** (default) | rows with `tenant_id` in `school_share` | the provisioning job (nothing to create per school) |
| **Dedicated** | its own database `tn_<slug>`, cloned from `tn_template` | the provisioning job |
| **Separate server** | its own database on another Postgres server | staff create it + a login there and paste the connection; the job migrates, seeds, checks |

## Build — in steps, each tested and checked in with the owner

**4a. `tenant_id` + RLS in every school database** (no placement change yet).
One tenant migration: add `tenant_id` (default from the setting) to all 138
tables, backfill existing rows with the school's id, NOT NULL, index, rewrite
the 70 unique indexes as `(tenant_id, …)`, enable + force RLS with the policy.
The school connection sets `app.tenant_id` and uses `ntcs_app`. Proven by the
full suite (1,128 tests) plus new tests that put **two schools in one
database** and show neither can read, write, count or validate against the
other's rows — raw queries and `unique`/`exists` rules included.

**4b. Placements.** `bo_school_databases` (mode, database, host, port,
username, encrypted password, sslmode). `school_share` and `tn_template`
created and kept migrated (the deploy migrates them too). New school dialog:
Shared / Dedicated / Separate server (+ Test connection). Provisioning per
mode.

**4c. Data ops per placement.** Databases tab shows placement and server;
backups: dedicated / separate → `pg_dump` of the database (separate with its
own login); shared → nightly dump of `school_share` + a per-school export
(`COPY … WHERE tenant_id`); migrate all placements.

**4d. Move a school.** Suspend → backup → copy its rows to the new placement
→ compare counts table by table → switch → reactivate; old copy kept until
**Remove old copy**. A failure before the switch leaves it where it was.

**4e. Screens** for all of the above, then a real local run with a second
Postgres container as the "separate server".

## As built — 4a (local, 2026-10-16)

- `app/Support/Tenancy/SchoolTables.php` — secures one school table (column,
  backfill, per-school uniques, FORCEd RLS policy) and grants the app role.
- Tenant migration `2026_10_17_000000_add_tenant_id_and_row_level_security_to_school_tables`
  — all school tables in one transaction; refuses a school database that has rows
  but no school set.
- `app/Support/Tenancy/SchoolConnection.php` — on every school connection:
  `SET ROLE ntcs_app` (except while migrating) and `app.tenant_id`; follows
  `TenantContext` changes (`set`, `forget`, `usePlatform`, and `runFor` handing
  back). `TenantProvisioningService` migrates as owner with the school set.
- `app/Support/Tenancy/SchoolDefaults.php` — the defaults six old migrations
  inserted (General form category + 3 templates, payroll settings, tax brackets,
  NSSF schemes, performance settings), now created per school: provisioning,
  every deploy (`BaseDataSeeder`), and every test school (`TenantFactory`).
  A database that belongs to no school (tests; later school_share, tn_template)
  drops those ownerless rows during the migration.
- Config: `tenancy.database.app_role` (`TENANCY_DB_APP_ROLE`, default `ntcs_app`).
- Local result: `tn_demo`, `tn_newtech` migrated — 138/138 tables secured, 997
  students stamped with newtech's id, app reads them; as `ntcs_app` a wrong
  school sees 0 rows, a row written for another school is refused, TRUNCATE is
  refused.
- Tests: `tests/Feature/Tenancy/SharedDatabaseIsolationTest.php` (8). The whole
  suite now runs under RLS (all test schools share one database). It found test
  fixtures that only passed because test schools shared unprotected tables:
  `HasAcademicAdmin::actingAsAdminWithPermissions()` made a NEW school on every
  call (a second admin was quietly acting on another school's rows) — it now
  reuses the test's school; fixtures created before sign-in use the new
  `inSchool()`.

## As built — 4b (local, 2026-10-16)

- `bo_school_placements` (central): mode, database, and for a separate server
  host, port, username, **encrypted** password, sslmode. No row = dedicated.
- `App\Models\Tenant::database()` → `SchoolDatabaseConfig`: every connection
  follows the placement (separate: the pasted server and login, no `SET ROLE` —
  FORCEd RLS binds that login even as table owner).
- `TenantProvisioningService`: shared → `school_share` created/migrated with no
  school set; dedicated → **cloned** from `tn_template`, then migrated;
  separate → only migrated. `tenants:provision-all-databases` (every deploy)
  first prepares `tn_template` and `school_share`.
- API: `POST /schools` takes `placement` (default **shared**, from
  `platform.default_placement`) and `server{…}` for separate, which must pass
  the connection test first; `POST /schools/test-connection` (saves nothing).
  `SchoolResource.placement` never includes the password.
- Data ops (minimal until 4c): the Databases list shows central, template,
  shared (with its schools), and each dedicated / separate school; a separate
  school is probed through its own login.
- Tests: `tests/Feature/Platform/PlatformPlacementTest.php` (5).
- Proven locally for real: `tn_template` and `school_share` created (138 tables
  secured, 0 rows each); a second Postgres container as the separate server
  (database + non-superuser login created there as staff would); created
  share-one, share-two (shared), dedi-one (dedicated, cloned), far-one (separate)
  — all active, every step ok; each school's rows carry only its own id; as the
  app role share-one sees only share-one's rows in school_share; the school app
  reads all four.

## As built — 4c (local, 2026-10-16)

- **Backups per placement**: dedicated → `pg_dump` of its database; separate →
  `pg_dump` with that school's login and server, `--enable-row-security` and
  `app.tenant_id` set (its login is bound by FORCEd RLS, and pg_dump's default
  "row security off" is refused there — found running it for real); shared →
  a dump of the whole `school_share` **and** a per-school export
  (`SchoolExporter`: a zip of one JSON-lines file per table + manifest, read
  through the school's own connection so RLS guarantees only its rows).
- `bo_backups.format` (`dump` | `school`); "Back up all" and the schedule use
  `BackupRunner::queueAll()` (central, shared, every own-database school, each
  shared school's export — never the template). Retention keeps the newest good
  backup per database **per school per format**. `platform:restore-backup`
  refuses a school export (that comes back by moving the school, 4d).
- Databases report: template no longer flagged "no recent backup".
- Tests: 5 more in `PlatformDataOpsTest` (19).
- Proven locally for real: back up all → 9 backups across all placements;
  share-one's export = 490 rows, every one carrying share-one's id; the
  separate server's dump holds all 139 tables; Databases report shows central,
  template, shared (with its schools), dedicated and separate (with its
  server), all reached, 0 pending; Migrate all → 6 schools OK including the
  separate server and both shared schools.

## As built — 4d (local, 2026-10-16)

- Moves allowed (owner's decision): shared → dedicated, shared → separate,
  dedicated ↔ separate. **Never into shared** — the school's ids would collide
  with other schools' there.
- `bo_school_moves` (both placements stored **encrypted**, never returned);
  `SchoolMover` + job `MoveSchool`: check → suspend → backup (export) →
  prepare (dedicated: cloned from the template; separate: migrated with its
  own login) → copy (`SchoolImporter`: parents first by the target's foreign
  keys, rows in id order, sequences moved past the highest id) → verify (row
  counts per table) → switch → reactivate. A failure before the switch drops
  or empties the target and reopens the school where it was; the move is only
  marked failed after that. "Remove old copy" (separate button): shared →
  delete the school's rows from school_share; dedicated → drop its database;
  separate → delete its rows there.
- API: `GET/POST /schools/{id}/moves`, `POST /school-moves/{id}/remove-old-copy`;
  permission `platform.school.placement`.
- Tests: `tests/Feature/Platform/PlatformMoveTest.php` (6).
- Found running it for real: link tables have no `id`/sequence (the copy
  failed on `class_teachers`), and a failed move was shown "failed" before the
  school was reopened. Both fixed; the rollback itself worked on all three.
- Proven locally for real: share-one shared → dedicated, dedi-one dedicated →
  separate, far-one separate → dedicated — each 490 rows / 138 tables, copy
  identical; the app reads each from its new place; a new row gets the next id;
  "Remove old copy" removed exactly the old data (share-two's rows in
  school_share untouched) and refuses a second time.

## As built — 4e (local, 2026-10-16)

- **New school** dialog: a "Where the data lives" choice — Shared (default),
  Dedicated, Separate server. Separate shows the server login fields (host,
  port, database, login, password, SSL) with **Test connection**; a bad
  password shows Postgres's own message, a good one "Connected as … (PostgreSQL
  18.4)". Components `PlacementChoice`, `ServerFields`.
- **School page → Where the data lives** (`SchoolPlacementPanel`): placement,
  database, server and login (never the password); **Move…** offers only the
  allowed targets; each move lists its steps (✓/✕, the message on hover), the
  row count ("490 rows in 138 tables — copy identical"), the error if it
  failed, and "Old copy kept at …" with **Remove old copy** (confirm). The
  panel refreshes every 2 s while a move runs and reloads the school when it
  ends.
- **Data ops → Databases**: a kind badge per row (Platform / Template / Shared
  / School), the template marked "Empty — copied into every new dedicated
  school" and never backed up, the separate server under its database, and the
  shared row listing its schools, each with **Export** (that school's rows
  only). The "Pending migrations" column folded into State (only shown when
  not 0) so the table fits at 1280 px.
- **Backups**: a school export says so ("School export · <school>"); a move's
  backup shows trigger "Move".
- Checked in headless Chrome (desktop, phone 390 px, Khmer): create through
  the dialog → shared; test connection wrong/right; move shared → dedicated
  through the dialog → Done, copy identical; Export; no page or table wider
  than the window; no console errors. (Two `[intlify] Not found parent scope`
  warnings come from the shared `BasePagination` — old, harmless.)

### Deploying 4a to production

Nothing to set on the server first: the migration creates `ntcs_app` itself
(the app's login is a superuser) and the default `TENANCY_DB_APP_ROLE` turns
it on. The deploy's `tenants:provision-all-databases` migrates each school with
its school set, so existing rows are stamped correctly. Take fresh backups of
every database right before (Data ops → Back up all, and download them).

## Not in this stage

- Creating databases on another server with its admin login.
- Automatic moves; read replicas.

## Rollout to production (after 4a)

4a changes every school database, so it ships on its own, with a fresh
backup of every database first, and the `ntcs_app` login created on the
server before the deploy (step-by-step provided). Existing schools stay
**dedicated**; nothing moves unless staff move it.

## Done when

```bash
docker exec ntscweb_php php artisan test           # the whole suite, not only Platform
    # two schools in one database: no read, write, count, unique or exists check crosses
    # each placement: create → migrate → seed → health → active; the school app reads its data
    # separate: connection test; bad password → clear error; password never returned
    # move shared → dedicated → separate and back: counts match, school works after each
```
