<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage 4a (docs/backoffice/stage-4-database-placement.md): every school table
 * gets tenant_id, per-school unique indexes and a FORCEd row-level-security
 * policy, so several schools can share one database (school_share) and every
 * school's database works the same way whatever its placement.
 *
 * Existing rows are stamped with the school this database belongs to — the
 * migration runs with app.tenant_id set (TenantProvisioningService). A
 * database with rows and no school set refuses, rather than guessing.
 *
 * One transaction: a failure on any table leaves the database as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection();

        $db->transaction(function () use ($db) {
            foreach (SchoolTables::all($db) as $table) {
                SchoolTables::secure($db, $table);
            }

            SchoolTables::grantAppRole($db);
        });
    }

    public function down(): void
    {
        // Deliberately not reversible in place: dropping tenant_id from a
        // database shared by several schools would merge them. Restore the
        // backup taken before this migration instead.
        throw new RuntimeException('Restore the pre-4a backup to undo this migration.');
    }
};
