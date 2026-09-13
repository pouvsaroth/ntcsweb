<?php

declare(strict_types=1);

namespace Tests\Feature\BaseData;

use App\Models\LookupCategory;
use App\Models\LookupValue;
use Database\Seeders\BaseDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class BaseDataSeederTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_is_safe_to_run_more_than_once(): void
    {
        $this->actingAsAdminWithPermissions([]);

        (new BaseDataSeeder())->run();
        (new BaseDataSeeder())->run();

        $this->assertDatabaseHas('lookup_values', ['code' => 'graduated'], 'tenant');
    }

    /**
     * Regression test: a plain (non-partial) unique index on
     * (lookup_category_id, code) plus SoftDeletes on LookupValue means a
     * school deactivating one of these must not make a later re-seed crash
     * trying to re-insert it — see BaseDataSeeder's own docblock on
     * "deactivate one it doesn't want" and the withTrashed() fix.
     */
    public function test_rerunning_does_not_resurrect_or_crash_on_a_deactivated_value(): void
    {
        $this->actingAsAdminWithPermissions([]);
        (new BaseDataSeeder())->run();

        $category = LookupCategory::query()->where('code', 'STUDENT_STATUS')->firstOrFail();
        $value = LookupValue::query()->where('lookup_category_id', $category->id)->where('code', 'graduated')->firstOrFail();
        $value->delete();

        (new BaseDataSeeder())->run();

        $this->assertSoftDeleted('lookup_values', ['id' => $value->id], connection: 'tenant');
    }
}
