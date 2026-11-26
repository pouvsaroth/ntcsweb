<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Commune;
use App\Models\District;
use App\Models\Province;
use App\Models\Village;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Political Party > Locations: Cambodia's provinces, districts, communes
 * and villages (the platform-wide geography tables), narrowed by a picked
 * province / district / commune and found by Khmer name, Latin name or code.
 *
 * With no search, it lists the level under the deepest one picked (the
 * provinces when nothing is picked). With a search, it looks through every
 * level under it — or only `level`, when given.
 */
final class PartyLocationController extends Controller
{
    public const LEVELS = ['province', 'district', 'commune', 'village'];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permissions::PARTY_LOCATIONS_VIEW), 403);

        $data = $request->validate([
            'province_id' => ['nullable', 'integer'],
            'district_id' => ['nullable', 'integer'],
            'commune_id' => ['nullable', 'integer'],
            'level' => ['nullable', Rule::in(self::LEVELS)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $scope = array_filter([
            'province_id' => $data['province_id'] ?? null,
            'district_id' => $data['district_id'] ?? null,
            'commune_id' => $data['commune_id'] ?? null,
        ]);
        // The levels under the deepest one picked.
        $depth = isset($scope['commune_id']) ? 3 : (isset($scope['district_id']) ? 2 : (isset($scope['province_id']) ? 1 : 0));
        $below = array_slice(self::LEVELS, $depth);
        $search = trim((string) ($data['search'] ?? ''));

        if (! empty($data['level'])) {
            $levels = in_array($data['level'], $below, true) ? [$data['level']] : [];
        } else {
            $levels = $search !== '' ? $below : array_slice($below, 0, 1);
        }

        $connection = DB::connection((new Village)->getConnectionName());
        $union = null;
        foreach ($levels as $level) {
            $query = $this->levelQuery($connection->query(), $level, $scope, $search);
            $union = $union === null ? $query : $union->unionAll($query);
        }

        if ($union === null) {
            return ApiResponse::success(new LengthAwarePaginator([], 0, (int) ($data['per_page'] ?? 25)));
        }

        $page = $connection->query()
            ->fromSub($union, 'places')
            ->orderBy('depth')
            ->orderBy('name_latin')
            ->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        // Each place's province / district / commune, for its path.
        $rows = collect($page->items());
        $provinces = Province::query()->whereIn('id', $rows->pluck('province_id')->filter()->unique())->get(['id', 'code', 'name_km', 'name_latin'])->keyBy('id');
        $districts = District::query()->whereIn('id', $rows->pluck('district_id')->filter()->unique())->get(['id', 'code', 'name_km', 'name_latin'])->keyBy('id');
        $communes = Commune::query()->whereIn('id', $rows->pluck('commune_id')->filter()->unique())->get(['id', 'code', 'name_km', 'name_latin'])->keyBy('id');
        $brief = fn ($m) => $m === null ? null : ['id' => $m->id, 'code' => $m->code, 'name_km' => $m->name_km, 'name_latin' => $m->name_latin];

        $page->setCollection($rows->map(fn ($row) => [
            'level' => $row->level,
            'id' => (int) $row->id,
            'code' => $row->code,
            'name_km' => $row->name_km,
            'name_latin' => $row->name_latin,
            'unit_km' => $row->unit_km,
            'unit_en' => $row->unit_en,
            'province' => $row->level === 'province' ? null : $brief($provinces->get($row->province_id)),
            'district' => in_array($row->level, ['commune', 'village'], true) ? $brief($districts->get($row->district_id)) : null,
            'commune' => $row->level === 'village' ? $brief($communes->get($row->commune_id)) : null,
            'children' => (int) $row->children,
        ]));

        return ApiResponse::success($page);
    }

    /**
     * One level's places with the same columns, so the levels can be listed together.
     *
     * @param  array{province_id?: int, district_id?: int, commune_id?: int}  $scope
     */
    private function levelQuery(Builder $query, string $level, array $scope, string $search): Builder
    {
        [$table, $alias] = match ($level) {
            'province' => ['provinces', 'p'],
            'district' => ['districts', 'd'],
            'commune' => ['communes', 'c'],
            'village' => ['villages', 'v'],
        };
        $query->from("{$table} as {$alias}");

        // Ancestor ids (and how many places sit directly under it).
        if ($level === 'province') {
            $query->selectRaw("'province' as level, 0 as depth, p.id as province_id, null::bigint as district_id, null::bigint as commune_id, (select count(*) from districts x where x.province_id = p.id) as children");
        } elseif ($level === 'district') {
            $query->selectRaw("'district' as level, 1 as depth, d.province_id, d.id as district_id, null::bigint as commune_id, (select count(*) from communes x where x.district_id = d.id) as children");
        } elseif ($level === 'commune') {
            $query->join('districts as d', 'd.id', '=', 'c.district_id')
                ->selectRaw("'commune' as level, 2 as depth, d.province_id, c.district_id, c.id as commune_id, (select count(*) from villages x where x.commune_id = c.id) as children");
        } else {
            $query->join('communes as c', 'c.id', '=', 'v.commune_id')
                ->join('districts as d', 'd.id', '=', 'c.district_id')
                ->selectRaw("'village' as level, 3 as depth, d.province_id, c.district_id, v.commune_id, 0 as children");
        }
        $query->addSelect(["{$alias}.id", "{$alias}.code", "{$alias}.name_km", "{$alias}.name_latin", "{$alias}.unit_km", "{$alias}.unit_en"]);

        $column = fn (string $key) => match ($key) {
            'province_id' => $level === 'province' ? 'p.id' : 'd.province_id',
            'district_id' => $level === 'district' ? 'd.id' : 'c.district_id',
            'commune_id' => $level === 'commune' ? 'c.id' : 'v.commune_id',
        };
        foreach ($scope as $key => $id) {
            $query->where($column($key), $id);
        }

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q
                ->where("{$alias}.name_km", 'ILIKE', $like)
                ->orWhere("{$alias}.name_latin", 'ILIKE', $like)
                ->orWhere("{$alias}.code", 'ILIKE', $like));
        }

        return $query;
    }
}
