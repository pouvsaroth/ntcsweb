<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Support\Accounting\AccountType;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_an_admin_can_create_an_account(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACCOUNTS_CREATE]);

        $response = $this->postJson('/api/v1/accounts', [
            'code' => '5300',
            'name' => 'Electricity',
            'type' => AccountType::EXPENSE,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.code', '5300');
        $response->assertJsonPath('data.is_active', true);
    }

    public function test_an_account_can_have_a_parent(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACCOUNTS_CREATE]);
        $parent = Account::factory()->forTenant($this->tenant)->type(AccountType::EXPENSE)->create(['code' => '5000']);

        $response = $this->postJson('/api/v1/accounts', [
            'code' => '5100',
            'name' => 'Salary',
            'type' => AccountType::EXPENSE,
            'parent_id' => $parent->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.parent_id', $parent->id);
    }

    public function test_an_account_code_must_be_unique_within_the_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACCOUNTS_CREATE]);
        Account::factory()->forTenant($this->tenant)->create(['code' => '5300']);

        $this->postJson('/api/v1/accounts', ['code' => '5300', 'name' => 'Dup', 'type' => AccountType::EXPENSE])
            ->assertUnprocessable();
    }

    public function test_deactivating_an_account_does_not_delete_it(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACCOUNTS_CREATE, Permissions::ACCOUNTS_DEACTIVATE]);
        $account = Account::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson("/api/v1/accounts/{$account->id}/deactivate");
        $response->assertOk();
        $response->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'is_active' => false]);
    }

    public function test_creating_an_account_requires_the_accounts_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->postJson('/api/v1/accounts', ['code' => 'X', 'name' => 'X', 'type' => AccountType::EXPENSE])->assertForbidden();
    }

    public function test_viewing_accounts_requires_the_accounts_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/accounts')->assertForbidden();
    }
}
