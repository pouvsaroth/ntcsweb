<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicProgram;
use App\Models\BookCategory;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class BookCategoryTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_creates_and_lists_book_categories(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BOOK_CATEGORIES_VIEW, Permissions::BOOK_CATEGORIES_CREATE]);
        $computer = AcademicProgram::factory()->create(['code' => 'COM']);

        $this->postJson('/api/v1/book-categories', ['name' => 'Office', 'academic_program_id' => $computer->id])
            ->assertCreated()
            ->assertJsonPath('data.academic_program.code', 'COM');

        $response = $this->getJson('/api/v1/book-categories');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_a_category_cannot_be_created_without_an_academic_program(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BOOK_CATEGORIES_CREATE]);

        $response = $this->postJson('/api/v1/book-categories', ['name' => 'Office']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('academic_program_id');
    }

    public function test_the_same_name_can_repeat_across_different_programs_but_not_within_one(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BOOK_CATEGORIES_CREATE]);
        $computer = AcademicProgram::factory()->create(['code' => 'COM']);
        $english = AcademicProgram::factory()->create(['code' => 'ENG']);
        BookCategory::factory()->create(['name' => 'Other', 'academic_program_id' => $computer->id]);

        $this->postJson('/api/v1/book-categories', ['name' => 'Other', 'academic_program_id' => $english->id])
            ->assertCreated();

        $this->postJson('/api/v1/book-categories', ['name' => 'Other', 'academic_program_id' => $computer->id])
            ->assertUnprocessable();
    }

    public function test_it_updates_and_deletes_a_book_category(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BOOK_CATEGORIES_UPDATE, Permissions::BOOK_CATEGORIES_DELETE]);
        $category = BookCategory::factory()->create(['name' => 'Office']);

        $this->putJson("/api/v1/book-categories/{$category->id}", ['name' => 'Business Office'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Business Office');

        $this->deleteJson("/api/v1/book-categories/{$category->id}")->assertNoContent();
        $this->assertSoftDeleted('book_categories', ['id' => $category->id]);
    }

    public function test_a_book_can_be_tagged_to_a_category_belonging_to_its_own_program(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BOOKS_CREATE, Permissions::BOOKS_VIEW]);
        $computer = AcademicProgram::factory()->create(['code' => 'COM']);
        $office = BookCategory::factory()->create(['name' => 'Office', 'academic_program_id' => $computer->id]);

        $response = $this->postJson('/api/v1/books', [
            'title' => 'MS Word', 'academic_program_id' => $computer->id, 'book_category_id' => $office->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.book_category.name', 'Office');
    }

    public function test_a_book_cannot_be_tagged_to_a_category_from_a_different_program(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BOOKS_CREATE]);
        $computer = AcademicProgram::factory()->create(['code' => 'COM']);
        $english = AcademicProgram::factory()->create(['code' => 'ENG']);
        $kindergarten = BookCategory::factory()->create(['name' => 'Kindergarten 1', 'academic_program_id' => $english->id]);

        $response = $this->postJson('/api/v1/books', [
            'title' => 'MS Word', 'academic_program_id' => $computer->id, 'book_category_id' => $kindergarten->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('book_category_id');
    }
}
