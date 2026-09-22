<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Go to website" (AdminHeader.vue) links to Tenant::hostname(), which
 * follows whichever tenant_domains row is is_primary (see
 * AuthController::me()'s primaryDomain eager-load). NewTech's primary
 * domain was www.newtechkh.com, but the production Apache vhost (see
 * docs/deployment.md's TLS/domain section) only exists for the bare
 * newtechkh.com — so every click hit a host with no vhost/cert and failed
 * to load. Swap primary to the bare domain, which is the one that actually
 * resolves.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenant_domains')->where('hostname', 'www.newtechkh.com')->update(['is_primary' => false]);
        DB::table('tenant_domains')->where('hostname', 'newtechkh.com')->update(['is_primary' => true]);
    }

    public function down(): void
    {
        DB::table('tenant_domains')->where('hostname', 'newtechkh.com')->update(['is_primary' => false]);
        DB::table('tenant_domains')->where('hostname', 'www.newtechkh.com')->update(['is_primary' => true]);
    }
};
