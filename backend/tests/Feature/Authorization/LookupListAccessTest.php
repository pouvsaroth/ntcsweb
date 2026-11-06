<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** A list a form picks from is readable by whoever does that action, without the list's own menu. */
class LookupListAccessTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function pairs(): array
    {
        return [
            'expense form picks an account' => [Permissions::EXPENSE_CREATE, '/api/v1/accounts'],
            'pay expense picks an account' => [Permissions::EXPENSE_PAY, '/api/v1/accounts'],
            'income form picks an account' => [Permissions::INCOME_CREATE, '/api/v1/accounts'],
            'complete repair picks an account' => [Permissions::ASSET_REPAIRS_COMPLETE, '/api/v1/accounts'],
            'user form picks a role' => [Permissions::USERS_CREATE, '/api/v1/roles'],
            'position form picks a role' => [Permissions::POSITIONS_UPDATE, '/api/v1/roles'],
            'book form picks a program' => [Permissions::BOOKS_CREATE, '/api/v1/academic-programs'],
            'enrollment picks a program' => [Permissions::ENROLLMENTS_CREATE, '/api/v1/academic-programs'],
            'classroom form picks a building' => [Permissions::CLASSROOMS_CREATE, '/api/v1/buildings'],
            'video form picks a package' => [Permissions::VIDEOS_CREATE, '/api/v1/course-packages'],
            'change class picks a package' => [Permissions::ENROLLMENTS_TRANSFER, '/api/v1/course-packages'],
            'send to repair picks a shop' => [Permissions::ASSET_REPAIRS_CREATE, '/api/v1/repair-shops'],
            'class form picks a room' => [Permissions::CLASSES_UPDATE, '/api/v1/classrooms'],
        ];
    }

    #[DataProvider('pairs')]
    public function test_the_action_can_read_the_list_it_needs(string $permission, string $url): void
    {
        $this->actingAsAdminWithPermissions([$permission]);

        $this->getJson($url)->assertOk();
    }

    public function test_a_role_without_those_actions_still_cannot_read_the_lists(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);

        foreach (['/api/v1/accounts', '/api/v1/roles', '/api/v1/academic-programs', '/api/v1/buildings', '/api/v1/course-packages', '/api/v1/repair-shops', '/api/v1/classrooms'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }
}
