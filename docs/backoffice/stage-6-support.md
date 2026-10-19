# Stage 6 — Support (school health, audited "view as the school")

```
Owns      backend: app/Platform/** (support services, controllers), routes/platform.php,
          central migrations for bo_* tables, one console endpoint (enter a support
          view), one console middleware (read-only + logging while in a support view)
          frontend: frontend/src/backoffice/** (Health tab, Support views page),
          one bar in the console layout ("Support view — read-only")
Never     the school's passwords, the school's own data changed by staff, a way into a
          school that the school cannot see afterwards
Depends   Stages 1–5
```

Modelled on SmartGPS-V3's `backoffice/support` (docs/tracks/C4.md: "audited
impersonation, per-tenant health"; "Impersonation is always audited and
read-only by default").

## Why

When a school calls with "my invoices page is empty" or "my teacher can't see
the class", staff today can only ask for screenshots, or ask for the
customer's password. Asking for passwords is unsafe, and it leaves no record of
who looked at what. Staff also have no single place that says whether a school
is healthy: backups, database, sign-ins, plan and usage are spread over four
screens.

## Rules

- **Never a password.** Staff open a view of the school from the back office.
  Nobody types, resets or learns a school user's password (Stage 2's "invite,
  never set a password" rule).
- **Read-only.** In a support view every change is refused by the server
  (anything other than reading), not only hidden on screen. Signing out of the
  view is the one exception.
- **Short.** A support view ends by itself after 30 minutes (editable), or when
  staff press **End**. Ending is immediate: the next request is refused.
- **Always recorded.** Who opened it, for which school, as which user, the
  reason staff typed, when it started and ended, and every page the view
  loaded. Kept in the back office (`bo_audit_logs` + the support view's own
  page list).
- **It is not the user signing in.** A support view does not change the user's
  last sign-in, does not count as one of their devices, does not sign out their
  own sessions, and is not recorded as their login. The school's activity log
  gets its own line instead: "Support (NewTech staff) viewed as <user>:
  <reason>".
- **Only staff with the permission** (`platform.support.view`), and never for
  an archived school or one still being set up.

## Build

### 6a. School health (one tab on the school page)

A **Health** tab on each school in the back office, read-only, putting in one
place what is today spread around:

| Line | Source | Flag when |
|---|---|---|
| Status | tenant status + last status change and its reason | suspended / archived |
| Database | where it lives (Stage 4), size, pending migrations (Stage 3) | missing, or migrations pending |
| Last backup | newest good `bo_backups` row for the school, copied or not | none, older than the backup schedule expects, or copy failed |
| Plan | subscription summary (Stage 5) | expiring, expired, no plan |
| Students | active students vs. plan limit (Stage 5d) | 90% or more |
| Users | number of users, active in the last 30 days | — |
| Last sign-in | the most recent `last_login_at` among the school's users, and who | none in 14 days |
| Failed jobs | failed queue jobs for this school (count + latest message, no stack trace) | any in 7 days |

The **Schools list** gets one small dot per school: green, amber (something
flagged), red (suspended, or no recent backup). That needs no query into each
school database: everything in the dot comes from central tables (status,
backup, plan, last sign-in, failed jobs). The database and student lines are
shown only on the tab (they open the school database).

### 6b. Support view (read-only "view as the school")

1. On the school page, **Open support view**: staff pick the user to view as
   (default: the owner), and type a **reason** (required, e.g. "Ticket 214:
   invoice list empty").
2. The back office creates a `bo_support_sessions` row and a one-time ticket,
   valid for 60 seconds, and opens the console in a new tab on the school's
   address with that ticket.
3. The console exchanges the ticket for a session as that user, marked as a
   support view (the ticket cannot be used twice).
4. The console shows a red bar on every page: **"Support view by <staff name> —
   read-only — ends 14:35 — End"**.
5. While marked, the console server:
   - refuses every change (POST/PUT/PATCH/DELETE) with "Read-only support view",
     except sign-out;
   - writes each page/API path read into `bo_support_session_events` (path and
     time, no data);
   - ends the view when the time is up or the row is ended from the back office.
6. The back office lists support views: **Support** page (all schools) and a
   section on each school, with staff, user, reason, start, end, and the pages
   viewed. Staff with the permission can **End** any open view.

Tables:

```
bo_support_sessions        tenant_id, staff_id, user_id, reason, ticket_hash,
                           ticket_used_at, started_at, expires_at, ended_at,
                           ended_by (staff | timeout | user sign-out), ip
bo_support_session_events  support_session_id, method, path, status, at
```

### Permissions

```
platform.support.read     see the Health tab and the list of support views
platform.support.view     open a support view (and end any open one)
```

The Owner role gets both automatically, like every new permission.

## Steps (one at a time, check in after each)

1. **6a** Health tab + the dot on the Schools list.
2. **6b-1** Back office side: open a support view (ticket), list, end.
3. **6b-2** Console side: enter with the ticket, read-only guard, page log,
   time limit, the red bar.

## Done when

```bash
docker exec ntscweb_php php artisan test --filter=Platform
    # health: each line from its source; flags at the thresholds above
    # schools list dot: computed from central tables only
    # open a support view: reason required; archived / provisioning school refused;
    #   support.read alone cannot open one
    # ticket: works once, expires after 60 s, only on that school
    # in the view: GET works, POST/PUT/PATCH/DELETE refused (sign-out allowed)
    # every request logged as an event; time-out and End both stop the next request
    # the user's last_login_at, devices and sessions untouched; no login audit row,
    #   one "support viewed as" row in the school's activity log
cd frontend && npx vue-tsc -b && npm run build:admin && npx vite build --config vite.config.backoffice.ts
```

Then locally, for real: open a support view of newtech as the QA console user,
browse a few pages, try to save something (refused), let it time out, and see
every page in the back office list. Render-checked in headless Chrome, desktop
and phone.

## Not in this stage

- Changing anything in a school from a support view ("write mode"); owner
  decided read-only only.
- Live chat or tickets inside the system; tickets stay where they are today.
- Reading the server's error log per school (errors are not tagged by school
  today; only failed queue jobs are).

## As built — 6a (2026-10-18)

- `App\Platform\Support\SchoolHealth`: `report()` for the panel (8 lines,
  each ok / warn / bad / off), `dots()` for the list (central tables only, one
  batched query per source). Route `GET schools/{school}/health`
  (`platform.support.read`); the list's `health` field is null for staff
  without it.
- The school page has a **Health panel** (top of the right column) rather than
  a tab: the page has no tabs.
- **Failed jobs per school:** jobs carried no school until now. Every queued
  job's payload now gets `ntcs_tenant_id` (the job's own `tenantId`, else the
  school of whoever queued it; `AppServiceProvider::configureQueuePayload`).
  Failures from before this change are not counted for any school.
- **Found: sign-in times are stored in the school's own time.** Console
  requests switch the app to the school's timezone (ResolveTenant), so
  `users.last_login_at` is written as Phnom Penh time with no zone. Health
  reads it back in the school's zone. Anything else in the back office that
  shows a school user's time must do the same.
- Backup "too old" follows the backup schedule (PlatformDatabases::staleBefore),
  like the Databases screen, instead of a fixed 2 days.
- Tests: `SchoolHealthTest` (8).

## As built — 6b-1 (2026-10-19)

- Tables `bo_support_sessions`, `bo_support_session_events`, `bo_support_settings`
  (migration `2026_10_19_010000_create_bo_support_tables`). Besides the brief's
  columns: `ticket_expires_at` (60 s) and `ended_by_staff_id`.
- `App\Platform\Support\SupportViews`: `open()` checks the school (active or
  suspended only), the user (active, in that school), stores the ticket as a
  sha256 hash and returns the console address once:
  `<platform.console_url>/support-view?ticket=…&tenant=<slug>`. It writes
  `support.view_opened` to `bo_audit_logs` and a `SUPPORT_VIEW` line (module
  Auth, no user) to the school's `audit_logs`: "Support (NewTech staff) viewed
  as <user>: <reason>". `end()` writes `support.view_ended`.
- A view past `expires_at` with no `ended_at` shows as **timed out**; 6b-2 may
  stamp `ended_by = timeout` when the console sees it.
- Routes: `GET schools/{school}/support/users` (owner first, active only, 50,
  search name/email/phone), `POST schools/{school}/support/sessions`,
  `POST support/sessions/{session}/end`, `PATCH support/settings`
  (`platform.support.view`); `GET support/sessions`, `GET support/sessions/{session}`
  (with pages), `GET support/settings` (`platform.support.read`).
- Screens: **Support** menu (Support views, Settings), a **Support views**
  panel on the school page (Open view, latest 5, detail with pages, End now).
  The new tab is opened on the click and pointed at the console when the
  ticket comes back; if the browser blocks it, the dialog shows the link.
- `SUPPORT_VIEW` added to the school app's Audit log action filter.
- Tests: `SupportViewsTest` (9).

## As built — 6b-2 (2026-10-19)

- **Active schools only.** The console refuses every user of a suspended
  school (EnsureUserIsActive) and resolves no tenant for it, so a support view
  of a suspended school could not work; 6b-1 now refuses suspended too.
- `POST /api/v1/auth/support-view {ticket}` (`SupportViewController`, in the
  throttled auth group): `SupportViewSession::enter()` checks the ticket
  (hash, unused, within 60 s, view open, user active, school active, and the
  school the request names — domain or `tenant` on the shared domain — must
  be the ticket's), spends it atomically, signs out whoever was in this
  browser (their device slot freed, an earlier support view ended), then logs
  the session in as the user with `ntcs_support_session_id` in it. No
  `recordLogin`, no `user_sessions` row, no LOGIN audit row.
- `GuardSupportView` (api group, after EnsureTenantMatchesUser, and in the
  priority list before route-model binding so a refused write is never a
  404): ended / timed out / another user → signed out, 401
  `SUPPORT_VIEW_ENDED` (a timed-out row gets `ended_by = timeout`);
  `auth/logout` → `ended_by = sign_out`, no LOGOUT row for the user; not
  GET/HEAD/OPTIONS → 403 `SUPPORT_VIEW_READ_ONLY`; every request → one
  `bo_support_session_events` row (method, path without query, status).
- **Timezones:** console requests run in the school's zone, back office rows
  are UTC with no zone in the column; every support-view read/write in the
  console goes through `SupportViewSession::inUtc()`.
- `/auth/me` meta `support_view` {id, staff, expires_at}. Console: page
  `/support-view` (enters, then a full reload to `/admin`; `?ended=1` is the
  "Support view ended" page), red `SupportViewBar` at the top of AdminLayout
  with End, a timer that leaves when time is up, and an http interceptor that
  sends any `SUPPORT_VIEW_ENDED` there. Logout skips the push-unsubscribe.
- Tests: `SupportViewConsoleTest` (7). Checked for real locally: opened a
  view of newtech as qa-verify, browsed, a General Settings save was refused
  (403), 38 requests listed in the back office, End and a time-out each sent
  the console to the ended page, qa-verify's last sign-in unchanged.

## Decisions (owner, 2026-10-18)

| Question | Answer |
|---|---|
| Whose eyes | **Any user of the school, owner preselected** |
| Write mode | **Read-only only.** No write mode in this stage |
| Does the school see it | **Yes: a line in the school's own activity log**, "Support (NewTech staff) viewed as <user>: <reason>", written when the view opens |
| How long | **30 minutes, editable** in a one-row `bo_support_settings` (Support page → Settings) |
