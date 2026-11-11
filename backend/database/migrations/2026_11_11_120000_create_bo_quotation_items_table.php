<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing > Quotation items: the console's menus > submenus > tabs a
 * quotation picks from, each with a default price. Filled once from the
 * System sheet of docs/NTCSWEB-Modules.xlsx (database/data/quotation_items.json);
 * edited in the back office after that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('bo_quotation_items')->cascadeOnDelete();
            $table->string('level', 10); // menu | submenu | tab
            $table->string('code', 30)->nullable();
            $table->string('name', 150);
            $table->string('name_km', 150)->nullable();
            $table->string('path', 150)->nullable();
            $table->decimal('price_usd', 12, 2)->default(0);
            $table->decimal('price_khr', 14, 0)->default(0);
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['parent_id', 'sort_order']);
        });

        $now = now();
        $insert = function (array $node, string $level, ?int $parentId, int $sort) use (&$insert, $now): void {
            $id = DB::table('bo_quotation_items')->insertGetId([
                'parent_id' => $parentId,
                'level' => $level,
                'code' => $node['code'] ?? null,
                'name' => $node['name'] ?? ($node['code'] ?? '—'),
                'name_km' => $node['name_km'] ?? null,
                'path' => $node['path'] ?? null,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $next = ['menu' => 'submenu', 'submenu' => 'tab'][$level] ?? null;
            foreach ($node['children'] ?? [] as $i => $child) {
                if ($next !== null) {
                    $insert($child, $next, $id, $i + 1);
                }
            }
        };

        $menus = json_decode((string) file_get_contents(database_path('data/quotation_items.json')), true) ?: [];
        foreach ($menus as $i => $menu) {
            $insert($menu, 'menu', null, $i + 1);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_quotation_items');
    }
};
