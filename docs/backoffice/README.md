# Back office — the platform's own administration

The back office is where **we** (the company running NTCSWEB) manage every
school. It is not a school's admin panel and it is not a "super admin" view of
one. Modelled on SmartGPS-V3's `apps/backoffice` (track C4 there).

Why it exists: today a school is created, given a database and fixed by
running artisan commands on the server, and the platform admin is an
`is_super_admin` flag on an ordinary school `users` row that signs in through
the same login as everyone else. A bug in the school UI is one step from
every school. The back office gives platform work its own accounts, its own
sign-in, its own routes and its own app.

## Rules (every stage)

1. **Staff are not users.** Platform staff live in `bo_staff` in the
   central database. A school user can never become staff, and a staff
   account can never sign in to a school.
2. **Own sign-in, with 2FA.** Password, then a TOTP code. Required, not
   optional.
3. **Own guard, own routes.** Everything is under `/api/v1/platform/*` behind
   the `staff` guard. A school session or Sanctum token gets **401** there.
4. **Deny by default, 404 not 403.** Every platform route names a
   `platform.*` permission. Missing it → 404, so the route's existence is not
   confirmed.
5. **Every staff write is audited** in `bo_audit_logs` (who, what, which
   school, from which IP). Never in a school's own `audit_logs`.
6. **Invite, never set a password.** Staff create one owner per school and
   send a signed, expiring link. The owner invites everyone else.
7. **Own app, own deployment.** A separate Vite entry, served on the central
   admin host (`admin.ntcsweb.com`), so it can sit behind an IP allowlist.
8. **Local first.** Nothing in this plan runs against the production
   database. Moving it to the server is the owner's step.

## Stages

| # | Stage | Brief | Status |
|---|---|---|---|
| 1 | Foundation — staff accounts, sign-in + 2FA, guard, permissions, audit, the app shell | [stage-1-foundation.md](stage-1-foundation.md) | ✅ Built locally — backend (33 tests) + screens; not committed |
| 2 | Schools — create, background provisioning with status + retry, suspend, invite the owner, domains | [stage-2-schools.md](stage-2-schools.md) | ✅ Built locally — backend (11 tests) + screens; not committed |
| 3 | Data ops — backups per school database, migrate all schools, health | [stage-3-data-ops.md](stage-3-data-ops.md) | ✅ Built locally — backend (14 tests) + screens; not committed |
| 4 | Billing — plans and subscriptions per school | — | Not started |
| 5 | Support — per-school health, audited read-only impersonation | — | Not started |

Each stage gets its brief before any code, and a "done when" that is a list
of commands.

## The central database (`bo_school` locally)

The back office's tables live in the central database next to `tenants`,
`tenant_domains` and `users`. On 2026-10-16 the 70 school tables left behind
there from before the move to a database per school (`students`,
`invoices`, …, all empty) were dropped from the local `bo_school`; a dump
from before is kept outside the repo. The central database now holds only
what is central. Consequence: the old `is_super_admin` "platform mode" can
no longer read school models with no school selected. (`is_super_admin`
itself stays permanently — owner's decision, see stage-2-schools.md.) The same clean-up on the server is the owner's step.

## Database names

`bo_school` is the central (platform) database; each school has its own
`tn_<slug>` (e.g. `tn_newtech`). The code was switched from `tenant_<slug>`
to `tn_<slug>` on 2026-10-16 (`TenancyServiceProvider`). Production still
uses `tenant_*` names and must be renamed before this code is deployed.
