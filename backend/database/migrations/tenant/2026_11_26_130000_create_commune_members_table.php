<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Political Party > Commune members: each commune's list of
 * full members (ពេញសិទ្ធិ) and reserve members (បម្រុង) for an election
 * year, in order. A member is an HRM staff member (their details live on
 * the staff record) and is on one commune's list per year. Place ids point
 * at the central geography tables (no foreign key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commune_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('election_year');
            $table->unsignedBigInteger('province_id');
            $table->unsignedBigInteger('district_id');
            $table->unsignedBigInteger('commune_id');
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('role', 10); // full | reserve
            $table->unsignedSmallInteger('position');
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->unique(['election_year', 'staff_id']);
            $table->index(['election_year', 'commune_id', 'role', 'position']);
            $table->index(['election_year', 'province_id', 'district_id']);
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'commune_members');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('commune_members');
    }
};
