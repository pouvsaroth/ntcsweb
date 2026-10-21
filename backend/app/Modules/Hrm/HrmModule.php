<?php

declare(strict_types=1);

namespace App\Modules\Hrm;

use App\Modules\Module;

/**
 * Staff, staff attendance, leave, recruitment, performance, HR requests.
 * See docs/architecture/multi-product.md (module registry).
 */
final class HrmModule extends Module
{
    public function code(): string
    {
        return 'hrm';
    }

    public function label(): string
    {
        return 'HRM';
    }

    public function labelKm(): string
    {
        return 'ធនធានមនុស្ស';
    }

    public function menus(): array
    {
        return [
            'staff' => ['Staff', 'បុគ្គលិក'],
            'staff-attendance' => ['Staff attendance', 'វត្តមានបុគ្គលិក'],
            'positions' => ['Positions', 'មុខតំណែង'],
            'leave-management' => ['Leave management', 'គ្រប់គ្រងការឈប់សម្រាក'],
            'leave-requests' => ['Leave requests', 'សំណើឈប់សម្រាក'],
            'recruitment' => ['Recruitment', 'ការជ្រើសរើសបុគ្គលិក'],
            'resignation-requests' => ['Resignation requests', 'សំណើលាលែង'],
            'performance' => ['Performance', 'ការវាយតម្លៃការងារ'],
            'overtime-requests' => ['Overtime requests', 'សំណើម៉ោងបន្ថែម'],
            'manpower-requests' => ['Manpower requests', 'សំណើបុគ្គលិកបន្ថែម'],
            'attendance-corrections' => ['Attendance corrections', 'កែតម្រូវវត្តមាន'],
        ];
    }

    public function dashboardPermissions(): array
    {
        return [
            'dashboard.cards.teacher-attendance',
        ];
    }
}
