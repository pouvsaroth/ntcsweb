<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * One email / one phone = one account across every customer.
 */
class UniqueAccountIdentityTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function juniorRole(User $admin): Role
    {
        $admin->cachedRoles()->first()->update(['level' => 80]);
        $admin->forgetPermissionCache();

        return Role::factory()->forTenant($this->tenant)->create(['level' => 40]);
    }

    public function test_an_email_or_phone_used_by_another_customer_is_refused(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_CREATE, Permissions::ROLES_ASSIGN]);
        $role = $this->juniorRole($admin);
        $other = Tenant::factory()->create(['slug' => 'other-school']);
        User::factory()->forTenant($other)->create(['email' => 'dara@example.com', 'phone' => '012345678']);

        // Case and spaces do not matter.
        $this->postJson('/api/v1/users', ['name' => 'A', 'phone' => '099 111 222', 'email' => 'Dara@Example.com', 'role_id' => $role->id])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/users', ['name' => 'A', 'phone' => '012 345 678', 'role_id' => $role->id])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->postJson('/api/v1/users', ['name' => 'A', 'phone' => '099 111 222', 'email' => 'new@example.com', 'role_id' => $role->id])
            ->assertCreated();
    }

    public function test_a_repeat_inside_the_same_customer_is_a_clear_message_not_an_error(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::USERS_CREATE, Permissions::ROLES_ASSIGN]);
        $role = $this->juniorRole($admin);
        User::factory()->forTenant($this->tenant)->create(['phone' => '011223344']);

        $this->postJson('/api/v1/users', ['name' => 'A', 'phone' => '011-223-344', 'role_id' => $role->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'This phone number already has an account.']);
    }

    public function test_editing_a_user_may_keep_their_own_email_and_phone_but_not_take_another(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::USERS_UPDATE]);
        $user = User::factory()->forTenant($this->tenant)->create(['email' => 'me@example.com', 'phone' => '015000111']);
        $other = Tenant::factory()->create(['slug' => 'other-school']);
        User::factory()->forTenant($other)->create(['email' => 'taken@example.com']);

        $this->putJson("/api/v1/users/{$user->id}", ['name' => 'Me', 'email' => 'me@example.com', 'phone' => '015000111'])->assertOk();
        $this->putJson("/api/v1/users/{$user->id}", ['name' => 'Me', 'email' => 'taken@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_my_profile_phone_cannot_be_another_customers(): void
    {
        $me = $this->actingAsAdminWithPermissions([]);
        $other = Tenant::factory()->create(['slug' => 'other-school']);
        User::factory()->forTenant($other)->create(['phone' => '017888999']);

        $this->postJson('/api/v1/auth/me', ['name' => $me->name, 'phone' => '017 888 999'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }
}
