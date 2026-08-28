# API

Base path: `/api/v1`. Versioned from day one — a future `/api/v2` gets its own route file
and controller namespace rather than breaking existing clients.

## Response envelope

Every endpoint returns the same shape, via `App\Http\Responses\ApiResponse`:

```json
{
  "success": true,
  "message": "Signed in.",
  "data": { "...": "..." },
  "meta": { "...": "..." }
}
```

- `data` is present, even if `null`, on success responses.
- `message` and `meta` are omitted (not `null`-valued) when there's nothing to say.
- A paginated `data` is unwrapped to a plain array; pagination info moves to
  `meta.pagination` (see below) rather than nesting `links`/`meta` the way Laravel does by
  default — a paginated list and a single resource then differ only by that one key.

Errors:

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "error": { "code": "VALIDATION_FAILED" },
  "errors": { "email": ["The email field is required."] }
}
```

`error.code` is a stable machine-readable string the SPA can switch on
(`TENANT_MISMATCH`, `ACCOUNT_INACTIVE`, `VALIDATION_FAILED`, `NOT_FOUND`, `RATE_LIMITED`,
`UNAUTHENTICATED`, `FORBIDDEN`). `errors` (plural) is only present for `422` field-level
validation failures, keyed by input name — what the SPA's form components bind to.

## Pagination, filtering, sorting, searching

Not yet exposed on any endpoint (no list endpoints exist until Phase 5/6 add resources to
list), but the mechanism is built and ready: `App\Support\Query\ApiQuery`.

```
GET /api/v1/users?search=sok&filter[status]=active&sort=-created_at&per_page=50
```

- **Search** (`?search=`) — only against columns a controller explicitly allow-lists via
  `->searchable(...)`. Uses `ILIKE` (Postgres case-insensitive), with `%`/`_`/`\` escaped
  so a search for a literal `%` can't force a full scan.
- **Filter** (`?filter[x]=`) — only against an allow-listed `column => exposed name` map.
  Comma-separated values become `whereIn`.
- **Sort** (`?sort=-created_at,name`) — `-` prefix for descending. Only allow-listed
  columns; everything else is silently ignored, never passed through to the query (a
  client can never sort by an unindexed column and degrade the query for everyone).
- **`per_page`** — clamped to a configured maximum (`ApiQuery::MAX_PER_PAGE`, default
  100), never rejected outright, but never honoured past the cap either.

Two pagination modes, chosen per endpoint:

- **`paginate()`** — length-aware (`meta.pagination.total`, `.last_page`), for admin
  tables that show a page count. Costs a `COUNT(*)` per request.
- **`cursorPaginate()`** — keyset pagination (`meta.pagination.next_cursor`), constant
  cost regardless of depth. The right default for anything that can grow past
  ~100k rows or that a client will page through sequentially (exports, feeds).

Every list query gets a final deterministic tiebreak order automatically
(`ORDER BY id DESC` appended) — required for cursor pagination to be stable, and it stops
plain offset pagination from occasionally repeating or skipping rows when sort values tie.

## Authentication

`POST /api/v1/auth/login` — `{ email, password, device_name?, remember?, tenant? }`.

Two transports, chosen by the request, not by configuration:

- **No `device_name`** → session-cookie auth. Requires the request to look like it came
  from a first-party SPA (`SANCTUM_STATEFUL_DOMAINS` matched against `Origin`/`Referer` —
  a real browser fetch/XHR always sends `Origin`; see `EnsureFrontendRequestsAreStateful`).
  Response sets an HttpOnly session cookie; the token never touches JavaScript.
- **`device_name` present** → Bearer token (Sanctum personal access token, 30-day
  expiry by default). For mobile apps, custom-domain integrations, and anything that
  isn't the first-party SPA.

Either way, `Route::middleware('auth:sanctum')` accepts the request identically — nothing
downstream needs to know which transport was used.

**Login is tenant-scoped at the lookup, not just checked after.** The candidate user is
selected from the resolved tenant only — correct credentials for School A's account are
simply not recognised on School B's domain, which is stronger than authenticating first
and rejecting on tenant mismatch afterward (that would confirm the account exists at all
to the wrong school).

**The failure message is identical for "no such user" and "wrong password."**
`AuthService` checks the submitted password against a fixed, validly-formatted dummy
bcrypt hash when no user matched, so the response time doesn't leak which case occurred
either — the endpoint cannot be used to enumerate accounts by content or by timing.

Other auth endpoints, all under `/api/v1/auth`:

| Route | Auth required | Notes |
|---|---|---|
| `POST /logout` | Yes | Revokes the current token only, or ends the current session — never every credential, so signing out on a phone doesn't sign you out on a laptop |
| `GET /me` | Yes | User + tenant + flattened permission list, everything the SPA needs to render its shell in one call |
| `POST /change-password` | Yes | Requires `current_password` (defeats a walk-up attacker at an unlocked screen); revokes every other token, keeps the one making the request |
| `POST /forgot-password` | No | Always `200` with an identical message whether or not the address exists |
| `POST /reset-password` | No | Revokes every token on success — a password reset is exactly the moment an attacker's stolen session should be invalidated |
| `GET /verify-email/{id}/{hash}` | Signed URL | No auth needed; the signature itself is the credential and expires on its own |
| `POST /verify-email/resend` | Yes | Rate-limited |

Rate limits (`App\Providers\AppServiceProvider::configureRateLimiting`):
`auth` — 20/min per (tenant, IP), ahead of `LoginRequest`'s own 5/min-per-account throttle
(one IP can't grind through many accounts at a school; one account can't be brute-forced
even if the attacker rotates IPs). `verification` — 6 per 5 minutes. General `api` —
120/min per user (or IP if unauthenticated).

## Authorization

`$user->can('students.create')` / `@can('students.create')` — permission slugs are checked
directly by `AuthServiceProvider`'s `Gate::before`, no policy method needed for a bare
capability check. Policy classes (`view`, `update`, `delete`, ...) additionally enforce the
tenant boundary and role hierarchy — see [docs/multi-tenancy.md](multi-tenancy.md) and
`App\Policies\UserPolicy` for the pattern. Full permission catalog:
`App\Support\Authorization\Permissions::catalog()`.

## Academic domain

Standard `Route::apiResource` CRUD (`index`/`store`/`show`/`update`/`destroy`), all under
`auth:sanctum` + `active`, all implicitly tenant-scoped via `BelongsToTenant` — a request
for another school's record 404s the same as a nonexistent id, both for the `index`
listing and for a direct `{id}` lookup.

| Endpoint | Notes |
|---|---|
| `/api/v1/teachers` | `paginate()` — small table, a page count is cheap and useful in the UI |
| `/api/v1/students` | **`cursorPaginate()`**, not `paginate()` — this is the table designed to hold millions of rows, so it never computes a total count. `meta.pagination.type` is `"cursor"` here, `"length_aware"` everywhere else |
| `/api/v1/classrooms` | |
| `/api/v1/books` | |
| `/api/v1/classes` | Route parameter is `{class}` (model is `SchoolClass` — `class` is a PHP reserved word, can't name a class `Class`). `POST`/`PUT` accept a nested `schedules` array (the "study day"/"study time" — `day_of_week` 1–7, `start_time`/`end_time` as `"HH:MM"`) and a `book_ids` array in the same request; an update's `schedules` **replaces** the class's entire weekly schedule rather than merging into it |
| `/api/v1/enrollments` | Links a student to a class; `student_id`/`class_id` are immutable after creation — re-enrolling is a new record, not an edit |
| `/api/v1/home-slides` | The admin side of the homepage image slider. `store`/`update` take `multipart/form-data`, not JSON — see [docs/database.md#file-storage](database.md#file-storage) for the upload plumbing (upload limits, the `_method=PUT` requirement for updates, soft-vs-force delete) |
| `/api/v1/public/home-slides` | Unauthenticated — the public homepage's slider reads from here: active slides only, ordered by `sort_order` |

Every `teacher_id`/`classroom_id`/`student_id`/`class_id`/`book_id` reference in a request
body is validated against the *current tenant's* rows specifically (`Rule::exists(...)->where('tenant_id', ...)`)
— naming another school's id, even a real one, fails validation rather than silently
succeeding or leaking a 500 from a foreign-key violation.
