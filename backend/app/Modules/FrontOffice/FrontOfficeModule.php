<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice;

use App\Modules\Module;

/**
 * The front desk: inquiries, meetings, appointments
 * and the visitor log. See docs/architecture/multi-product.md (module registry).
 */
final class FrontOfficeModule extends Module
{
    public function code(): string
    {
        return 'front-office';
    }

    public function label(): string
    {
        return 'Front Office';
    }

    public function labelKm(): string
    {
        return 'ការិយាល័យទទួលភ្ញៀវ';
    }

    public function menus(): array
    {
        return [
            'inquiries' => ['Inquiries', 'ការសាកសួរព័ត៌មាន'],
            'meetings' => ['Meetings', 'កិច្ចប្រជុំ'],
            'appointments' => ['Appointments', 'ការណាត់ជួប'],
            'visitors' => ['Visitors', 'ភ្ញៀវមកទស្សនា'],
        ];
    }
}
