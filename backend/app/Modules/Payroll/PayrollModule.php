<?php

declare(strict_types=1);

namespace App\Modules\Payroll;

use App\Modules\Module;

/**
 * Payroll runs (per-staff USD/KHR, Cambodia tax/NSSF).
 * See docs/architecture/multi-product.md (module registry).
 */
final class PayrollModule extends Module
{
    public function code(): string
    {
        return 'payroll';
    }

    public function label(): string
    {
        return 'Payroll';
    }

    public function labelKm(): string
    {
        return 'បើកប្រាក់បៀវត្សរ៍';
    }

    public function menus(): array
    {
        return [
            'payroll' => ['Payroll', 'បើកប្រាក់បៀវត្សរ៍'],
        ];
    }
}
