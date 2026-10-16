# Stage 2 — Schools

```
Owns      backend: app/Platform/** (Schools controllers, provisioning job + service),
          routes/platform.php, central migrations for bo_* tables
          frontend: frontend/src/backoffice/** (Schools pages)
Touches   (narrowly, each named below) Tenant model statuses, the invite mail,
          TenancyServiceProvider (already done: tn_<slug>)
Never     a school's tenant migrations, the school admin app's pages
Depends   Stage 1
```

Modelled on SmartGPS-V3's `docs/tracks/tenant-provisioning.md`, cut down to
what NTCSWEB needs: every school already gets **its own database**
(`tn_<slug>`), so there is no shared/dedicated choice to make.

## Why

Today a school is born by hand: a row in `tenants`, then
`tenants:provision-database`, then the seeders, then someone creates the
first admin and tells them a password. Nothing records what happened, a
half-finished school is invisible, and the platform's staff set school
passwords. Stage 2 makes it one dialog, a background job you can watch, and
an invite the owner accepts themselves.

## Build

### 1. School list — `Schools`

Name (Khmer + English), slug, status, owner (and whether they have accepted),
domains, created. Search, status filter, cards on phone. Opening a school
shows its detail page: profile, status with history, provisioning log,
owner, domains.

### 2. Create a school

One dialog: **name (Khmer), name (English), slug, owner's name, owner's
email**, plus timezone / language / currency defaulted to `Asia/Phnom_Penh`
/ `km` / `USD`.

Slug rules (the slug becomes the database name and the subdomain, so it can
never change after creation — no route edits it):

- `^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$`
- not reserved: `admin`, `api`, `www`, `erp`, `app`, `mail`, `static`,
  `login`, `bo`, `platform`, `test`… (list in `config/platform.php`)
- not used by any school, archived ones included
- no database named `tn_<slug>` already on the server

Saving creates the `tenants` row with status **`provisioning`** and queues
the job. It does not wait for it.

### 3. Provisioning — a queued job, never inside the request

Each step is idempotent and writes a row to `bo_school_provisioning_logs`
(step, ok/failed, message, duration):

1. Create database `tn_<slug>` — skipped if it exists
2. Run the tenant migrations into it
3. Seed the school's defaults: system roles (School Admin, Teacher, Staff,
   Student) with their default permissions, chart of accounts, base data,
   teacher positions — the same seeders the deploy runs, for this one school
4. Health check: connect to `tn_<slug>`, read back the seeded roles and
   accounts
5. Status → **`active`**, `activated_at` set
6. Create the owner (`users`, status `invited`, School Admin role) and send
   the invite

A step that throws stops the job: status → **`failed`** with the message
shown on the school's page and a **Retry** button. Retry resumes — earlier
steps see their work already done and pass straight through.

```
provisioning ──ok──▶ active ⇄ suspended
     │                 ▲          │
     └─error─▶ failed ─┘(retry)   └──▶ archived
```

The existing resolvers already refuse any school that is not `active`, so a
school mid-provisioning or failed answers 404 on its site and its users
cannot sign in — no new check needed, only a test proving it.

### 4. The owner invite

- Staff type an email; **the system sends a link**; the owner sets their own
  password. Staff never see or set a school password.
- The link uses the existing set-password page (`/reset-password`) with a
  token that lasts **7 days** (a separate password broker, `invites`, so the
  normal 60-minute reset is unchanged).
- On the school's page: owner status (invited / active), **Resend invite**
  (new token, old one dead), and **Change owner** (invite a different email;
  the previous owner keeps their account but loses nothing automatically —
  that is the school's own business).
- Locally mail goes to the log (`MAIL_MAILER=log`), so the link is read from
  `storage/logs`.

### 5. Suspend, reactivate, archive

- **Suspend** (reason required): the school's users are signed out on their
  next request and its site stops answering. Data untouched.
- **Reactivate**: back to `active`.
- **Archive**: off the list by default, slug stays reserved forever, **the
  database is kept** — deleting a school's data is not a button in this
  stage.

Every one of these is a `bo_audit_logs` row with the school's `tenant_id`.

### 6. Domains

- `<slug>.ntcsweb.com` works the moment the school is active (already true
  in `DomainTenantResolver`).
- **Add a custom domain** → saved unverified with a token. The page shows
  the DNS records to create: `CNAME <host> → <platform host>` and
  `TXT _ntcsweb-verify.<host> = ntcsweb-verify=<token>`. **Check now** looks
  the TXT record up; found → `verified_at` set and the domain starts
  resolving. Remove a domain; mark one primary.
- No automatic polling and no TLS automation in this stage — the nginx/TLS
  side stays a server step, listed on the page as a reminder.

### 7. Permissions and tables

```
platform.school.read     list and open schools
platform.school.write    create, retry, edit profile, owner invite, domains
platform.school.status   suspend, reactivate, archive
```

New central tables: `bo_schools` (owner, creator, failure detail,
attempts, activated / invite-sent times — one per school, created lazily
for older schools), `bo_school_provisioning_logs`, and
`bo_school_status_history` (from, to, reason, staff, when). The school
itself stays the existing `tenants` row with two new statuses
(`provisioning`, `failed`); `tenant_domains` gains `verification_token`.

### 8. A command for the deploy

`php artisan platform:provision-pending` — re-queues every school stuck in
`provisioning` (e.g. the queue worker restarted mid-job). Safe to run any
time because every step is idempotent.

## As built (backend)

- Code: `app/Platform/Services/{SchoolProvisioner,SchoolDatabase,SchoolStatus,SchoolOwners}.php`,
  `app/Platform/Jobs/ProvisionSchool.php`, `app/Platform/Http/Controllers/School*Controller.php`,
  `app/Platform/Support/DnsTxtLookup.php`, `app/Notifications/Auth/SchoolOwnerInviteNotification.php`,
  migration `2026_10_16_020000_create_bo_school_tables`, command `platform:provision-pending`.
- Touched outside the back office, narrowly:
  - seeders: a public one-school entry point each (`seedTenant` / `seedForTenant` / `ensureTeacherPosition`);
  - `PasswordResetService`: `issueInvite()`, invite links last `platform.invite_days` while the
    account is `invited`, and accepting one makes it `active`;
  - `DomainTenantResolver`: skips a domain that has a token and no `verified_at`;
  - `AuditLogger` (the schools' audit): only a school `User` is an actor — inside a back-office
    request the signed-in account is staff, whose id broke `audit_logs.user_id`'s foreign key
    and aborted the transaction. Found by the tests.
- Tests: `tests/Feature/Platform/PlatformSchoolsTest.php` (11), with `tests/Concerns/FakeSchoolDatabase.php`
  so the suite never creates real databases.
- Proven locally for real: created **demo** through the API → `tn_demo` created, migrated, seeded,
  health-checked (~29 s), active; invite link read from the mail log; owner set a password and
  signed in with School Admin.
- After deploying new job classes, the queue worker must restart (`php artisan queue:restart`).

## As built (screens)

- `frontend/src/backoffice/pages/{SchoolsPage,SchoolDetailPage}.vue`, components
  `SchoolCreateModal`, `SchoolSetupPanel` (live steps, polls every 2 s while setting up),
  `SchoolProfileForm`, `SchoolOwnerPanel`, `SchoolDomainsPanel`, `ReasonModal`, `SchoolStatusBadge`.
  Schools is the first menu item. Cards on phone, English + Khmer.
- The school app's `/reset-password` shows "Welcome — set your password" when the link carries
  `invite=1` (all five locales); an ordinary reset is unchanged.
- Render-checked in headless Chrome: create through the dialog (slug suggested from the English
  name) → setup steps tick live → active + owner invited; add a domain → DNS records → Check now;
  suspend with a reason → reactivate; history; phone; Khmer. No console errors beyond the expected
  signed-out 401. Local schools created along the way: `demo`, `sample-school` (`tn_demo`,
  `tn_sample-school`).

## Added on request: copy the invite link (2026-10-16)

Email is not available yet, so the owner panel has **Copy invite link**: a fresh
set-password link to send by Telegram, SMS or chat. It is not emailed, it
lasts the same 7 days, and — because tokens are stored hashed — it replaces
any earlier link (including an emailed one); an old link can never be shown
again. Audited as `school.owner_invite_link_copied`. The link's address comes
from `App\Platform\Support\InviteLink`: the school's own address in
production, the local front end (`APP_FRONTEND_URL`, port 5299) on a local
machine. Endpoint `POST /schools/{id}/owner/invite-link`; test in
`PlatformSchoolsTest`; proven end to end locally (copy → open → set password →
owner signs in).

## Not in this stage

- Retiring `is_super_admin` — not planned at all (decision below).
- Deleting a school's database, moving data between servers, backups per
  school (Stage 3).
- Billing, plans, trial limits (Stage 4). `trial_ends_at` is left alone.
- Impersonation / "open this school as support" (Stage 5).

## Done when

```bash
docker exec ntscweb_php php artisan test --filter=Platform
    # create → job runs → tn_<slug> exists, migrated, seeded, health-checked → active
    # a failing step → failed with the message; retry resumes and finishes
    # slug: regex, reserved word, taken, existing database — all refused; no route changes a slug
    # provisioning / failed / suspended / archived school: site 404, users cannot sign in
    # owner invite: invited user with School Admin; link sets the password once; resend kills the old link
    # suspend / reactivate / archive each write status history + audit
    # domain: add → unverified (not resolving) → verify via TXT → resolving
    # every new route names platform.school.*; school staff get 401
cd frontend && npm run build
```

Then, locally: provision the existing **test** school (it has no database
yet) from the back office, accept its owner invite from the log, sign in as
that owner — all without touching the database by hand. Render-checked in
headless Chrome, desktop and phone.

## Production note

The code now names school databases `tn_<slug>`. Production still has
`tenant_newtech` (and any other `tenant_*`). **Before this code is ever
deployed**, the server's databases must be renamed to `tn_*` — the owner's
step, like the `bo_school` rename. Nothing here is committed until the
owner says so.

## Decided: `is_super_admin` stays

Owner's decision (2026-10-16): the school app's `is_super_admin` users keep
working permanently, alongside the back office. The back office is where
schools are created and managed; super admin remains a way to act inside a
school from the school app. Neither replaces the other.
