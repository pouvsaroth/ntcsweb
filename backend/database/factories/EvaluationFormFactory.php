<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EvaluationForm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvaluationForm>
 */
class EvaluationFormFactory extends Factory
{
    protected $model = EvaluationForm::class;

    public function definition(): array
    {
        return [
            'name' => 'Teacher evaluation',
        ];
    }
}
