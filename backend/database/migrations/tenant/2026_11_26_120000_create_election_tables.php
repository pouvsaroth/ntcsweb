<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Political Party > Election offices, kept per election year:
 *  - election_offices: where people vote (code, name, location, voters),
 *    each in a commune (and optionally a village);
 *  - election_commune_seats: how many commune / sangkat council seats each
 *    commune has.
 * Place ids point at the central geography tables (no foreign key); the
 * levels above the commune are filled in for filtering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('election_offices', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('election_year');
            $table->string('code', 30);
            $table->string('name');
            $table->unsignedBigInteger('province_id');
            $table->unsignedBigInteger('district_id');
            $table->unsignedBigInteger('commune_id');
            $table->unsignedBigInteger('village_id')->nullable();
            $table->string('location')->nullable();
            $table->unsignedInteger('voters')->nullable();
            $table->timestamps();

            $table->unique(['election_year', 'code']);
            $table->index(['election_year', 'province_id', 'district_id', 'commune_id']);
        });

        Schema::create('election_commune_seats', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('election_year');
            $table->unsignedBigInteger('province_id');
            $table->unsignedBigInteger('district_id');
            $table->unsignedBigInteger('commune_id');
            $table->unsignedSmallInteger('seats');
            $table->timestamps();

            $table->unique(['election_year', 'commune_id']);
            $table->index(['election_year', 'province_id', 'district_id']);
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'election_offices');
        SchoolTables::secure($db, 'election_commune_seats');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('election_commune_seats');
        Schema::dropIfExists('election_offices');
    }
};
