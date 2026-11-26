<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Six communes that are in the NEC's 2022 election office lists and 2027 seat
 * declarations but were missing from our Cambodia places list (pumi dataset).
 * Each goes under the district of ours that already holds its neighbours, with
 * the next free code in that district. Latin names are our own transliteration.
 *
 * Existing databases get them here; a fresh database gets them from
 * database/data/cambodia/communes.json through CambodiaGeographySeeder (this
 * migration does nothing while the places tables are still empty).
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string}> district code, commune code, Khmer, Latin */
    private const COMMUNES = [
        ['000110', '11004', 'សង្កាត់អូរជ្រៅ', 'Ou Chrov Sangkat'],
        ['000110', '11005', 'សង្កាត់អូរឫស្សី', 'Ou Ruessei Sangkat'],
        ['000204', '20409', 'បួវិល', 'Buo Vil'],
        ['000406', '40614', 'ភ្នំក្រាំងដីមាស', 'Phnum Krang Dei Meas'],
        ['000506', '50614', 'យាយម៉ៅពេជ្រនិល', 'Yeay Mau Pech Nil'],
        ['001004', '100411', 'អូរកណ្ដៀរសែនជ័យ', 'Ou Kandear Saen Chey'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::COMMUNES as [$district, $code, $km, $latin]) {
            $districtId = DB::table('districts')->where('code', $district)->value('id');
            if ($districtId === null || DB::table('communes')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('communes')->insert([
                'district_id' => $districtId, 'code' => $code, 'name_km' => $km, 'name_latin' => $latin,
                'unit_km' => 'ឃុំ', 'unit_latin' => 'Khum', 'unit_en' => 'Commune',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('communes')->whereIn('code', array_column(self::COMMUNES, 1))->delete();
    }
};
