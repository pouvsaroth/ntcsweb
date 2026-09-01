<?php

declare(strict_types=1);

namespace App\Support\Assets;

use App\Models\Classroom;
use App\Models\Department;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;

/**
 * The whitelist of model classes an AssetAssignment's polymorphic
 * `assignable` may point at — Staff, Student, a standalone User account,
 * a Department, or a Classroom/Lab (spec section 11's "Staff, Teacher,
 * Accountant, IT Officer, Department, Classroom, Lab, Student, Other").
 */
final class AssignableType
{
    /** @return array<string, class-string> label => FQCN, for a frontend picker's "assign to" type selector. */
    public static function options(): array
    {
        return [
            'staff' => Staff::class,
            'student' => Student::class,
            'user' => User::class,
            'department' => Department::class,
            'classroom' => Classroom::class,
        ];
    }

    /** @return list<class-string> */
    public static function all(): array
    {
        return array_values(self::options());
    }

    public static function isAllowed(string $class): bool
    {
        return in_array($class, self::all(), true);
    }
}
