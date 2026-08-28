<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Book;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class BookTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_creates_and_lists_books(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BOOKS_VIEW, Permissions::BOOKS_CREATE]);

        $this->postJson('/api/v1/books', ['title' => 'Excel Fundamentals', 'quantity' => 10])->assertCreated();

        $response = $this->getJson('/api/v1/books');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_it_searches_by_title(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BOOKS_VIEW]);
        Book::factory()->create(['title' => 'Excel Fundamentals']);
        Book::factory()->create(['title' => 'English Grammar']);

        $response = $this->getJson('/api/v1/books?search=Excel');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_it_updates_and_deletes_a_book(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BOOKS_UPDATE, Permissions::BOOKS_DELETE]);
        $book = Book::factory()->create(['quantity' => 5]);

        $this->putJson("/api/v1/books/{$book->id}", ['quantity' => 12])
            ->assertOk()
            ->assertJsonPath('data.quantity', 12);

        $this->deleteJson("/api/v1/books/{$book->id}")->assertNoContent();
        $this->assertSoftDeleted('books', ['id' => $book->id]);
    }
}
