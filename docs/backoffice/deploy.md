# Back office — going live

What the deploy does by itself, and the few steps that are yours on the
server. Addresses:

| | Address |
|---|---|
| Back office (staff) | https://smis.newtechkh.com |
| Console (schools) | https://erp.newtechkh.com |

## What the deploy does (push to `main`)

- Builds the back office (`frontend/dist/backoffice`) beside the console, and
  uploads it.
- `migrate --force` creates the back office's tables in the central
  database: `bo_staff`, `bo_roles`, `bo_role_staff`, `bo_audit_logs`,
  `bo_schools`, `bo_school_provisioning_logs`, `bo_school_status_history`,
  `bo_backups`, `bo_backup_schedule`, `bo_migration_runs`, `bo_heartbeats`,
  and adds `verification_token` to `tenant_domains`. Nothing existing is
  changed or removed.
- nginx gets a `smis.newtechkh.com` site (`docker/nginx/prod.conf`).
- The queue worker and the scheduler are restarted.

**School databases keep their names** (`tenant_<slug>`): the prefix is a
setting, `TENANCY_DB_PREFIX`, and it defaults to `tenant_`. Nothing is
renamed and no database is created for an existing school.

## Your steps on the server

### 1. DNS and HTTPS for smis.newtechkh.com

Point `smis.newtechkh.com` at the server the same way `erp.newtechkh.com`
is (same A record / proxy), and give it HTTPS the same way erp has it.
nginx inside Docker already answers for that name on port 80.

Recommended: allow only your office's IP addresses to reach
smis.newtechkh.com (at the proxy/firewall, or an `allow … ; deny all;` in its
nginx block). The back office manages every school.

### 2. Make sure backups can run

```bash
docker compose -f docker-compose.prod.yml exec queue which pg_dump
```

It must print `/usr/bin/pg_dump`. If it prints nothing, rebuild the queue
container: `docker compose -f docker-compose.prod.yml up -d --build queue`.

### 3. Create the first Owner

```bash
docker compose -f docker-compose.prod.yml exec php \
  php artisan platform:create-staff you@example.com "Your Name"
```

It prints a temporary password once. Sign in at https://smis.newtechkh.com,
scan the QR code with an authenticator app, save the recovery codes, choose
a password. Add the rest of your staff from **Staff**.

### 4. Optional settings in the server's `backend/.env`

| Setting | Default | What it does |
|---|---|---|
| `PLATFORM_CONSOLE_URL` | `https://erp.newtechkh.com` | Where owner invite links point |
| `PLATFORM_BACKUP_COPY_DISK` | empty (off) | The second copy of each backup — see stage-3-data-ops.md |
| `TENANCY_DB_PREFIX` | `tenant_` | School database names. Change only together with the rename below |

After any `.env` change: `docker compose -f docker-compose.prod.yml restart php queue scheduler`.

### 5. Later, if you want `tn_` names on the server too

Only in a maintenance window, with fresh backups:

1. Stop php, queue and scheduler.
2. For each school: `alter database "tenant_<slug>" rename to "tn_<slug>";`
3. Set `TENANCY_DB_PREFIX=tn_` in the server's `backend/.env`.
4. Start them again and check Data ops → Databases shows no *missing* school.

## Email

Until mail works, use **Copy invite link** on a school's page and send the
link by Telegram or SMS. Emailed invites that fail show up as failed jobs on
Data ops → Health.
