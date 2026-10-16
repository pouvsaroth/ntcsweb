# Restoring a backup

Restore is a **server command, never a back-office button** (owner's
decision, Stage 3). It always restores into a **new** database; it never
writes over a live one. Swapping a school onto the restored copy is a
separate, deliberate step.

## 1. Find the backup

Back office → Data ops → Backups. Note the backup's **#id**, its database
and its time. (Only backups with status *ok* can be restored.)

## 2. Restore into a new database

On the server:

```bash
docker compose -f docker-compose.prod.yml exec php \
  php artisan platform:restore-backup <id> --into=<new_database_name>

# e.g. look at yesterday's newtech without touching the live one
php artisan platform:restore-backup 42 --into=tn_newtech_check
```

- `--into` must be a database that does **not** exist yet (`createdb`
  refuses one that does).
- The restore is audited (`backup.restored`).
- If the file is no longer on the server's backups disk, copy it back from
  the second place first (same path under the copy disk).

Check the copy with `psql -d <new_database_name>`.

## 3a. Just looking? Drop it afterwards

```bash
psql -U <user> -d template1 -c 'drop database "<new_database_name>"'
```

## 3b. Replacing a school's live database

Only in a maintenance window, and only after taking a fresh backup of the
live database first (Data ops → Back up now), so the swap itself can be
undone.

1. **Suspend** the school in the back office (its users are signed out, its
   site stops answering).
2. Rename the live database out of the way and the restored one into place:

   ```sql
   -- connected to template1, with no sessions open on either database
   alter database "tn_newtech" rename to "tn_newtech_before_restore";
   alter database "tn_newtech_check" rename to "tn_newtech";
   ```

3. **Migrate** the school (Data ops → Migrate) in case the backup predates a
   migration.
4. **Reactivate** the school. Keep `tn_newtech_before_restore` until you are
   sure, then drop it.

The central database (`bo_school`) is restored the same way, but with the
whole platform stopped (php, queue, scheduler) rather than one school
suspended.
