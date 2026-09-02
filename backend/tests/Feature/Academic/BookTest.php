<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicProgram;
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

    /**
     * There is no separate "Course" model — a book is tagged directly to the
     * academic program(s) it belongs to, which is what lets a Course
     * Package's book picker filter down to just its own program.
     */
    public function test_a_book_can_be_tagged_to_academic_programs(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BOOKS_CREATE, Permissions::BOOKS_VIEW]);
        $computer = AcademicProgram::factory()->create(['code' => 'COM']);
        $english = AcademicProgram::factory()->create(['code' => 'ENG']);

        $bookId = $this->postJson('/api/v1/books', [
            'title' => 'MS Word', 'program_ids' => [$computer->id],
        ])->assertCreated()->json('data.id');

        $book = Book::findOrFail($bookId);
        $this->assertTrue($book->programs()->whereKey($computer->id)->exists());
        $this->assertFalse($book->programs()->whereKey($english->id)->exists());

        $response = $this->getJson('/api/v1/books');
        $response->assertOk();
        $response->assertJsonPath('data.0.programs.0.code', 'COM');
    }
}
