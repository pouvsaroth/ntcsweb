<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Invoices > Discount codes gets its own View / Add / Update / Delete. Each
 * goes to every role that can already do the same with invoices.
 */
return new class extends Migration
{
    /** New permission => the invoice permission that grants it. */
    private const GRANTS = [
        'discount-codes.view' => 'invoices.view',
        'discount-codes.create' => 'invoices.create',
        'discount-codes.update' => 'invoices.update',
        'discount-codes.delete' => 'invoices.cancel',
    ];

    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        $ids = DB::table('permissions')->whereIn('slug', [...array_keys(self::GRANTS), ...array_values(self::GRANTS)])->pluck('id', 'slug');
        $rows = [];
        foreach (self::GRANTS as $new => $from) {
            if (! isset($ids[$new], $ids[$from])) {
                continue;
            }
            foreach (DB::table('permission_role')->where('permission_id', $ids[$from])->pluck('role_id') as $roleId) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $ids[$new]];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('permission_role')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        // sync() drops permissions that leave the catalog; nothing to undo by hand.
    }
};
