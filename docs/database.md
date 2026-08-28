# Database

PostgreSQL 18, one shared database (`ntcsdb`), every tenant-owned table carries
`tenant_id`. No database-per-tenant, no schema-per-tenant.

## Tables as of Phase 2–4

| Table | Scope | Notes |
|---|---|---|
| `tenants` | Platform-wide | The schools themselves. Soft-deletes. `settings` is a `jsonb` blob for per-school config, read only through `Tenant::setting()` |
| `tenant_domains` | Platform-wide | Hostname → tenant mapping. Many rows per tenant (subdomain + any custom domains) |
| `users` | Tenant-owned, `tenant_id` nullable | `NULL` = platform account (Super Admin). Soft-deletes |
| `password_reset_tokens` | Tenant-owned, `tenant_id` nullable | Re-keyed on `(tenant_id, email)` — see below |
| `roles` | Tenant-owned, `tenant_id` nullable | `NULL` = platform role (currently only `super-admin`) |
| `permissions` | Platform-wide | Fixed catalog, synced from `App\Support\Authorization\Permissions`, never hand-edited |
| `permission_role`, `role_user` | Join tables | Composite primary keys, no surrogate `id` |
| `audit_logs` | Tenant-owned, `tenant_id` nullable | Append-only: no `updated_at`, no soft-delete |
| `personal_access_tokens` | Platform-wide (Sanctum) | Bearer tokens for non-session clients |

### Academic domain (teachers, students, classes)

| Table | Scope | Notes |
|---|---|---|
| `teachers` | Tenant-owned | `user_id` nullable — a teacher can exist with no login account |
| `students` | Tenant-owned | The highest-volume table in the system by design; `user_id` nullable for the same reason as teachers |
| `classrooms` | Tenant-owned | Physical (or virtual) rooms a class can be scheduled into |
| `books` | Tenant-owned | Each school's own textbook/material catalog |
| `classes` | Tenant-owned | Model class is `SchoolClass`, not `Class` — `class` is a PHP reserved word. A scheduled teaching group (e.g. "Excel Basics — Evening Batch 12"); deliberately not linked to a program/course/subject yet, since those tables don't exist until Academic Management (Phase 5 proper) lands |
| `class_schedules` | Tenant-owned | The "study day" / "study time" — one row per weekly meeting slot, so Mon/Wed/Fri is three rows, not one row holding three days. `day_of_week` is ISO-8601 (1=Monday..7=Sunday). Two Postgres `CHECK` constraints enforce `end_time > start_time` and a valid day range at the DB level, not just in validation |
| `class_book` | Join table | Class ↔ Book, many-to-many, no `tenant_id` of its own (same pattern as `permission_role`/`role_user`) |
| `enrollments` | Tenant-owned | Student ↔ Class, modelled as a full entity (not a bare pivot) so it can carry `enrolled_at`/`status` and be queried directly |
| `home_slides` | Tenant-owned | The public homepage's image slider — see [File storage](#file-storage) below for how the uploaded image itself is handled |

Platform-wide tables never carry `tenant_id`; every other table does. Where `tenant_id`
is nullable, `NULL` has one specific meaning (documented per-table above) — it is never
"tenant not yet assigned."

## Why email uniqueness is `(tenant_id, email)`, not `email`

The same person — or the same generic address — may need an account at two different
schools. A plain `UNIQUE(email)` (the Laravel default) makes that impossible. But
`UNIQUE(tenant_id, email)` alone doesn't fully work either: Postgres treats every `NULL`
as distinct from every other `NULL`, so it would happily allow **duplicate platform
accounts** (`tenant_id IS NULL`) with the same email. The fix is a second, partial index:

```sql
CREATE UNIQUE INDEX users_email_platform_unique ON users (email) WHERE tenant_id IS NULL;
```

`roles.slug` uses the identical pattern for the same reason (a platform role's slug must
still be globally unique). `password_reset_tokens` is re-keyed on `(tenant_id, email)`
with the same partial-index treatment, because Laravel's default password broker keys
solely on `email` — which would let School A's reset request overwrite School B's token
for a same-named address.

This is **Postgres-specific DDL** (`CREATE UNIQUE INDEX ... WHERE ...` partial indexes,
and `ALTER TABLE ... DROP CONSTRAINT` on the default `{table}_pkey` name when
`password_reset_tokens` loses its `email`-only primary key). It's why the test suite runs
against real Postgres, not SQLite — see below.

## Indexing decisions

Every tenant-owned table's indexes lead with `tenant_id`, matching the queries that will
actually run (a school never queries across tenants, so an index that doesn't start with
`tenant_id` is close to useless here):

- `users`: `(tenant_id, id)` [unique via the composite above], `(tenant_id, status)`,
  `(tenant_id, created_at)` — covers "active users of this school" and "newest first"
  without a second lookup.
- `roles`: `(tenant_id, level)` — the hierarchy check ("does this role outrank that one")
  filters by tenant first.
- `audit_logs`: `(tenant_id, created_at)` for the admin log screen,
  `(tenant_id, auditable_type, auditable_id)` for "history of this one record",
  `(tenant_id, user_id, created_at)` for "what did this user do." Three indexes on a
  high-write, append-only table is already a real cost — no more were added speculatively.
- `tenant_domains`: `(tenant_id, is_primary)` for "this tenant's primary hostname";
  hostname lookups go through the `UNIQUE(hostname)` index directly.

No index was added to a column "just in case." Indexes cost write throughput and storage;
each one above maps to a query pattern that actually exists in the code today.

## Partitioning

Not implemented. `audit_logs` is the table most likely to need it eventually (append-only,
highest write volume once attendance/grades land in Phase 5) — the natural next step when
it does is monthly `RANGE` partitioning on `created_at` plus a retention policy that drops
old partitions, noted as a comment in its migration. Not worth the operational complexity
until real volume or query-latency data justifies it.

## Migration gotchas

### Never use `--env=testing` outside PHPUnit

`php artisan <command> --env=testing` does **not** connect to a different database. It
only tries to load a `.env.testing` file; if that file doesn't exist (it doesn't in this
project), Laravel silently falls back to the regular `.env` — pointing straight at the
**dev database**. `phpunit.xml`'s `<php><env>` overrides only take effect when Laravel is
bootstrapped *through* PHPUnit itself (`php artisan test` / `vendor/bin/phpunit`), not via
a bare `artisan` invocation with `--env` tacked on.

This is not a hypothetical: exactly this mistake ran `migrate:fresh` against the dev
database mid-way through building this phase, destroying the pre-existing tenant and user
records it wasn't supposed to touch. **If you need to target the test database directly,
export `DB_DATABASE=ntcsdb_testing` (and the matching host/port/credentials) in the shell
before running the command — never rely on `--env`.**

### Backfilling `tenant_domains` from the old `tenants.domain` column

`2026_08_28_030100_create_tenant_domains_table.php` copies any existing
`tenants.domain` value into `tenant_domains` (as a `verified` custom domain) *before* the
following migration drops that column — order matters if these are ever replayed or
adapted.

## File storage

`home_slides` is the first feature to store an actual uploaded file, and the pattern it
establishes is the one every future upload (school logos, gallery photos, documents)
should follow:

- **Path, not URL, in the database.** `home_slides.image_path` holds a storage-relative
  path (`tenants/{tenant_id}/home-slides/{random}.jpg`), never a full URL. The API
  resource resolves it to a URL at read time via `Storage::disk('public')->url(...)`
  (`HomeSlide::imageUrl()`) — switching disks later (local → S3/Cloudflare R2, per
  [docs/deployment.md](deployment.md)) is a config change, not a data migration.
- **Tenant isolation in the path itself**, via `Tenant::storagePath()` — every uploaded
  file lives under `tenants/{tenant_id}/...` regardless of which disk backs it, matching
  the isolation the database already enforces.
- **PHP's upload limits had to be raised.** The stock `php:8.3-fpm` image ships
  `upload_max_filesize=2M`, too small for a decent banner image. See
  `docker/php/uploads.ini` (10M/12M/256M) — raising Laravel's own `max:` validation rule
  without this just moves the failure to a less friendly PHP-level cutoff instead of a
  clean 422.
- **A real PUT with a file body doesn't work.** PHP only populates `$_FILES` for `POST`
  requests — this is a PHP/SAPI-level fact, true even for a JS client sending a properly
  formed multipart PUT. Every update-with-a-new-file goes through Laravel's `_method`
  override instead: a `POST` carrying a `_method=PUT` field, which still reaches the
  normal `apiResource` `update` route. See `UpdateHomeSlideRequest` and
  `frontend/src/services/homeSlides.ts`.
- **Soft delete keeps the file; force delete removes it.** `HomeSlide` still uses
  `SoftDeletes` — a mistaken removal stays recoverable. The file itself is only deleted in
  `HomeSlide::booted()`'s `forceDeleted` hook, not the ordinary `deleted` one.
- **GD had to be added to the PHP image** (`docker/php/Dockerfile`) — needed for real
  image handling generally (a future thumbnail/resize step), and specifically for
  `UploadedFile::fake()->image(...)` in tests, which requires it to generate pixel data.

### A column's DB `default(...)` is invisible to Eloquent until re-fetched

`Model::create($attributes)` only sends the columns actually set on the model to the
`INSERT` — a column left out gets the database's own `default()`, but the in-memory model
Eloquent hands back from `create()` never learns that value. The symptom: a freshly
created row's API response shows `"status": null` in the very call that created it, while
a subsequent fetch of the same row correctly shows `"status": "active"` — the database was
always right, only the immediate response was stale. Caught live (not by the test suite)
while building the academic-domain endpoints.

The fix is a PHP-level mirror of the default via Eloquent's `protected $attributes = [...]`
class property, applied to every model with a DB `default()` — `Teacher`, `Student`,
`Classroom`, `Book`, `SchoolClass`, `Enrollment`. Set it there whenever a new column gets
a DB default; a regression test can't catch this on its own since it requires asserting
the field on the immediate creation response specifically (see
`TeacherTest::test_a_newly_created_teacher_reports_its_default_status_immediately`).

## Seeding

`RolePermissionSeeder` (run via `php artisan db:seed`) is idempotent and safe to re-run on
every deploy:

1. Syncs `permissions` from `Permissions::catalog()` — additions and removals both apply;
   a permission removed from the catalog is deleted, cascading to `permission_role` and
   immediately withdrawing it.
2. Ensures the platform `super-admin` role exists.
3. Ensures every existing tenant has its four system roles (`school-admin`, `teacher`,
   `staff`, `student`) with the catalog's default permission set.
4. One-time bootstrap: any tenant user with zero roles is promoted to that school's
   `school-admin` — this is what keeps a pre-existing account usable the first time RBAC
   checks are enforced on it.

It does **not** create tenants. See the root [README](../README.md#first-time-setup) for
creating the first school and its admin.
