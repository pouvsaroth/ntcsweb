# Stage 5 — Billing (plans, subscriptions, payments, modules, student limit)

```
Owns      backend: app/Platform/** (billing services, jobs, controllers), routes/platform.php,
          central migrations for bo_* tables, one line in routes/console.php (billing tick),
          App\Support\Authorization\PermissionRegistry (one filter), the enrollment
          create/reactivate paths (one limit check), one console endpoint (billing banner)
          frontend: frontend/src/backoffice/** (Billing pages), one banner in the console layout
Never     the school's own invoices/payments/receipts (that is the school billing its
          students — a different thing that only shares the word)
Depends   Stages 1–4
```

Modelled on SmartGPS-V3's `backoffice/billing` (plans, subscriptions,
payments — migrations 0007, 0017, 0018, 0022, 0029), adapted to schools.

## Decisions (user, 2026-10-18)

| Question | Answer |
|---|---|
| How a school is charged | **Flat price per plan + a student limit** |
| Plans switch console modules | **Yes** |
| Subscription ends unpaid | **Warn, then suspend after a grace period** |
| Payments | **Record payments + PDF receipt** |

## Why

Today nothing in the platform says what a school pays, until when, or what it
may use. Staff track it outside the system, and an unpaid school keeps working
until someone remembers to suspend it by hand.

## Rules

- **It records, it never charges.** A payment row is money already received
  (cash, bank transfer, KHQR, cheque) with its reference. No card numbers, no
  gateway.
- **Nothing a school has today is taken away on deploy.** Every existing school
  is put on a **Legacy** plan: all modules, no student limit, no end date.
  Staff move schools to real plans when they choose.
- **History is kept.** A school collects subscription rows as it renews or
  changes plan. Plans and subscriptions with history are never hard-deleted
  (deactivated instead). A wrong payment is **voided** with a reason, never
  deleted.
- **Every write is audited** (`platform_audit_logs`), like Stages 1–4.

## Build

### 5a. Plans and subscriptions

`bo_plans`: code, name, description, **price**, **currency** (USD or KHR),
**period_months** (1, 3, 6, 12 … or empty = once, no end), **max_students**
(empty = no limit), **modules** (list of module codes, see 5c), status
(active / inactive).

`bo_school_subscriptions`: school, plan, status (**trial / active / expired /
cancelled**), start, end (empty = no end), notes, created by. At most one
**live** row (trial or active) per school — a database rule, not just code.

Screens: **Plans** (list, create, edit, deactivate) and, on each school,
a **Billing** tab: current plan, end date, days left, usage (students vs.
limit), history, and **Change plan / Renew / Cancel**.

Flags on the Schools list: **expiring** (end within 14 days), **expired**
(in grace), **suspended for billing**, **over limit**.

### 5b. Payments and receipts

`bo_payments`: school, subscription, amount, currency, **periods paid**,
method (cash / bank / KHQR / cheque / other), reference, paid on, **covered
from / covered to** (the end date before and after — so what a payment did is
readable without the audit log), receipt number, recorded by, void (by, at,
reason).

Recording a payment, in one transaction:
- extends the live subscription by `periods × period_months` — from its
  current end if paid early or on time, so no days are lost; a trial that is
  paid becomes active from the payment date;
- **lifts a billing suspension** (5e) if the school was suspended for billing
  — never a suspension staff made by hand.

Voiding keeps the row and does **not** move the end date back (staff change
it themselves if they mean to — the school may have paid another way).

**Receipt PDF** (Browsershot, already used for school invoices): number
`R-2026-0001` (yearly sequence), school, plan, period covered, amount, method,
reference, recorded by. Download from the back office. (Email is not working
yet, so no sending — download and pass it on.)

Screen: **Payments** (all schools, filter by school / date / method, voided
shown struck through) and **Record payment** on the school's Billing tab.

### 5c. Modules on plans

A **module** is a named group of console permission groups. The catalog lives
in code (`app/Platform/Billing/Modules.php`), so a new console feature is
added to a module in the same commit that adds its permissions.

**How it is enforced — one seam.** `PermissionRegistry::forUser()` and
`has()` drop every permission whose group belongs to a module the school's
live plan does not include. Because every API route already checks a
permission, and the console menu (`router/adminNav.ts`) already hides what a
user may not do, a module that is off disappears from menus **and** its API
answers 403 — with no change to the controllers. Changing a school's plan
bumps that school's permission cache version (the existing `invalidate()`).

**Core is always on** whatever the plan: dashboard, users, roles,
tenant-settings, organization, notifications, audit-logs, base-data,
currency-rates, my-requests, approval-groups, approval-requests, forms,
form-categories, form-templates (eApprovals). Self-service items that need
no permission but belong to a module (My check-in, Request leave,
Performance self-assessment → HRM; About page → Website) are hidden in the
console menu by module too.

Draft catalog (to confirm, see Open questions):

| Module | Permission groups |
|---|---|
| Academics | academic-programs, academic-years, academic-reports, programs, course-packages, classes, classrooms, buildings, students, enrollments, attendance, make-up-class-requests, exam-applications, exam-scores, examination, student-feedback |
| Finance | invoices, payments, receipts, billing-reports, products |
| Accounting | accounting, accounts, income, expense, transactions, reports |
| HRM | staff, staff-attendance, positions, leave-management, leave-requests, recruitment, resignation-requests, performance, promotions, overtime-requests, manpower-requests, attendance-corrections |
| Payroll | payroll |
| Assets | assets, my-assets |
| Library | books, book-categories |
| Website | news, events, gallery, home-slides, videos, announcements, contact-messages, downloads, documents |
| Projects | projects |

Platform super admins (`is_super_admin`) still see everything, as today.

**Menus inside modules (2026-10-20):** a plan may keep only some menus
(permission groups) of a module — `bo_plans.menus`, a list of groups; null =
every menu of its modules (all plans before this). `Modules::MENU_LABELS`
names each menu for the plan form; `SchoolModules::filter()` drops a group
that is in an included module but not in the plan's menus, so the menu
disappears from the console and its API answers 403, like a module. Saving
every menu of each module stores null (`Modules::normaliseMenus()`).

### 5d. Student limit

**Active students** = distinct students with an enrollment in
`not_started`, `active` or `exam_ready`. Counted in the school's own database
(row-level security already scopes it).

- Creating an enrollment, or moving one back into an active status, is
  refused with a clear message when it would pass the limit
  (“Your plan allows 300 active students. Contact us to upgrade.”).
  Nothing already enrolled is touched.
- From 90 % the console banner (5e) warns the owner.
- The back office shows usage on the school's Billing tab and flags
  **over limit** (possible after a downgrade — allowed, but no new
  enrollments until under).

### 5e. Expiry: warn, then suspend

Daily `billing:tick` (scheduler):
1. an active/trial subscription past its end → **expired**;
2. expired for more than **grace days** (config, default **7**) → the school
   is **suspended for billing** (Stage 2 suspend, with reason `billing`, so 5b
   can lift exactly these and nothing else). Audited as done by the system.

**Console banner**, shown to users holding `tenant-settings.update` (the
school's owner/admins):
- from 14 days before the end: “Your plan ends on 30 Nov (12 days).”
- during grace: “Your plan ended on 30 Nov. The school will be suspended on
  7 Dec unless payment is received.”
- near/over the student limit (5d).

Staff can always lift a suspension by hand (Stage 2), and can set an end date
or grace on one school directly.

### Permissions

`platform.billing.read` (see plans, subscriptions, payments, receipts) and
`platform.billing.manage` (change plans, subscribe, record/void payments).
Owner role holds both.

## Steps (one at a time, check in after each)

5a plans + subscriptions → 5b payments + receipts → 5c modules →
5d student limit → 5e expiry + banner. Back office screens are built with
each step, not at the end.

## Done when

```bash
docker exec ntscweb_php php artisan test --filter=Billing     # all billing tests
docker exec ntscweb_php php artisan test                      # full suite still green
cd frontend && npm run build:admin && npx vite build --config vite.config.backoffice.ts
```

Plus, by hand in the back office and console (headless Chrome render check):
- a new school on a plan without Payroll: no Payroll menu, `/api/v1/payroll/*` → 403;
- a school at its student limit: new enrollment refused with the message;
- a payment recorded: end date moves, receipt PDF downloads, a billing
  suspension lifts;
- `billing:tick` with a past end date: expired, then suspended after grace;
  the banner shows in between.

## Answers (user, 2026-10-18)

1. **Modules** — staff pick each plan's modules from the catalog above.
2. **Website module off** — the admin menus go **and** the school's public
   website goes offline (its public API answers 404).
3. **Receipt header** — company name, address, phone, logo and tax number,
   set by staff in **Billing settings**.
4. **Grace days** — 7, the same for every school, editable in Billing settings.
5. **Trial** — a new school starts on a **15-day trial** by default; the
   number of days is editable in Billing settings.

### Billing settings (back office)

One row (`bo_billing_settings`), edited with `platform.billing.manage`:
trial days (15), grace days (7), expiring warning days (14), and the receipt
header: company name, address, phone, email, tax number, logo.
