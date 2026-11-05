<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the new `dashboard.cards.expense` permission (see
 * Permissions::DASHBOARD_CARDS_EXPENSE) onto every role — system or custom,
 * any tenant — that already holds expense.create (can add expenses), same approach as
 * 2026_09_22_030000_split_dashboard_cards_from_module_permissions. Only additive.
 */
return new class extends Migration
{
    private const CARD = 'dashboard.cards.expense';

    private const SOURCE = 'expense.create';

    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        $permissionIds = DB::table('permissions')->pluck('id', 'slug');
        $cardId = $permissionIds[self::CARD] ?? null;
        $sourceId = $permissionIds[self::SOURCE] ?? null;

        if ($cardId === null || $sourceId === null) {
            return;
        }

        $rows = DB::table('permission_role')
            ->where('permission_id', $sourceId)
            ->pluck('role_id')
            ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $cardId])
            ->all();

        if ($rows !== []) {
            DB::table('permission_role')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        $cardId = DB::table('permissions')->where('slug', self::CARD)->value('id');

        if ($cardId !== null) {
            DB::table('permission_role')->where('permission_id', $cardId)->delete();
        }
    }
};
