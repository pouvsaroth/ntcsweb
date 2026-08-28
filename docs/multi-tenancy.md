# Multi-tenancy

Shared database, shared schema, every tenant-owned row carries a `tenant_id`. No
database-per-tenant, no schema-per-tenant. This document covers how a tenant is resolved,
how isolation is enforced, and what a new tenant-owned table needs.

## Resolution: how a request gets a tenant

`App\Http\Middleware\ResolveTenant` runs on every API request (registered in
`bootstrap/app.php`, appended to the `api` group) and asks
`App\Support\Tenancy\TenantResolverChain` to try each resolver in
`config('tenancy.resolvers')`, in order, stopping at the first hit:

1. **`DomainTenantResolver`** — the request hostname. Either an exact match in
   `tenant_domains` (custom domains, e.g. `school.example.edu.kh`), or a single-label
   subdomain of `TENANCY_ROOT_DOMAIN` matched against `tenants.slug`
   (`newtech.ntcsweb.com` → tenant with slug `newtech`, no extra row needed). Cached by
   hostname (`config('tenancy.cache')`), invalidated on save/delete of `Tenant` or
   `TenantDomain`.
2. **`RequestTenantResolver`** — an explicit `X-Tenant: <slug>` header or `?tenant=`
   parameter, honoured **only on a central domain** (`TENANCY_CENTRAL_DOMAINS`). This is
   what makes `http://localhost:8080` usable with no DNS at all. It is not a security
   hole — see "Why the header resolver is safe" below.
3. **`AuthenticatedUserTenantResolver`** — falls back to the signed-in user's own
   `tenant_id`. The only resolver whose answer is inherently trustworthy, since it reads
   from the `users` table, not from the request.

If none resolve a tenant and the authenticated user is a platform Super Admin,
`ResolveTenant` puts the context into **platform mode** instead (see below). Otherwise the
context stays empty, and the first tenant-scoped query later in the request throws
`TenantNotResolvedException` — the system fails closed, not open.

### Why the header resolver is safe

Naming a tenant via `X-Tenant` only decides which tenant the *request* claims. It does not
grant access to that tenant's data. `EnsureTenantMatchesUser` (runs right after
`ResolveTenant`) rejects any authenticated non-super-admin user whose own `tenant_id`
doesn't match the resolved tenant, with a `403 TENANT_MISMATCH` — before any tenant-scoped
query runs. A Tenant A user sending `X-Tenant: tenant-b` gets refused, not Tenant B's data.

### Adding subdomain / custom domain support later

Nothing changes. `DomainTenantResolver` already handles both cases; a school gets a
subdomain automatically from its slug, and a custom domain is added by inserting a row
into `tenant_domains` with `type = 'custom'` and a `verified_at` once DNS ownership is
proven (verification flow itself is a future admin feature, not yet built).

## `TenantContext`: the single source of truth

`App\Support\Tenancy\TenantContext` is a request-scoped singleton. Nothing downstream —
global query scopes, cache keys, file paths, policies — reads the tenant from the request;
everything reads it from here. Three states:

- **Unresolved** (default) — every tenant-scoped query throws.
- **Tenant** — scoped to one school.
- **Platform** — scoping lifted entirely. Only entered by `ResolveTenant` for an
  authenticated Super Admin, or explicitly via `TenantContext::withoutTenancy()` for
  trusted internal code (seeders, cross-tenant reports). Every call site of
  `withoutTenancy()` is a deliberate cross-tenant operation and should be treated as
  security-sensitive in review.

Queued jobs get a fresh, empty context on every job (`TenancyServiceProvider` resets it on
`JobProcessing`/`JobProcessed`/`JobFailed`) — a long-lived worker process must never let
one tenant's job accidentally inherit the previous job's context. A job that needs a
specific tenant enters it explicitly with `TenantContext::runFor($tenant, fn () => ...)`.

## Enforcement: `BelongsToTenant`

Applied to tenant-owned models (`App\Models\Concerns\BelongsToTenant`). Three guarantees,
none of which depend on the controller cooperating:

- **Read** — a global scope (`TenantScope`) pins every query to
  `TenantContext::id()`. `Model::withoutGlobalScope(TenantScope::class)` or the
  `acrossTenants()` / `forTenant()` scopes are the only ways around it, and every call site
  is a deliberate cross-tenant read.
- **Create** — `tenant_id` is stamped from context on the `creating` event, never taken
  from mass-assigned input. `tenant_id` must **never** appear in a tenant-owned model's
  `$fillable` — it is set here, not by a controller.
- **Update** — the `updating` event refuses to persist a row whose `tenant_id` doesn't
  match the current context, and refuses to *change* `tenant_id` at all. A row cannot be
  moved between schools, and a row that was somehow loaded across the boundary cannot be
  saved.

> **Why `creating`/`updating`, not `saving`:** Eloquent fires `saving` *before*
> `creating` on a new record (the actual order is `saving` → `performInsert` →
> `creating`). A single `saving` hook checking "does `tenant_id` match context" would see
> the column still unset on every single create and misfire. Use the two separate events.

### `User` and `Role` are the deliberate exceptions

Neither uses `BelongsToTenant`. Both need to be readable **before** any tenant exists
(during authentication) and need to represent platform accounts/roles with
`tenant_id IS NULL` — which the trait's fail-closed scope would reject. Their tenant
column is excluded from `$fillable` for the same reason as the trait enforces, but scoping
is explicit: `User::scopeInTenant()` / `Role::scopeVisibleTo()`, and always double-checked
by `EnsureTenantMatchesUser` at the HTTP layer.

`Role.tenant_id` and `Role.is_system` are *also* kept out of `$fillable` on purpose — a
future "create role" endpoint must never let request input decide either. Seeders and
services that need to set them use `forceFill()` explicitly (see
`RolePermissionSeeder::putRole()`), the same pattern `UserFactory::forTenant()` uses.

## Adding a new tenant-owned table

1. Migration: `tenant_id` as `foreignId()->constrained('tenants')->cascadeOnDelete()`
   (or `restrictOnDelete()` if the data must outlive tenant deletion — decide per table).
   Add composite indexes matching real query patterns, always leading with `tenant_id`
   (`(tenant_id, id)`, `(tenant_id, created_at)`, `(tenant_id, status)`, ...).
2. Model: `use BelongsToTenant;`. Do **not** put `tenant_id` in `$fillable`.
3. That's it — reads, creates, and updates are all covered by the trait. No controller
   code needs to filter by tenant manually.

## Platform vs. tenant users

- **Super Admin**: `tenant_id IS NULL` **and** holds the platform `super-admin` role
  (`User::isSuperAdmin()` checks both — either alone is not enough: a half-created NULL
  account without the role must not escape scoping, and a tenant creating its own
  "super-admin"-named role must not reach across tenants).
- **Tenant user**: `tenant_id` set to a specific school. Roles are `Teacher`, `Staff`,
  `Student`, `School Admin` (see [docs/database.md](database.md) for the RBAC schema).

`AuthServiceProvider`'s `Gate::before` grants a Super Admin every ability unconditionally,
so a newly added permission reaches them with no backfill — they are never one of the
rows in `role_permission`.
