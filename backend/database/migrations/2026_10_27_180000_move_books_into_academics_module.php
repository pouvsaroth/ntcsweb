<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Books and Book categories move from the Library module into Academics
 * (courses, timetable and curriculum use them), and the Library module goes.
 * Plans and systems drop "library"; a plan that lists its menus and has
 * Academics (or had Library) gets the two book menus.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('bo_plans')->get(['id', 'modules', 'menus']) as $plan) {
            $modules = json_decode((string) $plan->modules, true) ?: [];
            $menus = $plan->menus === null ? null : (json_decode((string) $plan->menus, true) ?: []);
            $hadLibrary = in_array('library', $modules, true);
            $modules = array_values(array_diff($modules, ['library']));
            if ($hadLibrary && ! in_array('academics', $modules, true)) {
                array_unshift($modules, 'academics');
            }
            if ($menus !== null && in_array('academics', $modules, true)) {
                $menus = array_values(array_unique([...$menus, 'books', 'book-categories']));
            }
            DB::table('bo_plans')->where('id', $plan->id)->update([
                'modules' => json_encode($modules),
                'menus' => $menus === null ? null : json_encode($menus),
            ]);
        }

        foreach (DB::table('bo_products')->get(['id', 'modules']) as $product) {
            $modules = json_decode((string) $product->modules, true) ?: [];
            if (in_array('library', $modules, true)) {
                DB::table('bo_products')->where('id', $product->id)->update(['modules' => json_encode(array_values(array_diff($modules, ['library'])))]);
            }
        }
    }

    public function down(): void
    {
        // The Library module no longer exists in code; nothing to put back.
    }
};
