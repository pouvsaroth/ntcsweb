# One project, many systems — review and plan

Goal: one codebase that serves School Management, HRM, Payroll, Inventory /
Stock / Warehouse, POS and more — each sold as its own system, a customer
able to hold several (docs/backoffice/stage-9-products.md).

## 1. Where the project stands (2026-10-21)

**Already right for many systems — keep it:**

| Piece | Status |
|---|---|
| One codebase, one console app, one login | ✅ |
| A database per customer (shared / dedicated / separate server), row-level security | ✅ |
| Modules → menus → permissions, enforced in one seam (`SchoolModules::filter`) | ✅ |
| Systems (products) built from modules; plans per system; a customer holds several | ✅ stage 9 |
| Billing, KHQR/Bakong, sign-up, back office, support chat, Telegram, approvals, notifications, audit | ✅ shared by every system |
| HRM, Payroll, Accounting, Assets, Projects | ✅ already independent of "school" |

**What still assumes "school":**

| Where | What |
|---|---|
| Code layout | By layer, not by module: 120 controllers in one folder, 146 models in one folder, one 1 000-line `routes/api.php`, one 900-line console router, one 5 000-line translation file per language. Hard to see what belongs to which system. |
| New-customer setup | Every customer gets the school's starting data (a "Teacher" position, school roles) — `SchoolProvisioner::seed()`. |
| Names | Role "School Admin", tables/classes `School*`, `tenant-settings` called "School settings", the sign-in page mark "School Management System". |
| Finance | `invoices.student_id`: invoices can only be for a student. `products` is a simple price list for the school shop (no stock). |
| Missing for retail / warehouse | Customers & suppliers as one "contacts" list, an item catalog with units and stock, warehouses, stock moves, purchase orders, a POS screen. |

## 2. Target shape — a modular monolith

Still **one** project and **one** deployment (not separate services: one team,
shared login/billing/tenancy, far simpler to run). Inside it, each module is
a folder that owns everything it needs:

```
backend/app/Modules/
  Core/        users, roles, settings, notifications, approvals, contacts, numbering
  Hrm/         staff, attendance, leave, recruitment, performance
  Payroll/
  Accounting/
  Assets/
  School/      academics, students, classes, exams, school billing
  Inventory/   items, units, warehouses, stock moves, purchasing
  Pos/         registers, shifts, sales, receipts
  Projects/ Website/ …
     each: Models/ Http/ Services/ routes.php  migrations/  permissions.php
           menu (for the console)  seeders/ (its starting data)  lang/

frontend/src/modules/<same names>/
     pages/ components/ services/ routes.ts nav.ts i18n/{en,km}.ts
```

A **module registry** (one PHP class per module) declares: its permissions,
its console menu, its routes, its starting data, its dashboard tiles. The
platform then:
- builds the console menu from the modules a customer has;
- runs only those modules' starting data when a customer is set up (no
  "Teacher" position for a shop);
- shows each system's own name and words (school vs company).

Table names stay as they are — moving code does not touch the database.

## 3. Shared building blocks new systems need (Core)

| Block | Used by | Note |
|---|---|---|
| **Contacts** (customers, suppliers) | POS, Inventory, Sales, Purchases, Accounting | `suppliers` exists (assets) — becomes a contact type |
| **Item catalog** (SKU, barcode, unit, price, cost, track stock) | Inventory, POS, Sales | today's `products` grows into it |
| **Warehouses / locations** | Inventory, POS, Assets | asset locations exist |
| **Document numbering** (INV-0001, PO-0001, R-…) | all | today each module numbers its own |
| **Payments** (cash, bank, KHQR) | POS, Sales, School billing | KHQR code exists |
| **Posting to accounting** | POS, Inventory, Payroll, Finance | Payroll already posts expenses |

## 4. Plan — one stage at a time, nothing breaks on the way

The 1 288 tests guard every step; each stage ships on its own.

| Stage | What | Visible change |
|---|---|---|
| **A. Module registry** | One class per existing module (permissions, menu, starting data, dashboard tiles). Setup seeds only the customer's modules. Neutral words in shared screens (Owner/Admin, "organization"). | Non-school customers get no school leftovers |
| **B. Core blocks** | Contacts, item catalog, warehouses, document numbering | New Settings screens |
| **C. Inventory / Warehouse** | Items & units, warehouses, stock in/out/transfer/adjust, purchase orders & receiving, stock count, low-stock alerts (Telegram), reports | New system to sell |
| **D. POS** | Registers & shifts, fast sale screen (barcode, discounts), cash / KHQR, receipt (58/80 mm printer), returns, end of day; moves stock, posts income | New system to sell |
| **E. Sales & Purchases** | Quotations, sales orders, customer invoices (not only students), purchase invoices | Completes ERP |
| **F. Move the rest** | Move existing modules into `Modules/` one at a time (pure moves, tests unchanged) | None |

Each new system is then just data in the back office: e.g. **Retail** = POS +
Inventory + Accounting; **Warehouse** = Inventory + Purchasing; **ERP / HR** =
HRM + Payroll + Accounting + Assets.

## Stage A — as built (2026-10-21)

Order from the owner: **the school system keeps working first**; groundwork
only, no change for schools.

- `backend/app/Modules/` — one class per module (`AcademicsModule`,
  `FinanceModule`, … `ProjectsModule`, extending `Module`), listed in
  `ModuleRegistry`. Each declares: code, names (en/km), menus (permission
  groups), dashboard permissions, and `seed()` — its starting data.
- `App\Platform\Billing\Modules` (used by plans, systems, the permission
  seam) now reads the registry: `catalog()`, `menuLabels()`,
  `slugModules()` replace the old constants; nothing else changed.
- Setup (`SchoolProvisioner::seed`): core seeds for everyone (roles, chart of
  accounts, base data), then `ModuleSetup::seedFor()` — only the customer's
  modules. Academics' seed is the "Teacher" position, so a shop customer no
  longer gets it. A new subscription on a running customer queues
  `EnsureModuleData`, so a module's starting data arrives when it is added.
- Tests: `tests/Feature/Platform/ModuleRegistryTest.php`.

### Adding a module (e.g. Inventory)

1. `app/Modules/Inventory/InventoryModule.php` extending `Module`: code
   `inventory`, names, `menus()` = its permission groups, `seed()` if it
   needs starting data (idempotent).
2. Add it to `ModuleRegistry::all()`.
3. Its permissions in `Permissions::catalog()`, its console menu items and
   routes — then it can be put in a system and a plan in the back office.

## 5. What to decide

- Start with **A** (groundwork), then **C** (Inventory) — recommended.
- POS: one shop per customer first, or several branches from the start?
- Receipt printer: browser print (any printer) first, direct thermal later?
