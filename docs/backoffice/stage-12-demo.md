# Stage 12 — Try the demo from the website

A visitor on newtechkh.com presses **Try the demo** on the Plans page, types
their name (phone and email optional), picks a role — School Director,
Teacher or Student — and lands in the demo school already signed in.

## Decisions (owner, 2026-10-22)

| Question | Answer |
|---|---|
| Which account | **Its own account per visitor** in the demo school (no shared logins) |
| Where the button is | **The Plans page** (`/plans`) |
| How long it works | **7 days**, then the account is switched off; the record stays |
| Where it is recorded | **Back office** (`bo_demo_visits`, a Demos list) and **`tn_demo`** (the visitor's staff or student record) |

## How it works

- The demo school is `platform.demo_school` (env `PLATFORM_DEMO_SCHOOL`,
  default `demo`); its database is `tn_demo`.
- `POST /api/v1/public/demo` (website school only, throttled, honeypot):
  name, phone?, email?, role (`director` | `teacher` | `student`).
  Inside the demo school it creates:
  - a user (login `demo-<random>@demo.local`, random password nobody sees,
    active) with the role School Admin / Teacher / Student;
  - Director/Teacher → a `staff` row (position *School Director* or
    *Teacher*, made if missing); Student → a `students` row.
  Centrally: a `bo_demo_visits` row (name, phone, email, role, user, staff or
  student id, IP, browser, expires in 7 days) and a `demo.started` audit row.
  The reply has a one-time sign-in address (2-minute ticket) and the visit's
  token.
- Console `/demo?ticket=…&tenant=demo` → `POST /auth/demo` swaps the ticket
  for an ordinary signed-in session as that user (last login, device slot,
  LOGIN audit like any sign-in), then opens `/admin`.
- Coming back within 7 days: the website remembers the visit's token and
  offers **Continue my demo** → `POST /public/demo/{token}/enter` → a new
  ticket for the same account.
- `platform:demos-tick` (hourly) switches off accounts past 7 days.
- The visitor's phone/email are kept as contact details only; they are never
  the login, so the same person can try every role.

## Steps (one at a time, check in after each)

1. **12a** Server: config, `bo_demo_visits`, public start/continue, console
   ticket sign-in, the hourly tick, back office list endpoint, tests.
2. **12b** Website: **Try the demo** on `/plans` (form in a dialog, Khmer and
   English, phone layout), the console's `/demo` landing page.
3. **12c** Back office: **Demos** list (who, contact, role, when, last
   sign-in, active/ended), phone cards, Khmer and English.

## As built — 12a (2026-10-22)

- Config `platform.demo_school` / `platform.demo_days`; table `bo_demo_visits`
  (`App\Platform\Models\DemoVisit`); service `App\Platform\Demo\Demos`
  (start, resume, enter, endExpired).
- Public: `POST public/demo` (throttle `demos`: 10/hour/IP; honeypot
  `website`), `POST public/demo/{token}/enter`. Console: `POST auth/demo`
  (in the `throttle:auth` group). Back office: `GET
  platform/billing/demos` (`platform.billing.read`; `filter[role]`,
  `state=live|ended`, search name/phone/email).
- Director → School Admin role + *School Director* position (made on first
  use); Teacher → the school's Teacher position; Student → student record.
- `platform:demos-tick` hourly: past 7 days → `ended_at`, user suspended,
  `demo.ended` audit.
- Tests: `tests/Feature/Platform/DemoTest.php` (7). Checked locally against
  `tn_demo`: one demo per role, rows in `bo_demo_visits` and `tn_demo`
  (staff ×2, student ×1), ticket → session → `/auth/me` as the visitor.
- No separate demo table inside `tn_demo`: it would be created in every
  school's database. The visitor's record there is their staff/student row.

## As built — 12b (2026-10-22)

- `/plans`: **Try the demo** under the subtitle (school system only, not on
  `/plans/{other system}`), opens `components/public/DemoDialog.vue`: name,
  phone and email (optional), three role cards with a one-line hint, a
  honeypot. Success → the browser goes to `sign_in_url`.
- The browser keeps the last demo (`localStorage` `ntcs_demo`: token, name,
  role, expiry) → the dialog offers **Continue my demo** / start a new one;
  an ended demo is forgotten and the form shown.
- Console `/demo` (`pages/auth/DemoEnter.vue`, like the support view page):
  `POST /auth/demo`, then a full reload into `/admin`, where the router sends
  each role to its own home (a student to the student tiles).
- Text in all five languages (`demo.*`).
- Locally the console must be served on 5299 (the only stateful port in
  `SANCTUM_STATEFUL_DOMAINS`, and `PLATFORM_CONSOLE_URL`): console
  `vite --config vite.config.admin.ts --port 5299`, website `vite --port 5300`.
- Render-checked in headless Chrome: desktop as Teacher, phone as Student,
  Khmer dialog on phone; signed in as the visitor, Continue offered after.
  No page errors. (A teacher's dashboard gets two 403s from the income and
  expense boxes — the Teacher role has no finance permission; not demo-specific.)

## As built — 12c (2026-10-22)

- Back office → Billing → **Demos** (`/billing/demos`,
  `pages/billing/DemosTab.vue`, `demosApi` in `backoffice/api.ts`): started,
  visitor (+ IP), phone/email, role, last sign-in + count, Active until /
  Ended on. Search name/phone/email; filters role and state
  (`filter[state]=live|ended`). Phone cards; English and Khmer.
- Needs `platform.billing.read` (as Sign-ups); read-only, no actions.
- Render-checked in headless Chrome as qa-backoffice: desktop table, role
  filter, phone cards with no sideways scroll, no page errors.

## Done when

```bash
docker exec ntscweb_php php artisan test --filter=Demo
cd frontend && npx vue-tsc -b && npm run build
```

Then locally: try each role from /plans, land signed in, see the row in the
back office and the staff/student row in `tn_demo`. Render-checked in
headless Chrome, desktop and phone.
