<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide table (no tenant_id column of its own beyond the FK it maps).
 *
 * Holds every hostname that maps to a tenant. This is the authoritative source
 * for domain-based tenant resolution and replaces the single `tenants.domain`
 * column, which could only ever hold one hostname per school.
 *
 * Supports both roadmap cases:
 *   - subdomain      newtech.ntcsweb.com
 *   - custom domain  school.example.edu.kh
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Always stored lower-cased and without a port. Unique platform-wide:
            // one hostname can only ever point at one tenant.
            $table->string('hostname', 253)->unique();

            $table->string('type', 20)->default('subdomain'); // subdomain | custom

            $table->boolean('is_primary')->default(false);

            // Custom domains stay unverified until DNS ownership is proven.
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            // "give me this tenant's primary hostname" — the only non-lookup query.
            $table->index(['tenant_id', 'is_primary']);
        });

        // Carry over any hostname already recorded on tenants.domain so we do not
        // lose data when that column is dropped in the next migration.
        DB::table('tenants')
            ->whereNotNull('domain')
            ->where('domain', '!=', '')
            ->orderBy('id')
            ->select('id', 'domain')
            ->chunkById(500, function ($tenants) {
                $now = now();

                DB::table('tenant_domains')->insert(
                    $tenants->map(fn ($tenant) => [
                        'tenant_id' => $tenant->id,
                        'hostname' => mb_strtolower(trim($tenant->domain)),
                        'type' => 'custom',
                        'is_primary' => true,
                        'verified_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
