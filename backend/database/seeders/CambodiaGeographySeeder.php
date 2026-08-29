<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Cambodia's official administrative hierarchy — province > district >
 * commune > village — for the student registration form's cascading
 * address selects. See the `create_cambodia_geography_tables` migration for
 * schema and provenance.
 *
 * Idempotent, but not by diffing: this data never changes, so the entire
 * run is skipped once `provinces` is non-empty rather than re-checking
 * ~16,000 rows on every deploy.
 *
 * Raw inserts, not `Model::create()` in a loop — the smallest table here is
 * 25 rows, the largest is 14,372; looping `create()` would mean that many
 * individual queries (plus timestamps/casts overhead) for data that only
 * needs to land in the table once. IDs are left to Postgres's own
 * auto-increment rather than assigned by hand — a bulk insert that sets
 * `id` explicitly desyncs the table's sequence, so a later normal insert
 * (e.g. from Eloquent) would collide with an id this seeder already used.
 */
class CambodiaGeographySeeder extends Seeder
{
    private const CHUNK_SIZE = 1000;

    public function run(): void
    {
        if (DB::table('provinces')->exists()) {
            return;
        }

        $now = now();

        $provinceIds = $this->insertLevel('provinces', null, $now);
        $districtIds = $this->insertLevel('districts', ['province_code' => $provinceIds], $now, 'province_id');
        $communeIds = $this->insertLevel('communes', ['district_code' => $districtIds], $now, 'district_id');
        $this->insertLevel('villages', ['commune_code' => $communeIds], $now, 'commune_id');
    }

    /**
     * @param  array<string, array<string, int>>|null  $parentMap  [json parent field name => code-to-id map]
     * @return array<string, int> this level's own code => id, for the next level to consume
     */
    private function insertLevel(string $table, ?array $parentMap, \DateTimeInterface $now, ?string $parentColumn = null): array
    {
        $path = database_path("data/cambodia/{$table}.json");
        $rows = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        [$parentField, $parentIds] = $parentMap ? [array_key_first($parentMap), reset($parentMap)] : [null, null];

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $toInsert = array_map(fn (array $row) => [
                ...($parentColumn !== null ? [$parentColumn => $parentIds[$row[$parentField]]] : []),
                'code' => $row['code'],
                'name_km' => $row['name_km'],
                'name_latin' => $row['name_latin'],
                'unit_km' => $row['unit_km'],
                'unit_latin' => $row['unit_latin'],
                'unit_en' => $row['unit_en'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);

            DB::table($table)->insert($toInsert);
        }

        // Re-queried rather than tracked during insert: Postgres assigns the
        // real ids on insert, and a bulk insert() doesn't hand them back.
        return DB::table($table)->pluck('id', 'code')->all();
    }
}
