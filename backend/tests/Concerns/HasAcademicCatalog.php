<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\AcademicProgram;
use App\Models\Book;
use App\Models\CoursePackage;
use App\Models\Product;
use App\Models\SchoolClass;
use App\Support\Billing\ProductType;

/**
 * A minimal Program/Book/Package/Class catalog for the "Computer / MS Word
 * 2024" scenario used across the enrollment-by-package tests. Pairs with
 * HasAcademicAdmin: call actingAsAdminWithPermissions() first so a tenant
 * context is active, then setUpAcademicCatalog() — every factory here
 * relies on that ambient tenant, same as the rest of this suite.
 *
 * There is no separate "Course" model — a Course Package bundles Books
 * directly (a Book already is "a subject a student can take, with a fee").
 */
trait HasAcademicCatalog
{
    protected AcademicProgram $computerProgram;

    protected Book $msWordBook;

    protected Book $excelBook;

    protected CoursePackage $msWordPackage;

    protected SchoolClass $computerEveningClass;

    protected function setUpAcademicCatalog(): void
    {
        $this->computerProgram = AcademicProgram::factory()->create(['code' => 'COM', 'name' => 'Computer']);

        $this->msWordBook = Book::factory()->create(['title' => 'MS Word', 'academic_program_id' => $this->computerProgram->getKey()]);
        $this->excelBook = Book::factory()->create(['title' => 'Excel', 'academic_program_id' => $this->computerProgram->getKey()]);

        $product = Product::factory()->create([
            'code' => 'MSWORD2024',
            'name' => 'MS Word 2024',
            'type' => ProductType::COURSE_FEE,
            'price' => 24,
        ]);

        // All 5 fee tiers are populated (not just the legacy `price`) so
        // enrollment tests can exercise `fee_type` selection against real
        // data — `price` stays equal to `fee_term` since that's the
        // enrollment form's own default tier.
        $this->msWordPackage = CoursePackage::factory()
            ->forProgram($this->computerProgram)
            ->create([
                'code' => 'MSWORD2024', 'name' => 'MS Word 2024', 'price' => 24,
                'fee_monthly' => 20, 'fee_term' => 24, 'fee_video' => 15,
                'fee_monthly_online' => 18, 'fee_term_online' => 22,
                'product_id' => $product->getKey(),
            ]);
        $this->msWordPackage->books()->sync([$this->msWordBook->getKey(), $this->excelBook->getKey()]);

        $this->computerEveningClass = SchoolClass::factory()
            ->forProgram($this->computerProgram)
            ->create(['name' => 'Computer Evening A']);
        $this->computerEveningClass->coursePackages()->sync([$this->msWordPackage->getKey()]);
    }
}
