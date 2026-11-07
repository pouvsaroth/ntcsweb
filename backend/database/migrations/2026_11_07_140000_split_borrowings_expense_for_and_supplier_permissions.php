<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roles step 4a: Accounting > Borrowings loses borrowings.manage (Loans,
 * Repayments and Lenders get their own Add / Update / Delete), and Expense for
 * and Suppliers (Vendors) get their own permissions instead of borrowing
 * Expenses' and Assets'. Each new permission goes to every role that could do
 * the same thing before; roles are read before sync() drops borrowings.manage.
 */
return new class extends Migration
{
    /** New permission => the old ones that allowed it. */
    private const GRANTS = [
        'borrowing-loans.create' => ['borrowings.manage'],
        'borrowing-loans.update' => ['borrowings.manage'],
        'borrowing-loans.delete' => ['borrowings.manage'],
        'borrowing-repayments.create' => ['borrowings.manage'],
        'borrowing-repayments.delete' => ['borrowings.manage'],
        'lenders.create' => ['borrowings.manage'],
        'lenders.update' => ['borrowings.manage'],
        'lenders.delete' => ['borrowings.manage'],
        'expense-for.view' => ['expense.view'],
        'expense-for.create' => ['expense.create'],
        'expense-for.update' => ['expense.update'],
        'expense-for.delete' => ['expense.update'],
        'suppliers.view' => ['assets.view', 'expense.view'],
        'suppliers.create' => ['assets.create', 'expense.create'],
        'suppliers.update' => ['assets.update', 'expense.update'],
        'suppliers.delete' => ['assets.delete', 'expense.update'],
    ];

    public function up(): void
    {
        $old = array_values(array_unique(array_merge(...array_values(self::GRANTS))));
        $holders = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereIn('permissions.slug', $old)
            ->get(['permissions.slug', 'permission_role.role_id'])
            ->groupBy('slug')
            ->map(fn ($rows) => $rows->pluck('role_id')->all());

        app(PermissionRegistry::class)->sync();

        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::GRANTS))->pluck('id', 'slug');
        $rows = [];
        foreach (self::GRANTS as $new => $from) {
            if (! isset($ids[$new])) {
                continue;
            }
            foreach ($from as $slug) {
                foreach ($holders[$slug] ?? [] as $roleId) {
                    $rows[$roleId.'-'.$ids[$new]] = ['role_id' => $roleId, 'permission_id' => $ids[$new]];
                }
            }
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('permission_role')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        // sync() drops permissions that leave the catalog; nothing to undo by hand.
    }
};
