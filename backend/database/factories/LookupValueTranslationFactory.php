<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Language;
use App\Models\LookupValue;
use App\Models\LookupValueTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LookupValueTranslation>
 */
class LookupValueTranslationFactory extends Factory
{
    protected $model = LookupValueTranslation::class;

    public function definition(): array
    {
        return [
            'lookup_value_id' => LookupValue::factory(),
            'language_id' => Language::factory(),
            'name' => fake()->word(),
            'description' => null,
        ];
    }

    public function forValue(LookupValue $value): static
    {
        return $this->state(['lookup_value_id' => $value->getKey()]);
    }

    public function forLanguage(Language $language): static
    {
        return $this->state(['language_id' => $language->getKey()]);
    }
}
