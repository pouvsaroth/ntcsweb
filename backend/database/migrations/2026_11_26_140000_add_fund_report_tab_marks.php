<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * Fund > Reports now has tabs (Summary, Money in & out, Providers by month):
 * adds their `hide-tab.*` marks so Settings > Roles shows a Show box per tab
 * (see TabVisibility). Nothing is granted: every role keeps every tab.
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
