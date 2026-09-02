<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

/**
 * Platform-global, idempotent — safe to run on every deploy. English is the
 * initial default; nothing here touches the flag if a school has already
 * changed it.
 */
class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'sort_order' => 0],
            ['code' => 'km', 'name' => 'Khmer', 'native_name' => 'ភាសាខ្មែរ', 'sort_order' => 1],
            ['code' => 'zh', 'name' => 'Chinese', 'native_name' => '中文', 'sort_order' => 2],
            ['code' => 'ko', 'name' => 'Korean', 'native_name' => '한국어', 'sort_order' => 3],
            ['code' => 'ja', 'name' => 'Japanese', 'native_name' => '日本語', 'sort_order' => 4],
        ];

        foreach ($languages as $data) {
            Language::query()->firstOrCreate(['code' => $data['code']], $data);
        }

        if (! Language::query()->where('is_default', true)->exists()) {
            Language::query()->where('code', 'en')->update(['is_default' => true]);
        }
    }
}
