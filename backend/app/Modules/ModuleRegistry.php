<?php

declare(strict_types=1);

namespace App\Modules;

/**
 * Every module of the platform, in the order plans and menus show them
 * (docs/architecture/multi-product.md). A new module is added here.
 */
final class ModuleRegistry
{
    /** @var array<string, Module>|null */
    private static ?array $modules = null;

    /** @return array<string, Module> code => module */
    public static function all(): array
    {
        return self::$modules ??= collect([
            new Academics\AcademicsModule,
            new Finance\FinanceModule,
            new Accounting\AccountingModule,
            new Hrm\HrmModule,
            new Payroll\PayrollModule,
            new Assets\AssetsModule,
            new Website\WebsiteModule,
            new Projects\ProjectsModule,
            new FrontOffice\FrontOfficeModule,
        ])->keyBy(fn (Module $m) => $m->code())->all();
    }

    public static function get(string $code): ?Module
    {
        return self::all()[$code] ?? null;
    }
}
