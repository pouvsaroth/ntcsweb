<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicProgram;
use App\Models\BookCategory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookCategory>
 */
class BookCategoryFactory extends Factory
{
    protected $model = BookCategory::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Office', 'Design', 'Programming', 'Language', 'Kindergarten 1']),
            'academic_program_id' => AcademicProgram::factory(),
            'is_active' => true,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (BookCategory $bookCategory) use ($tenant) {
            $bookCategory->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
