<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicProgram;
use App\Models\Book;
use App\Models\CoursePackage;
use App\Models\Product;
use App\Support\Authorization\Permissions;
use App\Support\Billing\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * The spec's Computer-class situation, at the model level: one priced
 * package bundling several books, backed by exactly one Product row so the
 * existing billing/accounting machinery needs zero changes.
 */
class CoursePackageTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_creating_a_package_with_multiple_books_creates_exactly_one_course_fee_product(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::COURSE_PACKAGES_CREATE]);
        $program = AcademicProgram::factory()->create(['code' => 'COM']);
        $msWord = Book::factory()->create(['title' => 'MS Word']);
        $excel = Book::factory()->create(['title' => 'Excel']);
        $powerPoint = Book::factory()->create(['title' => 'PowerPoint']);
        $photoshop = Book::factory()->create(['title' => 'Photoshop']);

        $response = $this->postJson('/api/v1/course-packages', [
            'code' => 'MSWORD2024', 'name' => 'MS Word 2024', 'academic_program_id' => $program->id,
            'price' => 24, 'book_ids' => [$msWord->id, $excel->id, $powerPoint->id, $photoshop->id],
        ]);

        $response->assertCreated();
        $package = CoursePackage::firstOrFail();
        $this->assertSame(4, $package->books()->count());

        $this->assertSame(1, Product::where('type', ProductType::COURSE_FEE)->count());
        $product = Product::firstOrFail();
        $this->assertSame($package->product_id, $product->id);
        $this->assertSame('24.00', (string) $product->price);
        $this->assertSame('MS Word 2024', $product->name);
    }

    public function test_updating_a_package_keeps_its_product_in_sync(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::COURSE_PACKAGES_CREATE, Permissions::COURSE_PACKAGES_UPDATE]);
        $program = AcademicProgram::factory()->create();
        $book = Book::factory()->create();

        $packageId = $this->postJson('/api/v1/course-packages', [
            'code' => 'PKG1', 'name' => 'Package 1', 'academic_program_id' => $program->id,
            'price' => 20, 'book_ids' => [$book->id],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/course-packages/{$packageId}", ['name' => 'Package 1 Renamed', 'is_active' => false]);

        $package = CoursePackage::findOrFail($packageId);
        $this->assertSame('Package 1 Renamed', $package->product->name);
        $this->assertFalse((bool) $package->product->is_active);
    }

    public function test_a_client_supplied_product_id_is_ignored_and_never_mass_assigned(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::COURSE_PACKAGES_CREATE]);
        $program = AcademicProgram::factory()->create();
        $book = Book::factory()->create();
        $decoyProduct = Product::factory()->create();

        $this->postJson('/api/v1/course-packages', [
            'code' => 'PKG1', 'name' => 'Package 1', 'academic_program_id' => $program->id,
            'price' => 20, 'book_ids' => [$book->id], 'product_id' => $decoyProduct->id,
        ])->assertCreated();

        $package = CoursePackage::firstOrFail();
        $this->assertNotSame($decoyProduct->id, $package->product_id);
    }
}
