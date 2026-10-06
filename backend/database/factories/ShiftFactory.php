<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('SH???')),
            'name' => 'Day shift',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'break_minutes' => 60,
            'late_grace_minutes' => 10,
            'early_leave_grace_minutes' => 0,
        ];
    }
}
