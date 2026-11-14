<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money in its own currency: products (variants follow their product),
 * website programs (fee), assets (purchase / current / disposal value),
 * asset repairs, asset maintenance and trainings (cost) each get USD/KHR.
 * Until now these screens always showed "$", so existing rows are USD; new
 * rows start on the school's currency (HasCurrency).
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['products', 'programs', 'assets', 'asset_repairs', 'asset_maintenances', 'trainings'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            if (Schema::hasColumn($name, 'currency')) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) {
                $table->string('currency', 3)->default('USD');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            if (Schema::hasColumn($name, 'currency')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn('currency'));
            }
        }
    }
};
