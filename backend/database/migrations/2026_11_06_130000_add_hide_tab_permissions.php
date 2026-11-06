<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * Settings > Roles: a Show box per tab. Adds the `hide-tab.*` marks to the
 * permissions table (see TabVisibility). Nothing is granted: no role holds
 * one yet, so every role keeps every tab until a school admin unticks one.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistry::class)->sync();
    }

    public function down(): void
    {
        // sync() removes permissions that left the catalog; nothing to undo by hand.
    }
};
