<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\CoursePackage;
use App\Models\Product;
use App\Models\ProgramOffering;
use App\Models\SchoolClass;
use App\Models\StudyMode;
use App\Support\Billing\ProductType;

/**
 * A minimal Program/Book/Package/Class/Offering catalog for the "Computer /
 * Part Time / MS Word 2024" scenario used across the enrollment-by-package
 * tests. Pairs with HasAcademicAdmin: call actingAsAdminWithPermissions()
 * first so a tenant context is active, then setUpAcademicCatalog() — every
 * factory here relies on that ambient tenant, same as the rest of this suite.
 *
 * There is no separate "Course" model — a Course Package bundles Books
 * directly (a Book already is "a subject a student can take, with a fee").
 */
trait HasAcademicCatalog
{
    protected AcademicProgram $computerProgram;

    protected StudyMode $partTimeMode;

    protected AcademicYear $year2026;

    protected ProgramOffering $computerPartTimeOffering;

    protected Book $msWordBook;

    protected Book $excelBook;

    protected CoursePackage $msWordPackage;

    protected SchoolClass $computerEveningClass;

    protected function setUpAcademicCatalog(): void
    {
        $this->computerProgram = AcademicProgram::factory()->create(['code' => 'COM', 'name' => 'Computer']);
        $this->partTimeMode = StudyMode::factory()->create(['code' => StudyMode::PART_TIME, 'name' => 'Part Time']);
        $this->year2026 = AcademicYear::factory()->create(['name' => '2026']);
        $this->computerPartTimeOffering = ProgramOffering::factory()
            ->forProgram($this->computerProgram)->forStudyMode($this->partTimeMode)->forAcademicYear($this->year2026)
            ->create();

        $this->msWordBook = Book::factory()->create(['title' => 'MS Word']);
        $this->excelBook = Book::factory()->create(['title' => 'Excel']);
        $this->computerProgram->books()->sync([$this->msWordBook->getKey(), $this->excelBook->getKey()]);

        $product = Product::factory()->create([
            'code' => 'MSWORD2024',
            'name' => 'MS Word 2024',
            'type' => ProductType::COURSE_FEE,
            'price' => 24,
        ]);

        $this->msWordPackage = CoursePackage::factory()
            ->forProgram($this->computerProgram)
            ->create(['code' => 'MSWORD2024', 'name' => 'MS Word 2024', 'price' => 24, 'product_id' => $product->getKey()]);
        $this->msWordPackage->books()->sync([$this->msWordBook->getKey(), $this->excelBook->getKey()]);

        $this->computerEveningClass = SchoolClass::factory()
            ->withProgramOffering($this->computerPartTimeOffering)
            ->create(['name' => 'Computer Evening A']);
        $this->computerEveningClass->coursePackages()->sync([$this->msWordPackage->getKey()]);
    }
}
