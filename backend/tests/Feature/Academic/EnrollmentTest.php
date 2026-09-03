<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Book;
use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /**
     * Creates a class and puts $books "on the menu" via class_book.
     */
    private function classOffering(Book ...$books): SchoolClass
    {
        $class = SchoolClass::factory()->create();
        $class->books()->attach(array_map(fn (Book $book) => $book->id, $books));

        return $class;
    }

    public function test_it_enrolls_a_student_into_a_book_offered_by_the_class(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $student = Student::factory()->create();
        $book = Book::factory()->create();
        $class = $this->classOffering($book);

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $student->id,
            'class_id' => $class->id,
            'book_id' => $book->id,
            'enrolled_at' => '2026-01-15',
            'fee' => 25,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.student.id', $student->id);
        $response->assertJsonPath('data.class.id', $class->id);
        $response->assertJsonPath('data.book.id', $book->id);
        $response->assertJsonPath('data.fee', 25);
    }

    /**
     * The actual scenario this feature exists for: two students share the
     * same class session but each take a different book at a different fee.
     */
    public function test_two_students_can_share_a_class_session_on_different_books_at_different_fees(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $excel = Book::factory()->create(['title' => 'Excel']);
        $word = Book::factory()->create(['title' => 'Word']);
        $class = $this->classOffering($excel, $word);
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        $this->postJson('/api/v1/enrollments', [
            'student_id' => $studentA->id, 'class_id' => $class->id, 'book_id' => $excel->id,
            'enrolled_at' => '2026-01-15', 'fee' => 30,
        ])->assertCreated();

        $this->postJson('/api/v1/enrollments', [
            'student_id' => $studentB->id, 'class_id' => $class->id, 'book_id' => $word->id,
            'enrolled_at' => '2026-01-15', 'fee' => 20,
        ])->assertCreated();

        $this->assertSame(2, Enrollment::where('class_id', $class->id)->count());
    }

    public function test_a_student_can_take_two_books_within_the_same_class_session(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $excel = Book::factory()->create();
        $word = Book::factory()->create();
        $class = $this->classOffering($excel, $word);
        $student = Student::factory()->create();

        $this->postJson('/api/v1/enrollments', [
            'student_id' => $student->id, 'class_id' => $class->id, 'book_id' => $excel->id,
            'enrolled_at' => '2026-01-15', 'fee' => 30,
        ])->assertCreated();

        $this->postJson('/api/v1/enrollments', [
            'student_id' => $student->id, 'class_id' => $class->id, 'book_id' => $word->id,
            'enrolled_at' => '2026-01-15', 'fee' => 20,
        ])->assertCreated();

        $this->assertSame(2, Enrollment::where('student_id', $student->id)->count());
    }

    public function test_a_student_cannot_be_enrolled_in_the_same_book_of_the_same_class_twice(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $student = Student::factory()->create();
        $book = Book::factory()->create();
        $class = $this->classOffering($book);
        Enrollment::factory()->forStudent($student)->forClass($class)->forBook($book)->create();

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $student->id, 'class_id' => $class->id, 'book_id' => $book->id,
            'enrolled_at' => '2026-01-15', 'fee' => 25,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('book_id');
    }

    public function test_a_book_not_offered_by_the_class_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $student = Student::factory()->create();
        $offeredBook = Book::factory()->create();
        $unrelatedBook = Book::factory()->create();
        $class = $this->classOffering($offeredBook);

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $student->id, 'class_id' => $class->id, 'book_id' => $unrelatedBook->id,
            'enrolled_at' => '2026-01-15', 'fee' => 25,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('book_id');
    }

    public function test_the_same_student_may_enroll_in_the_class_again_at_a_different_school(): void
    {
        $otherTenant = Tenant::factory()->create();
        $this->createForOtherTenant(function () use ($otherTenant) {
            $student = Student::factory()->forTenant($otherTenant)->create();
            $book = Book::factory()->forTenant($otherTenant)->create();
            $class = SchoolClass::factory()->forTenant($otherTenant)->create();
            $class->books()->attach($book->id);
            Enrollment::factory()->forTenant($otherTenant)->forStudent($student)->forClass($class)->forBook($book)->create();
        });

        // Different school entirely — its own student_id/class_id/book_id
        // numbering could coincidentally collide with the other tenant's,
        // and must not.
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $myStudent = Student::factory()->create();
        $myBook = Book::factory()->create();
        $myClass = $this->classOffering($myBook);

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $myStudent->id, 'class_id' => $myClass->id, 'book_id' => $myBook->id,
            'enrolled_at' => '2026-01-15', 'fee' => 25,
        ]);

        $response->assertCreated();
    }

    public function test_an_enrollment_cannot_reference_a_student_from_another_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $foreignStudent = $this->createForOtherTenant(fn () => Student::factory()->forTenant(Tenant::factory()->create())->create());
        $book = Book::factory()->create();
        $class = $this->classOffering($book);

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $foreignStudent->id, 'class_id' => $class->id, 'book_id' => $book->id,
            'enrolled_at' => '2026-01-15', 'fee' => 25,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('student_id');
    }

    public function test_it_updates_enrollment_status(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_UPDATE]);
        $enrollment = Enrollment::factory()->create(['status' => Enrollment::STATUS_ACTIVE]);

        $response = $this->putJson("/api/v1/enrollments/{$enrollment->id}", ['status' => Enrollment::STATUS_DROPPED]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'dropped');
    }

    public function test_an_enrollments_fee_can_be_adjusted_for_a_discount(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_UPDATE]);
        $enrollment = Enrollment::factory()->create(['fee' => 30]);

        $response = $this->putJson("/api/v1/enrollments/{$enrollment->id}", ['fee' => 15]);

        $response->assertOk();
        $response->assertJsonPath('data.fee', 15);
    }

    public function test_a_class_with_no_classroom_does_not_require_a_table(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $student = Student::factory()->create();
        $book = Book::factory()->create();
        $class = $this->classOffering($book); // classroom_id is null by default

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $student->id, 'class_id' => $class->id, 'book_id' => $book->id,
            'enrolled_at' => '2026-01-15', 'fee' => 25,
        ]);

        $response->assertCreated();
    }

    public function test_a_class_whose_room_has_no_tables_configured_does_not_require_a_table(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $student = Student::factory()->create();
        $book = Book::factory()->create();
        $room = Classroom::factory()->create();
        $class = SchoolClass::factory()->inRoom($room)->create();
        $class->books()->attach($book->id);

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $student->id, 'class_id' => $class->id, 'book_id' => $book->id,
            'enrolled_at' => '2026-01-15', 'fee' => 25,
        ]);

        $response->assertCreated();
    }

    public function test_a_table_is_required_once_the_classroom_has_tables(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $student = Student::factory()->create();
        $book = Book::factory()->create();
        $room = Classroom::factory()->create();
        ClassroomTable::factory()->create(['classroom_id' => $room->id, 'name' => 'Table 1']);
        $class = SchoolClass::factory()->inRoom($room)->create();
        $class->books()->attach($book->id);

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $student->id, 'class_id' => $class->id, 'book_id' => $book->id,
            'enrolled_at' => '2026-01-15', 'fee' => 25,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('table_id');
    }

    public function test_two_students_cannot_share_the_same_table_in_one_class(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $book = Book::factory()->create();
        $room = Classroom::factory()->create();
        $table = ClassroomTable::factory()->create(['classroom_id' => $room->id]);
        $class = SchoolClass::factory()->inRoom($room)->create();
        $class->books()->attach($book->id);
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        $this->postJson('/api/v1/enrollments', [
            'student_id' => $studentA->id, 'class_id' => $class->id, 'book_id' => $book->id, 'table_id' => $table->id,
            'enrolled_at' => '2026-01-15', 'fee' => 25,
        ])->assertCreated();

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $studentB->id, 'class_id' => $class->id, 'book_id' => $book->id, 'table_id' => $table->id,
            'enrolled_at' => '2026-01-15', 'fee' => 25,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('table_id');
    }

    public function test_dropping_an_enrollment_frees_its_table_for_reuse(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_UPDATE]);
        $book = Book::factory()->create();
        $room = Classroom::factory()->create();
        $table = ClassroomTable::factory()->create(['classroom_id' => $room->id]);
        $class = SchoolClass::factory()->inRoom($room)->create();
        $class->books()->attach($book->id);
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        $first = $this->postJson('/api/v1/enrollments', [
            'student_id' => $studentA->id, 'class_id' => $class->id, 'book_id' => $book->id, 'table_id' => $table->id,
            'enrolled_at' => '2026-01-15', 'fee' => 25,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/enrollments/{$first}", ['status' => Enrollment::STATUS_DROPPED])->assertOk();

        $this->postJson('/api/v1/enrollments', [
            'student_id' => $studentB->id, 'class_id' => $class->id, 'book_id' => $book->id, 'table_id' => $table->id,
            'enrolled_at' => '2026-01-15', 'fee' => 25,
        ])->assertCreated();
    }

    public function test_a_table_from_a_different_classroom_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $student = Student::factory()->create();
        $book = Book::factory()->create();
        $room = Classroom::factory()->create();
        ClassroomTable::factory()->create(['classroom_id' => $room->id]);
        $class = SchoolClass::factory()->inRoom($room)->create();
        $class->books()->attach($book->id);
        $otherRoomTable = ClassroomTable::factory()->create();

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $student->id, 'class_id' => $class->id, 'book_id' => $book->id, 'table_id' => $otherRoomTable->id,
            'enrolled_at' => '2026-01-15', 'fee' => 25,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('table_id');
    }
}
