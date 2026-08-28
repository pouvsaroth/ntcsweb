# Architecture

## Request lifecycle (API)

```
Request
  │
  ▼
EnsureFrontendRequestsAreStateful  (Sanctum: cookie auth for first-party SPA origins,
  │                                 Bearer token for everyone else — see docs/api.md)
  ▼
ResolveTenant                     (hostname → header → authenticated user; see
  │                                 docs/multi-tenancy.md)
  ▼
EnsureTenantMatchesUser           (403 if the authenticated user's own tenant_id
  │                                 doesn't match what was just resolved)
  ▼
auth:sanctum, active              (route-level: require login, require an active
  │                                 account and an active school)
  ▼
Form Request                      (validation — never trust tenant_id from input)
  │
  ▼
Controller → Service → Model      (BelongsToTenant enforces isolation at the model
  │                                 layer regardless of what the controller does)
  ▼
API Resource → ApiResponse        (consistent { success, message, data, meta } envelope)
```

`ResolveTenant` and `EnsureTenantMatchesUser` run for *every* API request, including
unauthenticated ones — the public school website needs a tenant too, just not a user.

## Layers and why each exists

| Layer | Namespace | Responsibility |
|---|---|---|
| Middleware | `App\Http\Middleware` | Tenant resolution, tenant/user match, active-account check |
| Form Requests | `App\Http\Requests` | Validation + authorization input shape. Never assigns `tenant_id` from the request body |
| Controllers | `App\Http\Controllers` | Thin — orchestrate a Request → Service/Model → Resource, no business logic |
| Services | `App\Services` | Business logic that's more than a one-line model call (e.g. `AuthService`, `PasswordResetService`) |
| Policies | `App\Policies` | Authorization decisions, re-checking the tenant boundary even though scopes already enforce it — policies are also reached from console/queue code where no middleware ran |
| Models | `App\Models` | Persistence + the `BelongsToTenant` / `HasRolesAndPermissions` concerns |
| Resources | `App\Http\Resources` | Response shape, eager-loading discipline (`whenLoaded`, `whenCounted` — never a query per row) |
| Support | `App\Support\*` | Cross-cutting infrastructure with no HTTP dependency: `Tenancy`, `Authorization`, `Query`, `Audit` |

Controllers stay thin on purpose — a controller with business logic in it is a controller
whose logic can't be reused by a console command or a queued job, and can't be unit tested
without booting the HTTP kernel.

## Why RBAC is hand-rolled, not a package

Roles are **tenant-owned** (`roles.tenant_id`), so two schools can each shape "Teacher"
differently without affecting each other, and a platform role (`tenant_id IS NULL`) has to
exist for the Super Admin. Package solutions built around a single global role table (or a
"teams" feature bolted onto one) fight this exact shape. `App\Support\Authorization` is
~300 lines total: a permission catalog (`Permissions`), a cache-backed resolver
(`PermissionRegistry`), and a `Gate::before` for the Super Admin bypass. See
[docs/database.md](database.md) for the schema and
[docs/multi-tenancy.md](multi-tenancy.md) for why `User`/`Role` don't use
`BelongsToTenant`.

## Why Sanctum, both transports

The admin panel and public site are first-party SPAs — session cookies (HttpOnly, never
touched by JavaScript) are the right default there, immune to XSS token theft. Anything
else (a future mobile app, a custom-domain integration, server-to-server calls) gets a
Bearer token via the same `auth:sanctum` guard, decided per-request by whether a
`device_name` is present in the login payload — no second auth system, no duplicated
authorization logic. See [docs/api.md](api.md#authentication) for the exact flow.

## Why the API response envelope

```json
{ "success": true, "message": "...", "data": { ... }, "meta": { ... } }
```

One shape for every endpoint means the frontend's HTTP client can unwrap responses
generically instead of special-casing each call. Pagination metadata is flattened into
`meta.pagination` rather than Laravel's default nested `links`/`meta` pair, so a paginated
list and a single resource differ only by the presence of that key — see
`App\Http\Responses\ApiResponse` and [docs/api.md](api.md).

## Exception handling

`bootstrap/app.php`'s `withExceptions()` maps the small set of exceptions the API actually
throws to the envelope shape, with deliberately generic messages where detail would leak
information:

- `TenantMismatchException` → `403`, generic message, **full detail logged** (never sent
  to the client) via `$exceptions->report()`.
- `ModelNotFoundException` / route `404` → generic "Resource not found", never the model
  name — confirming *what* doesn't exist can confirm it exists in another tenant.
- `TenantNotResolvedException` → `404`, not `500` — to a client this is indistinguishable
  from "no such site", which is the honest answer for an unresolvable hostname; the real
  detail goes to the logs.
- `ValidationException` → `422` with the flattened `errors` map the SPA's forms bind to.

## What's deliberately not built yet

- **Academic schema** (students, courses, attendance, grades) — Phase 5, not started.
  Multi-tenancy and auth had to be solid first; retrofitting `tenant_id` isolation onto an
  existing academic schema is far more disruptive than building it in from day one.
- **Admin API / Admin panel / Public website** — Phases 6–9.
- **Table partitioning** — not justified yet by real data volume or query patterns. See
  [docs/database.md](database.md#partitioning) for the trigger conditions.
- **Domain verification flow** for custom domains — `tenant_domains.verified_at` exists
  in the schema; the DNS-proof workflow that sets it is future admin-panel work.
