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

### 1. HTTPS for smis.newtechkh.com (host Apache)

DNS already points `smis.newtechkh.com` at the server (38.242.236.109). As
for `erp.newtechkh.com`, the **host's own Apache** is the front door: it
terminates HTTPS and reverse-proxies into the Docker nginx on
`127.0.0.1:8095` (see `docs/deployment.md`). Until smis has its own Apache
site, Apache answers it with its default site.

Copy erp's Apache site and change the name — the important lines:

```apache
<VirtualHost *:80>
    ServerName smis.newtechkh.com
    ProxyPreserveHost On
    ProxyPass        / http://127.0.0.1:8095/
    ProxyPassReverse / http://127.0.0.1:8095/
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
```

`ProxyPreserveHost On` matters: the Docker nginx picks the back office by
the `smis.newtechkh.com` Host header. Then:

```bash
sudo a2ensite smis.newtechkh.com && sudo systemctl reload apache2
sudo certbot --apache -d smis.newtechkh.com     # same as erp's Let's Encrypt certificate
```

Recommended: allow only your office's IP addresses in that vhost
(`<Location /> Require ip … </Location>`). The back office manages every
school.

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

### 5. Renaming the server's databases to `bo_school` / `tn_` — done 2026-10-16

Done on production: `ntcsdb` → `bo_school`, `tenant_demo|newtech|test` → `tn_…`,
with `DB_DATABASE=bo_school` and `TENANCY_DB_PREFIX=tn_` in the server's
`backend/.env`. Dumps from just before are in `/root/db-backups/before-rename/`.
The procedure, for reference:

Only in a maintenance window, with fresh backups:

1. Stop php, queue and scheduler.
2. For each school: `alter database "tenant_<slug>" rename to "tn_<slug>";`
3. Set `TENANCY_DB_PREFIX=tn_` in the server's `backend/.env`.
4. Start them again and check Data ops → Databases shows no *missing* school.

## Email

Until mail works, use **Copy invite link** on a school's page and send the
link by Telegram or SMS. Emailed invites that fail show up as failed jobs on
Data ops → Health.
