<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicProgram;
use App\Models\Book;
use App\Models\BookCategory;
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
        $computer = AcademicProgram::factory()->create();

        $this->postJson('/api/v1/books', ['title' => 'Excel Fundamentals', 'academic_program_id' => $computer->id])->assertCreated();

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
        $computer = AcademicProgram::factory()->create();
        $office = BookCategory::factory()->create(['academic_program_id' => $computer->id]);
        $book = Book::factory()->create(['academic_program_id' => $computer->id]);

        $this->putJson("/api/v1/books/{$book->id}", ['book_category_id' => $office->id])
            ->assertOk()
            ->assertJsonPath('data.book_category.id', $office->id);

        $this->deleteJson("/api/v1/books/{$book->id}")->assertNoContent();
        $this->assertSoftDeleted('books', ['id' => $book->id]);
    }

    /**
     * There is no separate "Course" model — a book is tagged directly to the
     * one academic program it belongs to, which is what lets a Course
     * Package's book picker filter down to just its own program.
     */
    public function test_a_book_belongs_to_exactly_one_academic_program(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BOOKS_CREATE, Permissions::BOOKS_VIEW]);
        $computer = AcademicProgram::factory()->create(['code' => 'COM']);

        $bookId = $this->postJson('/api/v1/books', [
            'title' => 'MS Word', 'academic_program_id' => $computer->id,
        ])->assertCreated()->json('data.id');

        $book = Book::findOrFail($bookId);
        $this->assertSame($computer->id, $book->academic_program_id);

        $response = $this->getJson('/api/v1/books');
        $response->assertOk();
        $response->assertJsonPath('data.0.academic_program.code', 'COM');
    }

    public function test_a_book_cannot_be_created_without_an_academic_program(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BOOKS_CREATE]);

        $response = $this->postJson('/api/v1/books', ['title' => 'MS Word']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('academic_program_id');
    }
}
