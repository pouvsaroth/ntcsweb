<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Commune;
use App\Models\District;
use App\Models\PartyBranch;
use App\Models\Province;
use App\Models\Village;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Political Party > Branches: the party's branches / offices. A branch is
 * placed at one place (`level` + `location_id`: a province, district,
 * commune or village); the levels above it are filled in from the
 * geography tables so the list can be narrowed by any of them.
 */
final class PartyBranchController extends Controller
{
    private const FILTERS = ['level', 'is_active', 'province_id', 'district_id', 'commune_id', 'village_id'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PartyBranch::class);

        $page = $this->query($request)->paginate();
        $page->setCollection($this->present($page->getCollection()));

        return ApiResponse::success($page);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PartyBranch::class);

        $branch = PartyBranch::query()->create($this->validated($request));

        return ApiResponse::created($this->present(collect([$branch->refresh()]))->first());
    }

    public function show(PartyBranch $partyBranch): JsonResponse
    {
        $this->authorize('view', $partyBranch);

        return ApiResponse::success($this->present(collect([$partyBranch]))->first());
    }

    public function update(Request $request, PartyBranch $partyBranch): JsonResponse
    {
        $this->authorize('update', $partyBranch);

        $partyBranch->update($this->validated($request));

        return ApiResponse::success($this->present(collect([$partyBranch->refresh()]))->first());
    }

    public function destroy(PartyBranch $partyBranch): JsonResponse
    {
        $this->authorize('delete', $partyBranch);

        $partyBranch->delete();

        return ApiResponse::noContent();
    }

    /** Every branch the list's filters and search keep, as an Excel sheet. */
    public function excel(Request $request, TenantContext $context): Response
    {
        $this->authorize('viewAny', PartyBranch::class);

        $rows = $this->present($this->query($request)->build()->get());

        $options = new Options;
        foreach ([6, 10, 28, 10, 18, 18, 18, 18, 24, 16, 30, 12, 10, 30] as $i => $width) {
            $options->setColumnWidth($width, $i + 1);
        }
        $path = tempnam(sys_get_temp_dir(), 'branches');
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Branches');
        $head = (new Style)->setFontBold()->setBackgroundColor('F3F4F6');

        $writer->addRow(Row::fromValues([$context->getOrFail()->name], (new Style)->setFontBold()->setFontSize(14)));
        $writer->addRow(Row::fromValues([__('party.branches_title').' ('.now()->format('d-m-Y').')'], (new Style)->setFontBold()));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([
            __('party.no'), __('party.code'), __('party.name'), __('party.level'),
            __('party.province'), __('party.district'), __('party.commune'), __('party.village'),
            __('party.leader'), __('party.phone'), __('party.address'), __('party.opened_on'), __('party.status'), __('party.remark'),
        ], $head));

        $place = fn (?array $p) => $p === null ? '' : trim($p['name_km'].' ('.$p['name_latin'].')');
        foreach ($rows->values() as $i => $row) {
            $writer->addRow(Row::fromValues([
                $i + 1, $row['code'], $row['name'], __('party.levels.'.$row['level']),
                $place($row['province']), $place($row['district']), $place($row['commune']), $place($row['village']),
                $row['leader_name'] ?? '', $row['leader_phone'] ?? '', $row['address'] ?? '',
                $row['opened_on'] !== null ? Carbon::parse($row['opened_on'])->format('d-m-Y') : '',
                $row['is_active'] ? __('party.active') : __('party.closed'), $row['remark'] ?? '',
            ]));
        }

        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="party-branches-'.now()->format('Y-m-d').'.xlsx"',
        ]);
    }

    private function query(Request $request): ApiQuery
    {
        return ApiQuery::for(PartyBranch::query(), $request)
            ->searchable('name', 'code', 'leader_name', 'leader_phone')
            ->filterable(self::FILTERS)
            ->sortable(['name', 'code', 'opened_on', 'created_at'], default: 'code');
    }

    /**
     * The branch's fields, with `location_id` turned into the place and
     * every level above it.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'level' => ['required', Rule::in(PartyBranch::LEVELS)],
            'location_id' => ['required', 'integer'],
            'leader_name' => ['nullable', 'string', 'max:255'],
            'leader_phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:2000'],
            'opened_on' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);

        $id = (int) $data['location_id'];
        $places = match ($data['level']) {
            'province' => ($p = Province::query()->find($id)) ? ['province_id' => $p->id, 'district_id' => null, 'commune_id' => null, 'village_id' => null] : null,
            'district' => ($d = District::query()->find($id)) ? ['province_id' => $d->province_id, 'district_id' => $d->id, 'commune_id' => null, 'village_id' => null] : null,
            'commune' => ($c = Commune::query()->with('district')->find($id)) ? ['province_id' => $c->district->province_id, 'district_id' => $c->district_id, 'commune_id' => $c->id, 'village_id' => null] : null,
            'village' => ($v = Village::query()->with('commune.district')->find($id)) ? ['province_id' => $v->commune->district->province_id, 'district_id' => $v->commune->district_id, 'commune_id' => $v->commune_id, 'village_id' => $v->id] : null,
        };
        if ($places === null) {
            throw ValidationException::withMessages(['location_id' => __('Choose the :level of this branch.', ['level' => $data['level']])]);
        }
        unset($data['location_id']);

        return [...$data, ...$places];
    }

    /**
     * Each branch as the API sends it, with its places' names (looked up
     * once for the whole list).
     *
     * @param  Collection<int, PartyBranch>  $branches
     * @return Collection<int, array<string, mixed>>
     */
    private function present(Collection $branches): Collection
    {
        $load = function (string $model, string $key) use ($branches) {
            $ids = $branches->pluck($key)->filter()->unique()->values();

            return $ids->isEmpty() ? collect() : $model::query()->whereIn('id', $ids)->get(['id', 'code', 'name_km', 'name_latin'])->keyBy('id');
        };
        $provinces = $load(Province::class, 'province_id');
        $districts = $load(District::class, 'district_id');
        $communes = $load(Commune::class, 'commune_id');
        $villages = $load(Village::class, 'village_id');
        $brief = fn ($m) => $m === null ? null : ['id' => $m->id, 'code' => $m->code, 'name_km' => $m->name_km, 'name_latin' => $m->name_latin];

        return $branches->map(fn (PartyBranch $b) => [
            'id' => $b->id,
            'code' => $b->code,
            'name' => $b->name,
            'level' => $b->level,
            'province' => $brief($provinces->get($b->province_id)),
            'district' => $b->district_id ? $brief($districts->get($b->district_id)) : null,
            'commune' => $b->commune_id ? $brief($communes->get($b->commune_id)) : null,
            'village' => $b->village_id ? $brief($villages->get($b->village_id)) : null,
            'leader_name' => $b->leader_name,
            'leader_phone' => $b->leader_phone,
            'address' => $b->address,
            'opened_on' => $b->opened_on?->toDateString(),
            'is_active' => $b->is_active,
            'remark' => $b->remark,
            'created_at' => $b->created_at?->toIso8601String(),
        ]);
    }
}
