<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Fund > Providers: the people (or companies) who give money
 * to a fund. Money in can name one; its name is also kept in `party`.
 * `code` (P00001) is set from the id when a provider is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->nullable()->unique();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        Schema::table('fund_transactions', function (Blueprint $table) {
            $table->foreignId('fund_provider_id')->nullable()->after('party')->constrained('fund_providers')->nullOnDelete();
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'fund_providers');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::table('fund_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fund_provider_id');
        });
        Schema::dropIfExists('fund_providers');
    }
};
