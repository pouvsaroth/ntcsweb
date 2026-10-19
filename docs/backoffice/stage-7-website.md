# Stage 7 — Website in the back office

```
Owns      backend: app/Platform/** (website controllers, one middleware), routes/platform.php,
          config/platform.php (website_school, platform.website.* permissions)
          frontend: frontend/src/backoffice/** (Website menu and its tabs)
          then removes: the school app's Website menu, pages and editing endpoints
Never     a change to the public website or its content; another school's data
Depends   Stages 1–6
```

## Why

The website at newtechkh.com is becoming NewTech's company site — first a
"group of services" page (Computer School, School Management System), then
the computer school's pages. Its content belongs to the company, so it is
edited by NewTech staff in the back office, not by a school admin in the
school app.

## Decisions (owner, 2026-10-19)

| Question | Answer |
|---|---|
| Whose website | **Only NewTech's** (`platform.website_school`, default `newtech`) |
| The school app's Website menu | **Removed**; editing lives only in the back office |
| Which items | **All nine**: Home slides, About page, Gallery, Downloads, Promotions, News, Events, Announcements, Documents (the last four stay "coming soon", as today) |

Consequence, accepted: other schools have no website editor anywhere; their
public sites keep the content they already have.

## Rules

- The content stays where it is (NewTech's school data, its `tenants.settings`
  for the About page, its `public` storage for files). The public website
  reads it exactly as before.
- Every back office website request steps into NewTech's school (tenant
  context + its database connection) and nothing else; times are written in
  UTC like the rest of the back office.
- Every change is recorded in `bo_audit_logs` (`website.*`, tagged with the
  school).

### Permissions

```
platform.website.read     see the Website menu and its content
platform.website.manage   add, change and remove website content
```

## Steps (one at a time, check in after each)

1. **7a** Server side: `EnterWebsiteSchool` middleware, endpoints for every
   working item (slides, gallery, promotions, downloads folders + files,
   About page), audit, tests.
2. **7b** Back office screens: Website menu with the nine tabs, ported from
   the school app; phone cards; English and Khmer.
3. **7c** Remove from the school app: menu, pages, editing endpoints and
   their permissions; old admin links go to the dashboard.

Then the services front page (website "group of services") is built on top,
its editor in back office → Website.

## Done when

```bash
docker exec ntscweb_php php artisan test --filter=Platform
    # read alone sees, cannot change; manage changes; every write audited
    # every item: list, add, change, remove — in NewTech's school only
    # no website school configured / found → a clear error, nothing written
cd frontend && npx vue-tsc -b && npm run build:admin && npx vite build --config vite.config.backoffice.ts
```

Then locally: edit each item in the back office and see it on the public
website. Render-checked in headless Chrome, desktop and phone.

## As built — 7a (2026-10-19)

- `platform.website_school` (env `PLATFORM_WEBSITE_SCHOOL`, default
  `newtech`); permissions `platform.website.read` / `.manage`.
- `EnterWebsiteSchool` (alias `platform.website`), after the permission
  check: sets the tenant context and opens the school's connection; an
  unknown or inactive school is 409 `WEBSITE_SCHOOL_MISSING`.
- Routes under `/api/v1/platform/website`: `GET /` (which school, its
  hostname), `about` (GET, POST), `slides` / `gallery` / `promotions`
  (GET, POST, GET/PUT/DELETE `{id}`; update is a POST with `_method=PUT`
  because of the file), `download-folders` (+ `{id}/files`) and
  `download-files/{id}`.
- `WebsiteImagesController` serves the three picture lists from one config
  (model, resource, the school app's Store/Update request rules, folder);
  `WebsiteDownloadsController`, `WebsiteAboutController`. Records are looked
  up inside the school (no route-model binding), so another school's id is
  a 404. Every write → `website.*` in `bo_audit_logs` with the school.
- Tests: `WebsiteAdminTest` (7). Read-only check against local newtech: 5
  slides, 8 gallery, 0 promotions, 2 folders, About — same as the school app.

## As built — 7b (2026-10-19)

- Back office menu **Website** (`platform.website.read`), tabs as routes
  `/website/{slides,about,gallery,downloads,promotions,news,events,announcements,documents}`;
  the header names the site (newtechkh.com ↗).
- `ImagesTab` + `WebsiteImageModal` serve the three picture lists (fields per
  kind in `backoffice/website.ts`); `DownloadsTab` (folders left, files right,
  uploads one at a time with progress); `AboutTab`; `ComingSoonTab` for the
  four unbuilt ones. Phone cards; English and Khmer. Without
  `platform.website.manage` everything is read-only.
- `websiteApi` in `backoffice/api.ts` (its own client — the school app's
  services pull in its http start-up code).
- Checked locally: every tab against newtech's data; a promotion added,
  hidden and removed (three `website.promotions.*` audit rows); phone, no
  overflow. Local slide/gallery pictures partly missing on disk (data copied
  from production without files) — not a code issue.

## As built — 7c (2026-10-19)

- School app: Website group gone from the sidebar (`adminNav.ts`); its
  routes now redirect to the dashboard (`/admin/home-slides`, `gallery`,
  `uploads`, `promotions`, `about-page`, `news`, `events`, `announcements`,
  `documents`); pages, form modals and admin services deleted
  (`services/downloads.ts` keeps only the public Download page's part);
  the role editor no longer lists the Website permissions.
- Server: the school app's Website endpoints (home-slides, gallery,
  promotions, download-folders/-files, settings/about), their controllers
  and policies removed. Permissions `home-slides.*`, `gallery.*`,
  `downloads.*`, `promotions.*`, `news.view`, `events.view`,
  `announcements.view`, `documents.view` removed from `Permissions` (catalog
  and default roles); the deploy's `RolePermissionSeeder` deletes them from
  the `permissions` table. Billing `Modules`: `website` keeps `videos`,
  `contact-messages`; `hrm` no longer lists `promotions`.
- The Store/Update request classes stay, rules only, for the back office.
- Tests: the school app's admin-endpoint tests removed; their checks
  (non-image refused, edit keeps the picture, soft delete keeps the file,
  two folder levels, runnable files refused, removing a folder cleans its
  files, About stats exactly four, other settings kept) moved into
  `WebsiteAdminTest`. Public website tests unchanged.
- The `admin.*` translation keys of the removed pages are left in the
  locale files, unused.

## Services front page — Step 1, the editor (2026-10-19)

- `ServicesPageContent` (`tenants.settings->services`): `enabled` (off by
  default), `computer_school` and `sms` cards (title, text — `_km` / `_en` —
  and a picture), plus the School Management System product page (heading,
  description, features one per line, `_km` / `_en`) and its Sign in address
  (default `https://erp.newtechkh.com`).
- Back office → Website → **Services page** (first tab; Website opens on
  it): the switch, both cards side by side in Khmer and English, the
  product page. `GET/POST /api/v1/platform/website/services`, audited as
  `website.services.updated`.
- Public: `/api/v1/public/settings` gains `services` — null while off, so
  the website is unchanged until it is switched on.
- Tests in `WebsiteAdminTest` (off by default, save with pictures, replace
  a picture, other settings kept, public feed only for NewTech, validation,
  manage needed).
- Next, Step 2: the public website — the cards at `/`, the school's home at
  `/computer-school`, the product page at `/school-management-system`, a
  Services home button.

## Services front page — Step 2, the public website (2026-10-19)

- `/` (`Landing.vue`): `ServicesHome` — the two cards — while
  `site.services` is set, else the school's `Home` as before. The school's
  home also lives at `/computer-school`; the product page at
  `/school-management-system` (heading, description, features, picture,
  Sign in → `sms.sign_in_url`, Contact us) sends you to `/` while off.
- Those two pages use `CompanyHeader` (brand, Login, language; no school
  menu, no bottom tab bar). School pages keep `PublicHeader`; with the
  services page on, Home / logo / the phone Home tab go to
  `/computer-school` (`site.schoolHome`) and a **Services** link goes back.
- Text: `utils/bilingual.ts` `pickText()` — Khmer for km, English for every
  other language, each falling back to the other.
- Checked locally with the switch off (unchanged; product page → `/`) and
  on (cards, card → /computer-school, Services link, Home → /computer-school,
  product page with 5 features), desktop and phone, then switched back off.
  The `/public/news` and `/public/events` 404s on the home page are older
  (those endpoints were never built).
