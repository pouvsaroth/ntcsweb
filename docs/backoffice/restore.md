# Restoring a backup

A restore always goes into a **new** database; it never writes over a live
one. Swapping a school onto the restored copy is a separate, deliberate
step. (Until 2026-11-12 restore was a server command only; the owner then
asked for it in the back office, and the command still works.)

## 1. In the back office (Data ops, needs `platform.data.databases`)

- **Backups tab → Restore** on any finished backup (whole database or a
  custom one). A school export (.zip) cannot be restored as a database.
- **Databases tab → Restore**: pick a backup from the list, or **upload a
  .dump file** (pg_dump custom format, `pg_dump -Fc`; at most 50 MB, the
  server's upload limit). A file that does not start with `PGDMP` is refused.

Name the new database (lowercase letters, digits and `_`, not already on the
server). It is restored in the background by the queue worker and shows in
Databases as an **Other** database: *Being made…*, then *Ready* or *Failed*
(with the error; a failed restore leaves no database behind). An uploaded
file waits in `storage/app/restore-uploads` and is deleted once its restore
has run, whether it worked or not. Audited as `database.restored`.

Delete an Other database from the Databases tab when done (type its name).

## 2. On the server (same result)

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

Databases tab, Delete on the Other database, or:

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
