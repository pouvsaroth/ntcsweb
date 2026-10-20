# Stage 8 — Plans on the website, self-service sign-up, Bakong payment

A school finds NewTech on newtechkh.com, picks a plan, fills in a form step by
step, pays by KHQR, and starts using the system. No staff step.

## Decisions (user, 2026-10-19)

| Question | Answer |
|---|---|
| Where the plans come from | **The back office's plans** (Billing → Plans) |
| A plan card's bullet points | **Typed per plan** (Khmer + English, one per line) |
| What "Choose" does | **Self-service sign-up**: a step-by-step form, pay, then use it |
| How payment is confirmed | **Bakong auto-check**: KHQR with the exact amount; the system asks Bakong until it is paid |
| Paid plan before paying | **No**: a paid plan opens only once paid. The Free trial card is the way to try first |

## Steps (one at a time, check in after each)

### 8a. Plans on the website

- `bo_plans` gets: `show_on_website`, `name_km`, `tagline_en/km`
  ("សម្រាប់សាលាតូចៗ"), `features_en/km` (one per line), `recommended`
  (the ណែនាំ mark; at most one plan), `website_order`.
- Billing → Plans: a "On the website" section in the plan form.
- Public `GET /api/v1/public/plans`: shown, active plans only, plus a Free
  trial card built from the billing settings' trial days (shown when trial
  days > 0). Never the Legacy plan, never modules or limits it does not show.
- New services card kind **plans** (one card): opens `/plans`, a page of plan
  cards like the services page (no school header). Price shows as
  "$15 / ក្នុងមួយខែ" from price + period.
- The live "School Management System" card is changed to this kind by the
  user in the back office (no production database step by us).

**As built (2026-10-20):** migration `2026_10_20_010000_add_website_fields_to_bo_plans`;
`App\Http\Controllers\Api\V1\Public\PlanController` (`GET public/plans`, features
split into lines, a typed leading "-" or "•" dropped); services card kind
`plans` (`ServicesPageContent::KIND_PLANS`, one card); public page
`pages/public/Plans.vue` at `/plans`, no school header (company view). Plan
cards have no button yet — "Choose" arrives with 8b. Tests:
`tests/Feature/Platform/SignupPlansTest.php`.

### 8b. Sign-up form (free trial first)

`/signup?plan={code|trial}`, steps:
1. School: Khmer name, English name, web address (`{slug}`, checked live
   against existing schools and reserved words).
2. Owner: name, email, phone, password (the owner sets it themselves — this
   is not staff setting a password, rule 6 holds).
3. Plan + months (paid plans: 1, 3, 6, 12 × the plan's period).
4. Check and confirm.

Kept in `bo_signups` (central): the answers, plan, months, amount, status
(`pending_payment` → `paid` → `created` | `expired` | `failed`), IP.
The web address is held for 30 minutes while unpaid.

Free trial: confirm → the school is created at once through the same code as
back office → Schools → Create (provisioning job, trial subscription), and
the page waits for it and then links to sign in.

Abuse: per-IP rate limit, a hidden honeypot field, one live sign-up per
email. Back office gets a **Sign-ups** list (status, school, amount, paid at).

**As built (2026-10-20):** table `bo_signups` (`App\Platform\Models\Signup`);
`App\Platform\Signup\Signups` (web address check incl. the 30-minute hold,
start, `createSchool()` — 8c calls it with the paid plan); school creation
moved into `App\Platform\Services\SchoolCreator`, shared with back office →
Schools → Create; the provisioner skips the invite email when the owner chose
a password. Public: `GET public/signup/slug`, `POST public/signup`
(throttle `signups`: 5/hour/IP; honeypot field `website`), `GET
public/signup/{token}`. One free trial per email. Pages `/signup` (4 steps)
and `/signup/{token}` (setting up → ready → sign-in link
`{console_url}/login?tenant={slug}`). Back office: Billing → Sign-ups
(`GET platform/billing/signups`, billing.read). A paid sign-up stops at
"Payment" until 8c. Tests: `tests/Feature/Platform/SignupTest.php`.

### 8c. Bakong payment

- Billing settings: NewTech's KHQR (the static "receive any amount" code)
  and the Bakong API token (stored encrypted; the token expires every 90
  days — the settings page shows its expiry and warns 14 days before).
- Pay step: a fixed-amount KHQR (existing `Khqr::withAmount`), valid 15
  minutes; the page asks our server every 5 s, our server asks Bakong
  `check_transaction_by_md5`. Also a scheduled check every minute so a
  payment is never missed when the visitor closes the page.
- Paid → in one transaction: create the school on the plan as **active**
  for the months bought, record the payment (method KHQR, Bakong hash as
  reference, receipt number), audit it. The receipt PDF is downloadable from
  the done page.
- Amount or currency not matching → not accepted, shown to staff in Sign-ups.

**As built (2026-10-20):** `Khqr::forPayment()` = the fixed-amount code plus
tag 99 (created/expires ms), so every sign-up's code — and its MD5 — is its
own; valid for the 30-minute hold (not 15), and still looked up for
`platform.bakong.late_minutes` (10) after. `App\Platform\Signup\Bakong`
(`check_transaction_by_md5`, token from billing settings, `encrypted` cast,
expiry read from the JWT). `Signups::checkPayment()` — from the visitor's
status page (at most every 4 s per sign-up) and `platform:signups-tick`
(every minute, also expires unpaid ones). Paid the right amount → school
created via `SchoolCreator`, subscribed and the payment recorded through the
existing `Subscriptions::subscribe()` / `Payments::record()` (actor null,
method khqr, reference = Bakong hash, receipt R-yyyy-nnnn; the receipt PDF
says "Bakong KHQR (online)"). Wrong amount/currency → `failed`, shown in
Sign-ups. Paid but the school failed → `failed` with **Create the school
again** (`POST platform/billing/signups/{id}/retry`). Settings: `PUT
billing/settings/payment` (KHQR, token write-only, `clear_token`) and `POST
billing/settings/payment/test`. Receipt download for the visitor is not
built — the receipt number is shown; staff download the PDF from Payments.
Tests: `tests/Feature/Platform/SignupPaymentTest.php` (Bakong faked).

**Not verified against the real Bakong yet** (no token locally): the reply
shape is taken from Bakong's Open API document. First live check: save the
KHQR + token, press **Test the token**, then make one $0.01-style test plan
sign-up and pay it.

## Done when

- `docker exec ntscweb_php php artisan test --filter=Signup` passes (plans
  feed, sign-up steps, slug hold, free trial creates a school, Bakong check
  mocked: paid creates school + payment, wrong amount does not, expired QR).
- Render check: /plans, each sign-up step on phone and desktop, no page errors.
- `npm run build` and `vue-tsc -b` pass.
