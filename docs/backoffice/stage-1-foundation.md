# Stage 1 — Foundation

```
Owns      backend: app/Platform/**, routes/platform.php, central migrations for platform_*
          frontend: frontend/backoffice/index.html, frontend/src/backoffice/**, vite.config.backoffice.ts
Never     a school's tenant migrations, school controllers, the school admin app
Depends   nothing
```

## Build

### Data (central database)

| Table | Columns that matter |
|---|---|
| `bo_staff` | name, email (unique), password, `status` (active / disabled), `must_change_password`, `two_factor_secret` (encrypted), `two_factor_confirmed_at`, `two_factor_recovery_codes` (encrypted, hashed), `last_login_at`, `last_login_ip` |
| `bo_roles` | name, description, `permissions` (json list of `platform.*` keys) |
| `bo_role_staff` | role ↔ staff |
| `bo_audit_logs` | staff_id, action, `target_type`/`target_id`, `tenant_id` (nullable — which school it touched), old/new (json), ip, user_agent, created_at. Append-only. |

The permission catalogue lives in code (`config/platform.php`), English and
Khmer labels, grouped as a tree. Stage 1 keys:

```
platform.staff.read     platform.staff.write
platform.role.read      platform.role.write
platform.audit.read
```

Later stages add `platform.school.*`, `platform.data.*`, `platform.billing.*`.
A built-in **Owner** role holds every key and cannot be deleted or emptied.

### Sign-in (`/api/v1/platform/auth`)

```
POST login            email + password      → { two_factor: 'required' | 'setup' }   (nothing signed in yet)
POST two-factor       code or recovery code → signed in (staff guard, session cookie)
POST two-factor/setup returns secret + otpauth URI for the QR, confirm with one code → recovery codes shown once
GET  session          → { staff, permissions, must_change_password }
POST logout
PUT  password         current + new
```

- Separate `staff` guard (session driver, `bo_staff` provider).
  Sanctum tokens and school sessions never satisfy it.
- Rate limited per email + IP; failure looks the same whether or not the
  email exists.
- TOTP per RFC 6238 (30 s, 6 digits, ±1 step), implemented in-house (~50 lines, no new
  package), tested against the RFC vectors. A used code cannot be replayed.
- First sign-in after an account is created: must enrol 2FA and change the
  temporary password before any other route answers (server refuses, not just
  the UI).

### Guard

`EnsureStaffPermission` middleware on every `/api/v1/platform/*` route except
the sign-in steps:

```
no staff session / disabled account          → 401
route names no platform.* permission         → 404
staff lacks the permission                   → 404 (and the denial is audited)
must change password / no 2FA yet            → 409 with a code the app routes on
```

Routes sit in their own file, `routes/platform.php`, outside the tenant
middleware (`ResolveTenant` / `EnsureTenantMatchesUser` never run on them —
staff are not inside a school).

### Screens (the new app, `frontend/src/backoffice/`)

- Sign in → code (or set up 2FA with QR) → forced password change if needed
- Shell: sidebar with only the areas the staff member's permissions open;
  English / Khmer
- **Staff** — list (cards on phone), add (temporary password shown once),
  disable, reset 2FA, assign roles
- **Roles** — list, the permission tree editor, copy a role
- **Audit** — filter by staff, action, school, date
- **My account** — change password, 2FA status, regenerate recovery codes

Dev server on port 5301, proxying `/api` to `localhost:8080` like the admin
app does on 5300.

### As built (backend)

- Code: `app/Platform/**` (models, `StaffSignIn`, `Totp`, `PlatformAudit`,
  middleware, controllers), `routes/platform.php`, `config/platform.php`,
  migration `2026_10_16_010000_create_platform_staff_tables`.
- Routes are mounted in `bootstrap/app.php` (`then:`) under the `web` group.
  A browser client calls `GET /sanctum/csrf-cookie` first and sends
  `X-XSRF-TOKEN`, as the school SPA does.
- Extra beyond the brief: `POST staff/{id}/reset-password` (temporary
  password, shown once) — the forgotten-password path for staff.
- `AuthenticateStaff` implements `AuthenticatesRequests` so it runs before
  route-model binding; without that `staff/{id}` answered 404 vs 401
  depending on whether the id existed.
- Tests: `tests/Unit/Platform/TotpTest.php`,
  `tests/Feature/Platform/PlatformSignInTest.php`,
  `tests/Feature/Platform/PlatformAccessTest.php`.

### As built (screens)

- `frontend/src/backoffice/` (own `api.ts` client on `/api/v1/platform`,
  pinia session, router guard, `ShellLayout`, pages), entry
  `frontend/backoffice/index.html`, `vite.config.backoffice.ts` (port 5301,
  output `dist/backoffice`). `npm run dev:backoffice` / `build:backoffice`;
  `npm run build` now builds it too.
- English + Khmer; shared UI components reuse the school app's `common.*`
  strings. Navy frame so it is never mistaken for a school's admin.
- Render-checked in headless Chrome (sign-in → QR enrolment → recovery
  codes → forced password change → every page, desktop and phone): no
  console errors beyond the expected signed-out 401 on `auth/session`.
- Tables are `bo_` prefixed (owner's decision, 2026-10-16): `bo_staff`,
  `bo_roles`, `bo_role_staff`, `bo_audit_logs`.

### CLI

`php artisan platform:create-staff {email} {name}` — the first Owner. Prints a
temporary password once; 2FA and a new password are forced at first sign-in.
This is the only way to create staff without already being staff.

## Not in this stage

- Retiring `is_super_admin` — it keeps working, permanently (owner's decision, Stage 2 brief).
- Anything that touches a school (Stage 2 onward).

## Done when

```bash
docker exec ntscweb_php php artisan test --filter=Platform
    # sign-in + 2FA happy path; wrong code; replayed code; recovery code single-use
    # forced 2FA enrolment + password change block every other route
    # isolation: a school session and a school Sanctum token get 401 on every platform route
    # every platform route names a platform.* permission (route-list test), missing → 404
    # every staff write leaves a bo_audit_logs row
    # TOTP matches the RFC 6238 test vectors
cd frontend && npm run build:backoffice     # vue-tsc -b + vite build, as CI runs it
```

Then the pages rendered in headless Chrome with a clean console (sign-in,
2FA, staff, roles, audit), using a dedicated local test staff account.
