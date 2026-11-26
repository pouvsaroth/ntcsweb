<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CommuneMember;
use App\Models\CommuneSeat;
use App\Models\Staff;
use App\Models\Village;
use App\Services\Party\CommuneMemberImport;
use App\Services\Party\CommuneMemberLists;
use App\Support\Authorization\Permissions;
use App\Support\Geography\Places;
use App\Support\Query\ApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Political Party > Commune members: each commune's full members
 * (ពេញសិទ្ធិ) and reserve members (បម្រុង) for an election year, picked
 * from HRM staff (their name, gender, birth date, ID card, phone, village
 * and photo come from the staff record). A staff member is on one
 * commune's list per year. The full count is compared with the commune's
 * council seats (Election offices) as a warning only.
 */
final class CommuneMemberController extends Controller
{
    private const PLACE_FILTERS = ['province_id', 'district_id', 'commune_id'];

    public function __construct(private readonly CommuneMemberLists $lists) {}

    /** Election years that have members, newest first. */
    public function years(): JsonResponse
    {
        $this->authorize('viewAny', CommuneMember::class);

        return ApiResponse::success(CommuneMember::query()->distinct()->orderByDesc('election_year')->pluck('election_year'));
    }

    /** The members (commune by commune, full before reserve, in order), with the pick's counts and seats in meta.summary. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CommuneMember::class);
        $year = $this->year($request);
        $request->validate(['role' => ['nullable', Rule::in(CommuneMember::ROLES)]]);

        $query = $this->scoped(CommuneMember::query()->where('commune_members.election_year', $year), $request)
            ->when($request->filled('role'), fn (Builder $q) => $q->where('role', $request->string('role')->toString()))
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->input('search'))).'%';
                $q->whereHas('staff', fn (Builder $s) => $s->where(fn (Builder $w) => $w
                    ->where('first_name', 'ilike', $term)->orWhere('last_name', 'ilike', $term)
                    ->orWhere('other_name', 'ilike', $term)->orWhere('employee_code', 'ilike', $term)
                    ->orWhere('phone', 'ilike', $term)->orWhere('national_id', 'ilike', $term)
                    ->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$term])));
            })
            ->with('staff.position')
            ->orderBy('province_id')->orderBy('district_id')->orderBy('commune_id')
            ->orderByRaw("case when role = 'full' then 0 else 1 end")->orderBy('position');

        $page = ApiQuery::for($query, $request)->paginate();
        $page->setCollection($this->present($page->getCollection()));

        $counts = $this->scoped(CommuneMember::query()->where('election_year', $year), $request)
            ->selectRaw("count(*) filter (where role = 'full') as full, count(*) filter (where role = 'reserve') as reserve, count(distinct commune_id) as communes")
            ->toBase()->first();
        $seats = $this->scoped(CommuneSeat::query()->where('election_year', $year), $request);
        $picked = collect(self::PLACE_FILTERS)->mapWithKeys(fn ($key) => [$key => $request->filled($key) ? $request->integer($key) : null])->all();
        $names = Places::namer(collect([$picked]))($picked);
        $deepest = collect(['commune', 'district', 'province'])->first(fn ($level) => $names[$level] !== null);

        return ApiResponse::success($page, meta: ['summary' => [
            'election_year' => $year,
            'place' => $deepest === null ? null : $names[$deepest],
            'full' => (int) $counts->full,
            'reserve' => (int) $counts->reserve,
            'communes' => (int) $counts->communes,
            'seats' => (int) (clone $seats)->sum('seats'),
            'communes_with_seats' => (clone $seats)->count(),
        ]]);
    }

    /**
     * Active staff to pick from, and the commune list each is already on in
     * the year (a staff member is on one list per year).
     */
    public function staffOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission(Permissions::COMMUNE_MEMBERS_CREATE) || $user?->hasPermission(Permissions::COMMUNE_MEMBERS_UPDATE), 403);
        $year = $this->year($request);

        $taken = CommuneMember::query()->where('election_year', $year)->get(['id', 'staff_id', 'commune_id', 'role']);
        $communes = Places::namer($taken);
        $takenBy = $taken->keyBy('staff_id');

        $staff = Staff::query()->whereIn('status', Staff::STATUSES_WORKING)->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'gender', 'phone']);

        return ApiResponse::success($staff->map(function (Staff $s) use ($takenBy, $communes) {
            $on = $takenBy->get($s->id);

            return [
                'id' => $s->id,
                'name' => $s->fullName(),
                'employee_code' => $s->employee_code,
                'gender' => $s->gender,
                'phone' => $s->phone,
                'taken' => $on === null ? null : ['member_id' => $on->id, 'role' => $on->role, 'commune' => $communes($on)['commune']],
            ];
        })->values());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CommuneMember::class);

        $member = DB::connection('tenant')->transaction(function () use ($request) {
            $data = $this->validated($request, null);

            return $this->lists->place(new CommuneMember, $data);
        });

        return ApiResponse::created($this->present(collect([$member->load('staff.position')]))->first());
    }

    public function update(Request $request, CommuneMember $communeMember): JsonResponse
    {
        $this->authorize('update', $communeMember);

        $member = DB::connection('tenant')->transaction(function () use ($request, $communeMember) {
            $data = $this->validated($request, $communeMember);

            return $this->lists->place($communeMember, $data);
        });

        return ApiResponse::success($this->present(collect([$member->load('staff.position')]))->first());
    }

    public function destroy(CommuneMember $communeMember): JsonResponse
    {
        $this->authorize('delete', $communeMember);

        DB::connection('tenant')->transaction(function () use ($communeMember) {
            $communeMember->delete();
            $this->lists->renumber($communeMember->election_year, $communeMember->commune_id, $communeMember->role);
        });

        return ApiResponse::noContent();
    }

    /** The empty Excel template in the user's language. */
    public function template(): Response
    {
        $this->authorize('viewAny', CommuneMember::class);

        return response((new CommuneMemberImport($this->lists))->template(app()->getLocale()), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="commune-members-template.xlsx"',
        ]);
    }

    /** Load a year's members from Excel; nothing is saved when a row has a problem. */
    public function import(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission(Permissions::COMMUNE_MEMBERS_CREATE) && $user->hasPermission(Permissions::COMMUNE_MEMBERS_UPDATE), 403);
        $data = $request->validate([
            'election_year' => ['required', 'integer', 'between:1993,2100'],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
        ]);

        return ApiResponse::success((new CommuneMemberImport($this->lists))->run($data['file']->getRealPath(), (int) $data['election_year']));
    }

    /** The year asked for, else the newest year with members, else this year. */
    private function year(Request $request): int
    {
        $request->validate(['election_year' => ['nullable', 'integer', 'between:1993,2100']]);
        if ($request->filled('election_year')) {
            return $request->integer('election_year');
        }

        return (int) (CommuneMember::query()->max('election_year') ?? now()->year);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function scoped(Builder $query, Request $request): Builder
    {
        $table = $query->getModel()->getTable();
        foreach (self::PLACE_FILTERS as $key) {
            if ($request->filled($key)) {
                $query->where("{$table}.{$key}", $request->integer($key));
            }
        }

        return $query;
    }

    /** @return array{election_year: int, province_id: int, district_id: int, commune_id: int, staff_id: int, role: string, position: int|null, remark: string|null} */
    private function validated(Request $request, ?CommuneMember $member): array
    {
        $data = $request->validate([
            'election_year' => ['required', 'integer', 'between:1993,2100'],
            'commune_id' => ['required', 'integer'],
            'staff_id' => ['required', 'integer', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')],
            'role' => ['required', Rule::in(CommuneMember::ROLES)],
            'position' => ['nullable', 'integer', 'min:1', 'max:999'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);

        $places = Places::ancestry('commune', (int) $data['commune_id']);
        if ($places === null) {
            throw ValidationException::withMessages(['commune_id' => __('party.members.chooseCommune')]);
        }
        $other = CommuneMember::query()->where('election_year', $data['election_year'])->where('staff_id', $data['staff_id'])
            ->when($member !== null, fn (Builder $q) => $q->whereKeyNot($member->id))->first();
        if ($other !== null) {
            $commune = Places::namer(collect([$other]))($other)['commune'];
            throw ValidationException::withMessages(['staff_id' => __('party.members.alreadyOn', ['commune' => $commune['name_latin'] ?? '', 'year' => $data['election_year']])]);
        }

        return [
            'election_year' => (int) $data['election_year'],
            'province_id' => $places['province_id'],
            'district_id' => (int) $places['district_id'],
            'commune_id' => (int) $places['commune_id'],
            'staff_id' => (int) $data['staff_id'],
            'role' => $data['role'],
            'position' => isset($data['position']) ? (int) $data['position'] : null,
            'remark' => $data['remark'] ?? null,
        ];
    }

    /**
     * Each member with their staff details and places (looked up once for the list).
     *
     * @param  Collection<int, CommuneMember>  $members
     * @return Collection<int, array<string, mixed>>
     */
    private function present(Collection $members): Collection
    {
        $names = Places::namer($members);
        $codes = $members->map(fn (CommuneMember $m) => $m->staff?->village_code)->filter()->unique()->values();
        $villages = $codes->isEmpty() ? collect() : Village::query()->whereIn('code', $codes)->get(['code', 'name_km', 'name_latin'])->keyBy('code');

        return $members->map(function (CommuneMember $m) use ($names, $villages) {
            $s = $m->staff;
            $village = $s?->village_code ? $villages->get($s->village_code) : null;

            return [
                'id' => $m->id,
                'election_year' => $m->election_year,
                ...$names($m),
                'role' => $m->role,
                'position' => $m->position,
                'remark' => $m->remark,
                'staff' => $s === null ? null : [
                    'id' => $s->id,
                    'employee_code' => $s->employee_code,
                    'name' => $s->fullName(),
                    'other_name' => $s->other_name,
                    'gender' => $s->gender,
                    'date_of_birth' => $s->date_of_birth?->toDateString(),
                    'national_id' => $s->national_id,
                    'phone' => $s->phone,
                    'position' => $s->position?->name,
                    'village' => $village === null ? null : ['name_km' => $village->name_km, 'name_latin' => $village->name_latin],
                    'photo_url' => $s->photoUrl(),
                    // No longer working (left, suspended or deleted): the list still shows them, marked.
                    'left' => $s->trashed() || ! in_array($s->status, Staff::STATUSES_WORKING, true),
                ],
            ];
        });
    }
}
