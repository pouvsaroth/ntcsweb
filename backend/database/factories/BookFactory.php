<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Book;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    protected $model = Book::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'author' => fake()->name(),
            'isbn' => fake()->unique()->numerify('978-##########'),
            'publisher' => fake()->company(),
            'description' => fake()->paragraph(),
            'cover_image' => null,
            'quantity' => fake()->numberBetween(1, 20),
            'status' => Book::STATUS_ACTIVE,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Book $book) use ($tenant) {
            $book->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
