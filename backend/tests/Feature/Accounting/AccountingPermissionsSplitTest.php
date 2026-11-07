<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Roles step 4a: Borrowings tabs, Expense for and Suppliers (Vendors) each
 * have their own Add / Update / Delete.
 */
class AccountingPermissionsSplitTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_lenders_have_their_own_add_update_and_delete(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BORROWINGS_VIEW, Permissions::LENDERS_CREATE]);
        $id = $this->postJson('/api/v1/lenders', ['name' => 'ABA Bank', 'type' => 'company'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/lenders/{$id}", ['name' => 'ABA'])->assertForbidden();
        $this->deleteJson("/api/v1/lenders/{$id}")->assertForbidden();
        // Lenders' Add is not Loans' Add.
        $this->postJson('/api/v1/borrowings', [])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::BORROWINGS_VIEW, Permissions::LENDERS_UPDATE, Permissions::LENDERS_DELETE]);
        $this->putJson("/api/v1/lenders/{$id}", ['name' => 'ABA'])->assertOk();
        $this->deleteJson("/api/v1/lenders/{$id}")->assertNoContent();
    }

    public function test_expense_for_and_suppliers_need_their_own_add(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_VIEW, Permissions::EXPENSE_CREATE]);
        // Picking from the lists still works for expense staff.
        $this->getJson('/api/v1/expense-entities')->assertOk();
        $this->getJson('/api/v1/suppliers')->assertOk();
        $this->postJson('/api/v1/expense-entities', ['name' => 'Flexi'])->assertForbidden();
        $this->postJson('/api/v1/suppliers', ['name' => 'EDC'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_FOR_VIEW, Permissions::EXPENSE_FOR_CREATE, Permissions::SUPPLIERS_VIEW, Permissions::SUPPLIERS_CREATE]);
        $this->postJson('/api/v1/expense-entities', ['name' => 'Flexi'])->assertCreated();
        $this->postJson('/api/v1/suppliers', ['name' => 'EDC'])->assertCreated();
    }

    public function test_school_admin_has_every_new_permission_by_default(): void
    {
        $defaults = Permissions::defaultsForSystemRoles()['school-admin'];
        foreach ([
            Permissions::BORROWING_LOANS_DELETE, Permissions::BORROWING_REPAYMENTS_DELETE, Permissions::LENDERS_DELETE,
            Permissions::EXPENSE_FOR_DELETE, Permissions::SUPPLIERS_DELETE,
        ] as $slug) {
            $this->assertContains($slug, $defaults);
        }
    }
}
