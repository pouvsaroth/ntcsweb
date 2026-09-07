<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every existing school gets a starter "General" form category so the Forms
 * catalog isn't empty on day one — a school renames or adds more of its own
 * afterward through the admin Form Categories screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        Tenant::query()->pluck('id')->each(function (int $tenantId) use ($now) {
            DB::table('form_categories')->insert([
                'tenant_id' => $tenantId,
                'name' => 'General',
                'order' => 0,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        DB::table('form_categories')->where('name', 'General')->delete();
    }
};
