# Trying the back office locally

Everything here runs on your own machine against the local `bo_school` /
`tn_*` databases. Nothing touches the server.

## 0. Is everything running?

| What | Address | Start it with |
|---|---|---|
| Back office | http://127.0.0.1:5301 | `cd frontend && npm run dev:backoffice` |
| School admin app | http://127.0.0.1:5299 | `cd frontend && npx vite --config vite.config.admin.ts --port 5299 --strictPort` |
| Backend (Docker) | http://localhost:8080 | `docker compose up -d` |

**The school app must run on 5299, not its default 5300.** Only 5299 is in
`SANCTUM_STATEFUL_DOMAINS` (`backend/.env`); on 5300 every school login fails
with "Session store not set on request". (The back office on 5301 is not
affected — it has its own session handling.)

After changing `backend/.env`, restart the workers so they pick it up:

```bash
docker restart ntscweb_queue ntscweb_scheduler
```

## 1. Your own Owner account

```bash
docker exec ntscweb_php php artisan platform:create-staff you@example.com "Your Name"
```

It prints a **temporary password** once.

## 2. First sign-in

1. Open http://127.0.0.1:5301 and sign in with that email and temporary password.
2. Scan the QR code with an authenticator app on your phone (Google
   Authenticator, Microsoft Authenticator, …) and type the 6-digit code.
3. **Save the 8 recovery codes** — they are shown only once.
4. Choose your own password. You land on **Schools**.

Lost the phone? Another Owner can press **Reset 2FA** for you on the Staff
page, or create a fresh account with the command above.

## 3. Things to try

**Schools**
- **New school** → fill the names, slug and an owner email → watch the setup
  steps tick off (about 20–30 s). The school ends **Active**.
- On the school: edit the profile, **Suspend** (needs a reason) →
  **Reactivate**, add a custom domain and press **Check now** (locally it
  will say "not found yet" — that is expected, there is no real DNS).

**Accept an owner invite (the school side)**

Easiest: on the school's page press **Copy invite link** and open the link
on this machine (it points at the school app on port 5299). Or, since mail is
written to the log locally, read the emailed link from there:

```bash
docker exec ntscweb_php sh -c 'grep -o "reset-password?token=[^\" ]*invite=1" storage/logs/laravel.log | tail -1'
```

It prints `reset-password?token=…&amp;email=…&amp;tenant=…&amp;invite=1`.
Change each `&amp;` to `&`, put `http://127.0.0.1:5299/` in front, open it,
choose a password, then sign in at http://127.0.0.1:5299/login as that owner.

**Data ops**
- **Databases**: every database with size, pending migrations, last backup.
- **Back up all** → **Backups** tab fills in by itself → **Download** one.
- Change the **Schedule** (days, time, keep days) and save.
- **Migrate all schools** → **Migrations** tab.
- **Health**: scheduler, queue, disk.

**Staff / Roles / Audit log**
- Add a staff member (temporary password shown once), give them a role with
  only some permissions, sign in as them in a private window and see the
  menu shrink to what they may open.
- Every change above appears in **Audit log**.

## 4. Good to know

- The school app and the back office share one session on `localhost`:
  signing out of one signs you out of the other. On their real separate
  addresses that does not happen.
- Backup files are in `backend/storage/app/backups` (second copy in
  `backups-copy`). Scheduled backups run every day at 02:00 by default.
- Local test schools made while building: `demo`, `sample-school` (archive
  them from the school page if you do not want them in the list).
- Nothing here is committed. `git status` shows the back office as new and
  changed files.
