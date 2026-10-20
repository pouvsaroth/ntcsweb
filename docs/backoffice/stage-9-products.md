# Stage 9 — Many systems (products), not only the school system

The back office sells more than the School Management System: an ERP/HR
system, later SmartGPS, and others not decided yet. All of them are built
inside this codebase (one console app, one login, a database per customer).

## Decisions (user, 2026-10-20)

| Question | Answer |
|---|---|
| Where the other systems live | **Inside this codebase** |
| One customer, several systems | **Yes** — one customer, one subscription per system |
| Systems to sell | School Management, ERP / HR, SmartGPS, more later — **configurable** |

## Today (what assumes "school")

- `tenants` are schools: back office menu **Schools**, school database, owner = School Admin.
- **Plans** pick modules/menus from one code catalog (`Modules::CATALOG`).
- **One live subscription per tenant** (a database rule).
- **Sign-up** and the website **plans page** sell one set of plans and create a school.
- The console's words, dashboard and menu are the school's.

Reusable as is: payments, receipts, KHQR/Bakong, trial, expiry/suspension,
provisioning, audit, support view, module/menu enforcement (one seam).

## Idea

A **system (product)** = a name and logo + the modules it is built from.
Modules stay in code (they are features); which modules make a system, and
how it is sold, is data edited in the back office.

| System | Modules (from the existing catalog) |
|---|---|
| School Management | Academics, Finance, Accounting, HRM, Payroll, Assets, Library, Website, Projects |
| ERP / HR | HRM, Payroll, Accounting, Assets, Projects (+ Finance) |
| SmartGPS | new modules, built later |

A **customer** may hold one subscription per system. What a customer may
use = every module/menu of all its live subscriptions together.

## Steps (one at a time, check in after each)

### 9a. Systems and plans per system
- `bo_products`: code, name (en/km), logo, description, modules it may use,
  status, order. Seeded: **School Management** (every current module) and
  **ERP / HR**.
- Plans get a system; the plan form only offers that system's modules/menus.
  Every existing plan → School Management.
- Back office: **Billing → Systems** (list, add, edit). New systems later
  need only data, unless they need new modules (code).

### 9b. Customers with several systems
- One live subscription **per customer per system** (the database rule changes).
- Access = union of all live subscriptions' modules/menus (`SchoolModules`).
- Back office: **Schools** becomes **Customers** (a school is a customer of
  School Management); the customer page lists its systems, each with its
  plan, payments, expiry; "Add system".
- Expiry/suspension per subscription: an expired system goes away; the
  customer is suspended only when nothing live is left.

### 9c. Website and sign-up per system
- Services card kind **plans** picks a system → `/plans/{system}`.
- Sign-up: `/signup?system=…&plan=…`; wording per system ("school" vs
  "company"); creates the customer + that subscription (paid by KHQR as 8c).
- An existing customer adding a second system: from the back office first.

### 9d. Console follows the customer's systems
- Words, dashboard and the console's name follow what the customer has
  (an ERP/HR-only customer never sees "students" or "school").
- Larger; scoped after 9a–9c.

## Not in this stage
- Building SmartGPS itself (new modules) — its own project, then added as a system.
