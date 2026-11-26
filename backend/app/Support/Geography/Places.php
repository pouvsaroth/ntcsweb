<?php

declare(strict_types=1);

namespace App\Support\Geography;

use App\Models\Commune;
use App\Models\District;
use App\Models\Province;
use App\Models\Village;
use Illuminate\Support\Collection;

/**
 * Places (Cambodia's provinces, districts, communes, villages) for school
 * records that sit at one: the central geography tables live in another
 * database than the school's, so records keep plain ids, every level above
 * their own filled in, and names are looked up here.
 */
final class Places
{
    public const LEVELS = ['province', 'district', 'commune', 'village'];

    /**
     * The place at `$level` and every level above it, or null when there is no such place.
     *
     * @return array{province_id: int, district_id: int|null, commune_id: int|null, village_id: int|null}|null
     */
    public static function ancestry(string $level, int $id): ?array
    {
        return match ($level) {
            'province' => ($p = Province::query()->find($id)) ? ['province_id' => $p->id, 'district_id' => null, 'commune_id' => null, 'village_id' => null] : null,
            'district' => ($d = District::query()->find($id)) ? ['province_id' => $d->province_id, 'district_id' => $d->id, 'commune_id' => null, 'village_id' => null] : null,
            'commune' => ($c = Commune::query()->with('district')->find($id)) ? ['province_id' => $c->district->province_id, 'district_id' => $c->district_id, 'commune_id' => $c->id, 'village_id' => null] : null,
            'village' => ($v = Village::query()->with('commune.district')->find($id)) ? ['province_id' => $v->commune->district->province_id, 'district_id' => $v->commune->district_id, 'commune_id' => $v->commune_id, 'village_id' => $v->id] : null,
            default => null,
        };
    }

    /**
     * Names for the places a list of records points at, looked up once.
     * Returns a function: record => ['province' => brief|null, 'district' => …, 'commune' => …, 'village' => …].
     *
     * @param  Collection<int, mixed>  $records  each with province_id / district_id / commune_id / village_id (missing = none)
     * @return \Closure(mixed): array<string, array{id: int, code: string, name_km: string, name_latin: string}|null>
     */
    public static function namer(Collection $records): \Closure
    {
        $load = function (string $model, string $key) use ($records) {
            $ids = $records->pluck($key)->filter()->unique()->values();

            return $ids->isEmpty() ? collect() : $model::query()->whereIn('id', $ids)->get(['id', 'code', 'name_km', 'name_latin'])->keyBy('id');
        };
        $maps = [
            'province' => $load(Province::class, 'province_id'),
            'district' => $load(District::class, 'district_id'),
            'commune' => $load(Commune::class, 'commune_id'),
            'village' => $load(Village::class, 'village_id'),
        ];
        $brief = fn ($m) => $m === null ? null : ['id' => $m->id, 'code' => $m->code, 'name_km' => $m->name_km, 'name_latin' => $m->name_latin];

        return function ($record) use ($maps, $brief): array {
            $out = [];
            foreach (self::LEVELS as $level) {
                $id = data_get($record, $level.'_id');
                $out[$level] = $id ? $brief($maps[$level]->get($id)) : null;
            }

            return $out;
        };
    }
}
