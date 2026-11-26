<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Political Party > Branches: the party's branches / offices,
 * each placed at a province, district, commune or village (`level`). The
 * place ids point at the platform-wide geography tables in the central
 * database, so they carry no foreign key; every level above the branch's
 * own is filled in too, for filtering. `code` (BR00001) is set from the id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->nullable()->unique();
            $table->string('name');
            $table->string('level', 10);
            $table->unsignedBigInteger('province_id');
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('commune_id')->nullable();
            $table->unsignedBigInteger('village_id')->nullable();
            $table->string('leader_name')->nullable();
            $table->string('leader_phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->date('opened_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->index(['province_id', 'district_id', 'commune_id', 'village_id']);
            $table->index(['level', 'is_active']);
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'party_branches');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('party_branches');
    }
};
