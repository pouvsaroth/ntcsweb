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
| `provinces`, `districts`, `communes`, `villages` | Platform-wide | Cambodia's official administrative hierarchy, seeded once from a real dataset — see [Cambodia geography reference data](#cambodia-geography-reference-data) below |
| `permission_role`, `role_user` | Join tables | Composite primary keys, no surrogate `id` |
| `audit_logs` | Tenant-owned, `tenant_id` nullable | Append-only: no `updated_at`, no soft-delete |
| `personal_access_tokens` | Platform-wide (Sanctum) | Bearer tokens for non-session clients |

### Academic domain (teachers, students, classes)

| Table | Scope | Notes |
|---|---|---|
| `teachers` | Tenant-owned | `user_id` nullable — a teacher can exist with no login account |
| `students` | Tenant-owned | The highest-volume table in the system by design; `user_id` nullable for the same reason as teachers. Field shape (`first_name`/`last_name`/`english_name`, structured `house_no`/`street_no`/`village_code`/`other_address`, `facebook`/`telegram`, `photo_path`) deliberately mirrors an existing external system's `t_student` table, column-for-column, so importing real records is a straight mapping — see the `restructure_students_table_for_legacy_migration` migration |
| `student_guardians` | Tenant-owned | One row per guardian of one student (a student can have more than one) — mirrors a legacy `t_school_student_guardian` table. `guardian_type` is free text, not an enum: the legacy `int` column had no available lookup table to map from |
| `student_educations` | Tenant-owned | One row per school a student attended before enrolling here — mirrors a legacy `t_school_student_education` table. `end_date` nullable means still attending there |
| `classrooms` | Tenant-owned | Physical (or virtual) rooms a class can be scheduled into |
| `books` | Tenant-owned | Each school's own textbook/material catalog. `fee` is a nullable default/list price — see [Dynamic classes: one session, many books, many fees](#dynamic-classes-one-session-many-books-many-fees) |
| `classes` | Tenant-owned | Model class is `SchoolClass`, not `Class` — `class` is a PHP reserved word. A scheduled teaching group (e.g. "Excel Basics — Evening Batch 12"); deliberately not linked to a program/course/subject yet, since those tables don't exist until Academic Management (Phase 5 proper) lands |
| `class_schedules` | Tenant-owned | The "study day" / "study time" — one row per weekly meeting slot, so Mon/Wed/Fri is three rows, not one row holding three days. `day_of_week` is ISO-8601 (1=Monday..7=Sunday). Two Postgres `CHECK` constraints enforce `end_time > start_time` and a valid day range at the DB level, not just in validation |
| `class_book` | Join table | Class ↔ Book, many-to-many, no `tenant_id` of its own (same pattern as `permission_role`/`role_user`) — the session's *book menu*, not a shared curriculum; see below |
| `enrollments` | Tenant-owned | Student ↔ Class ↔ Book, modelled as a full entity (not a bare pivot) so it can carry `enrolled_at`/`book_id`/`fee`/`status` and be queried directly |
| `home_slides` | Tenant-owned | The public homepage's image slider — see [File storage](#file-storage) below for how the uploaded image itself is handled |
| `student_imports` | Tenant-owned | One row per uploaded legacy-`t_student` CSV — see [Bulk student import](#bulk-student-import-from-a-legacy-system) below |
| `programs` | Tenant-owned | The public marketing catalog of courses (e.g. "Computer Basic") shown on the homepage's "Popular Programs" section and the full `/programs` page. Distinct from `classes` — a program is what a visitor browses before enrolling, a class is the operational record of one running batch of it. `is_featured` controls the homepage subset; the full catalog page shows every active row regardless |

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

## Dynamic classes: one session, many books, many fees

`classes` originally modelled "a scheduled teaching group" as if it had one shared
curriculum — every enrolled student implicitly studying the same book(s) together. Real
usage broke that: a school's computer lab runs several self-paced courses out of the same
room at the same time — e.g. one student on an Excel book, another on a Word book, both in
the identical Sat–Sun 1–3PM session with the same teacher and room, each paying a different
fee for their own book. A class is a shared **session** (who teaches it, where, when), not
a shared curriculum, so book choice and pricing had to move to the individual student, not
the class.

- **`class_book` is now the session's *menu***, not "the curriculum everyone in this class
  shares" — which books a teacher/room/timeslot combination currently offers.
- **`enrollments` gained `book_id` and `fee`.** One row per (student, class, book) — a
  student taking two books in the same session is two enrollment rows, each independently
  trackable (one can be dropped or completed without touching the other). The unique
  constraint moved from `(student_id, class_id)` to `(student_id, class_id, book_id)`
  accordingly.
- **`StoreEnrollmentRequest` validates that the chosen book is actually on the class's
  menu** (a `class_book` lookup in `withValidator()`) — enrolling a student in a book the
  session doesn't even offer would be silently meaningless data.
- **`fee` is a snapshot, not a live read of `books.fee`.** `books.fee` is only ever a
  default/list price the frontend pre-fills a new enrollment form with; the actual amount
  charged lives on the enrollment row itself and stays fixed even if the book's catalog
  price changes later, or gets manually adjusted per enrollment for a discount or
  scholarship. Covered directly by
  `EnrollmentTest::test_updating_a_books_fee_does_not_change_an_existing_enrollments_fee`.
- **`enrollments.book_id` is `restrictOnDelete()`, not `cascadeOnDelete()`** — an enrollment
  with a fee attached is a financial/academic record, not disposable metadata. Force-deleting
  a book that has enrollments against it has to be a deliberate, blocked-until-handled
  decision, not something that silently erases which book a payment was actually for.
- **Deliberately not built (yet)**: actual payment/invoice tracking — whether an enrollment
  has been paid, in full or in part. This is enrollment-time *pricing*, not a billing system;
  that would be a separate table on top of this if/when it's needed.

## The About page: a settings blob, not a table

Unlike Home Slides and Programs (both genuinely lists — many rows per tenant), the public
About page has exactly one instance per school: one set of 4 stat counters, one history
block, 3 vision/mission/goals pillars, 4 achievements. A dedicated table with CRUD
endpoints would be the wrong shape for "there is exactly one of these" — so it's stored as
a nested `about` key inside the existing `tenants.settings` jsonb column instead
(`App\Support\Content\AboutPageContent` is the single place that reads it, fills in an
empty-but-correctly-shaped default before it's ever configured, and resolves the history
photo's stored path to a URL).

- **Fixed shape, not a repeater.** `UpdateAboutPageRequest` validates `stats` as
  `size:4`, `pillars` as `size:3`, `achievements` as `size:4` — exactly, not `min`/`max` —
  because the public template has that many slots and no admin-facing "add another" affordance.
- **Read-modify-write on a shared JSON column needs a `refresh()` first.** The
  `settings` column also holds other configuration (`public_site`, etc. — see
  `Tenant::setting()`). `AboutPageController::update()` merges the new `about` value into
  `$tenant->settings` rather than overwriting the column outright, and calls
  `$tenant->refresh()` immediately before reading it — the `$tenant` instance handed in by
  `TenantContext` can already be resolved (and its `settings` attribute cached in memory)
  from earlier in the request lifecycle, so skipping the refresh risks silently dropping
  whatever settings weren't touched by this request. Caught by
  `AboutPageTest::test_saving_about_content_does_not_clobber_other_tenant_settings`.
- **The public endpoint reuses `GET /api/v1/public/settings`** rather than adding a new
  route — `about` is `null` until a school saves it at least once, and the public About
  page falls back to simple static copy in that case rather than rendering an empty rich
  layout.
- **The history photo lives on the `public` disk**, same tenant-isolated
  `Tenant::storagePath('about')` pattern as Home Slides and Programs — it's marketing
  content, correctly public, unlike the private student-import CSVs.

## Cambodia geography reference data

`provinces` > `districts` > `communes` > `villages` — Cambodia's real official administrative
hierarchy (25 provinces, 197 districts, 1,646 communes, 14,372 villages), sourced from the
MIT-licensed [seanghay/pumi-js](https://github.com/seanghay/pumi-js) dataset (itself built
from the NIS/NCDD gazetteer) and converted to JSON at `database/data/cambodia/*.json`.
Platform-wide, like `permissions` — identical for every school, never edited through an
admin screen. Powers the student registration form's cascading Province → District →
Commune → Village selects (`GET /api/v1/geo/{provinces,districts,communes,villages}`).

- **Surrogate integer PKs, not the NIS code.** Every FK in this app stays a fast integer
  join; the real code (e.g. province `"01"`, village `"01020101"`) lives in a plain unique
  `code` column instead, matching the `permissions.slug` pattern.
- **`students.village_code` is still not a hard FK to `villages`** — same reasoning as
  the legacy-mirroring migration that created it: this platform isn't Cambodia-only, and a
  legacy-imported or future non-Cambodian code should never be blocked by a constraint it
  can't satisfy. The admin UI's cascading selects are what keep *new* data consistent.
- **Seeded via `CambodiaGeographySeeder`, not the migration** — schema and data stay
  separate, same split as `RolePermissionSeeder`. It's idempotent by short-circuiting
  entirely once `provinces` is non-empty (this data never changes, so there's no need to
  diff ~16,000 rows on every deploy), and inserts are chunked (1,000 rows at a time) with
  ids left to Postgres's own auto-increment — a bulk insert that sets `id` explicitly would
  desync the table's sequence and collide with a later real insert.
- **`GET /api/v1/geo/lookup?village_code=...`** resolves a village's full ancestry
  (province/district/commune/village) from its code alone — what the student *edit* form
  uses to pre-select all four dropdowns without downloading all ~14,000 villages
  client-side to find the right one.

## Student registration: guardians and education are their own tables

The legacy system this platform mirrors keeps a student's guardians and prior schools in
their own tables (`t_school_student_guardian`, `t_school_student_education`), not flat
columns on the student — a student can have more than one guardian (father, mother,
other) and more than one prior school. The `guardian_name`/`guardian_phone` columns
originally added to `students` assumed a single guardian and were wrong; they were
dropped (0 rows existed at the time) in favor of `student_guardians` and
`student_educations`, both plain child tables of `Student` (own `tenant_id`, no soft
deletes — same pattern as `Enrollment`).

- **One registration screen, one transaction.** `POST /api/v1/students` accepts the
  student's own fields plus optional `guardians[]`/`educations[]` arrays in the same
  request; `StudentController::store()` wraps creating the student and all of its
  guardians/educations in `DB::transaction()` so a failure partway through never leaves
  an orphaned half-registered student.
- **Update replaces the whole list, like `SchoolClass`'s weekly schedule.** Sending
  `guardians` on `PUT /api/v1/students/{id}` deletes and recreates that student's entire
  guardian list; *omitting* the key entirely leaves existing guardians untouched. Same for
  `educations`, independently.
- **`guardian_type` is free text**, not a fixed enum — the legacy `int` column had no
  documented code-to-label mapping available at build time, so schools type the
  relationship directly ("Father", "Aunt") rather than this platform assuming a mapping
  it can't verify.
- **A genuine Eloquent pluralization gotcha**: `StudentEducation`'s guessed table name is
  `student_education` (singular) — Laravel's pluralizer treats "Education" as uncountable,
  the same category as "advice" or "information", and only pluralizes the *last word* of a
  StudlyCase class name. The migration creates `student_educations` (plural, matching
  every other table in this schema), so the model needs an explicit
  `protected $table = 'student_educations';` or every query 500s with "relation
  `student_education` does not exist." `StudentGuardian` pluralizes correctly on its own
  and needs no such override — this is genuinely word-specific, not something to guard
  against for every new model.
- **Multipart nested arrays, not JSON.** The registration form uploads a photo alongside
  the guardian/education arrays, so the whole request is `multipart/form-data`, which
  cannot carry nested JSON — arrays travel as bracket-notation keys
  (`guardians[0][guardian_name]`, `educations[1][end_date]`), which PHP parses back into
  the same nested array shape automatically. This is a different code path than
  PHPUnit's `postJson()` (which sends a real JSON body) — the automated test suite alone
  would not have caught a bracket-notation parsing mismatch, so this was additionally
  verified with a live `curl -F` request through the real HTTP stack before shipping.

## Bulk student import from a legacy system

`students`'s field shape (see the table above) mirrors an external system's `t_student`
table so a plain CSV export can be mapped column-for-column. The import itself
(`App\Jobs\ProcessStudentImport`) runs as a **queued job**, not inline in the upload
request, because "big data" here specifically means thousands of rows, easily minutes of
work — no HTTP request should block on that, and none should risk a gateway timeout.

- **A `queue` container had to be added to `docker-compose.yml`.** Nothing in this stack
  previously ran `php artisan queue:work` — `QUEUE_CONNECTION=redis` was configured but had
  no consumer, so anything dispatched with `ShouldQueue` would have sat in Redis forever.
  The new `queue` service builds from the same `docker/php/Dockerfile` as `php` and just
  runs `queue:work` instead of php-fpm.
- **The CSV is stored on the `local` (private) disk**, not `public` — unlike home-slide
  images or student photos, an import file is raw PII (names, phone numbers, addresses)
  with no reason to ever be web-accessible. `config/filesystems.php`'s `local` disk has no
  symlink into `public/`.
- **Streamed with `fgetcsv()`, inserted in chunks of 500** via `Student::insert()` (a raw
  bulk insert, not `Model::create()` in a loop) — this is the same "avoid `Model::all()`,
  design for millions of rows" principle applied to writes. Because a raw `insert()`
  bypasses Eloquent entirely, the job has to manually set `tenant_id`, `status`, and both
  timestamp columns per row — none of `BelongsToTenant`'s `creating` hook, the model's
  default-status `$attributes`, or Eloquent's auto-timestamps fire for a bulk insert.
- **Duplicate `student_code` detection is chunk-scoped, not table-scoped**: for each
  batch of 500 rows, one `whereIn('student_code', ...)` query checks which of *those* codes
  already exist, rather than loading every existing student's code into memory up front —
  correctness stays independent of how large the existing table already is. A second,
  in-memory set catches duplicates *within* the same uploaded file.
- **A malformed `BirthDate` doesn't fail the row.** It's logged as unparseable and the
  column is left `null` — losing one field is preferable to losing an otherwise-valid
  student record over a bad date format.
- **Row-level errors are capped at 100** (`ProcessStudentImport::MAX_RECORDED_ERRORS`) —
  past that, further bad rows still count toward `skipped_count` but aren't stored
  individually in the `jsonb` `errors` column. An import that bad needs its source file
  fixed, not a longer error list.
- **Photos are never imported.** A CSV has no image bytes — only whatever path or filename
  the old system used internally, which points at nothing on this platform's disks. Photos
  are added per student afterward through the normal student-update endpoint.
- **Tenant scoping inside the job** goes through
  `TenantContext::runFor($import->tenant, fn () => ...)` — a queued job gets a fresh, empty
  `TenantContext` per [docs/multi-tenancy.md](multi-tenancy.md), so the job has to enter the
  correct school explicitly rather than relying on any ambient state.

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
