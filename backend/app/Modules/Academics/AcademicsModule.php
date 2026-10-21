<?php

declare(strict_types=1);

namespace App\Modules\Academics;

use App\Models\Tenant;
use App\Modules\Module;
use Database\Seeders\TeacherPositionSeeder;

/**
 * The school: programs, classes, students, enrollments, attendance, exams.
 * See docs/architecture/multi-product.md (module registry).
 */
final class AcademicsModule extends Module
{
    public function code(): string
    {
        return 'academics';
    }

    public function label(): string
    {
        return 'Academics';
    }

    public function labelKm(): string
    {
        return 'សិក្សា';
    }

    public function menus(): array
    {
        return [
            'academic-programs' => ['Academic programs', 'កម្មវិធីសិក្សា'],
            'academic-years' => ['Academic years', 'ឆ្នាំសិក្សា'],
            'academic-reports' => ['Academic reports', 'របាយការណ៍សិក្សា'],
            'programs' => ['Programs', 'កម្មវិធី'],
            'course-packages' => ['Course packages', 'កញ្ចប់វគ្គសិក្សា'],
            'classes' => ['Classes', 'ថ្នាក់'],
            'classrooms' => ['Classrooms', 'បន្ទប់រៀន'],
            'buildings' => ['Buildings', 'អគារ'],
            'students' => ['Students', 'សិស្ស'],
            'enrollments' => ['Enrollments', 'ការចុះឈ្មោះចូលរៀន'],
            'attendance' => ['Student attendance', 'វត្តមានសិស្ស'],
            'make-up-class-requests' => ['Make-up class requests', 'សំណើរៀនសង'],
            'exam-applications' => ['Exam applications', 'ពាក្យប្រឡង'],
            'exam-scores' => ['Exam scores', 'ពិន្ទុប្រឡង'],
            'examination' => ['Examination', 'ការប្រឡង'],
            'student-feedback' => ['Student feedback', 'មតិសិស្ស'],
        ];
    }

    public function dashboardPermissions(): array
    {
        return [
            'dashboard.cards.register-student',
            'dashboard.cards.enrollment',
            'dashboard.cards.classes',
            'dashboard.cards.student-attendance',
            'dashboard.cards.registration-pending',
            'dashboard.attendance.view',
        ];
    }

    /** A class's teacher is a Staff member holding the "Teacher" position. */
    public function seed(Tenant $tenant): void
    {
        (new TeacherPositionSeeder)->ensureTeacherPosition($tenant);
    }
}
