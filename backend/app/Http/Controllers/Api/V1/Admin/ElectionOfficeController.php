<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CommuneSeat;
use App\Models\ElectionOffice;
use App\Models\Village;
use App\Services\Party\ElectionImport;
use App\Support\Authorization\Permissions;
use App\Support\Geography\Places;
use App\Support\Query\ApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Political Party > Election offices: where people vote, per election year,
 * and how many commune / sangkat council seats each commune has. Pick a
 * province → district → commune to see that place's seats, offices and
 * voters, with its offices listed.
 */
final class ElectionOfficeController extends Controller
{
    private const PLACE_FILTERS = ['province_id', 'district_id', 'commune_id', 'village_id'];

    /** Election years that have offices or seats, newest first. */
    public function years(): JsonResponse
    {
        $this->authorize('viewAny', ElectionOffice::class);

        $years = ElectionOffice::query()->distinct()->pluck('election_year')
            ->merge(CommuneSeat::query()->distinct()->pluck('election_year'))
            ->unique()->sortDesc()->values();

        return ApiResponse::success($years);
    }

    /** The offices, with the place's totals (seats, offices, voters) in meta.summary. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ElectionOffice::class);
        $year = $this->year($request);

        $offices = $this->scoped(ElectionOffice::query()->where('election_year', $year), $request);
        $page = ApiQuery::for($offices, $request)
            ->searchable('code', 'name', 'location')
            ->sortable(['code', 'name', 'voters'], default: 'code')
            ->paginate();
        $page->setCollection($this->presentOffices($page->getCollection()));

        $totals = $this->scoped(ElectionOffice::query()->where('election_year', $year), $request)
            ->selectRaw('count(*) as offices, coalesce(sum(voters), 0) as voters')->toBase()->first();
        $seats = $request->filled('village_id') ? null : (int) $this->scoped(CommuneSeat::query()->where('election_year', $year), $request, villages: false)->sum('seats');

        $picked = collect(self::PLACE_FILTERS)->mapWithKeys(fn ($key) => [$key => $request->filled($key) ? $request->integer($key) : null])->all();
        $pickedNames = Places::namer(collect([$picked]))($picked);
        $deepest = collect(array_reverse(Places::LEVELS))->first(fn ($level) => $pickedNames[$level] !== null);

        return ApiResponse::success($page, meta: ['summary' => [
            'election_year' => $year,
            'place' => $deepest === null ? null : $pickedNames[$deepest],
            'offices' => (int) $totals->offices,
            'voters' => (int) $totals->voters,
            'seats' => $seats,
            'communes_with_seats' => $request->filled('village_id') ? null : $this->scoped(CommuneSeat::query()->where('election_year', $year), $request, villages: false)->count(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ElectionOffice::class);

        $office = ElectionOffice::query()->create($this->validatedOffice($request, null));

        return ApiResponse::created($this->presentOffices(collect([$office->refresh()]))->first());
    }

    public function update(Request $request, ElectionOffice $electionOffice): JsonResponse
    {
        $this->authorize('update', $electionOffice);

        $electionOffice->update($this->validatedOffice($request, $electionOffice));

        return ApiResponse::success($this->presentOffices(collect([$electionOffice->refresh()]))->first());
    }

    public function destroy(ElectionOffice $electionOffice): JsonResponse
    {
        $this->authorize('delete', $electionOffice);

        $electionOffice->delete();

        return ApiResponse::noContent();
    }

    /** Each commune's seats in the year (with its offices and voters), narrowed by place. */
    public function seats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ElectionOffice::class);
        $year = $this->year($request);

        $offices = fn (string $select) => ElectionOffice::query()->selectRaw($select)
            ->whereColumn('election_offices.commune_id', 'election_commune_seats.commune_id')
            ->where('election_offices.election_year', $year);
        $query = $this->scoped(CommuneSeat::query()->where('election_year', $year), $request, villages: false)
            ->select('election_commune_seats.*')
            ->selectSub($offices('count(*)'), 'offices')
            ->selectSub($offices('coalesce(sum(voters), 0)'), 'voters');

        $page = ApiQuery::for($query, $request)->sortable(['seats', 'created_at'], default: 'id')->paginate();
        $names = Places::namer($page->getCollection());
        $page->setCollection($page->getCollection()->map(fn (CommuneSeat $s) => [
            'id' => $s->id,
            'election_year' => $s->election_year,
            ...$names($s),
            'seats' => $s->seats,
            'offices' => (int) $s->offices,
            'voters' => (int) $s->voters,
        ]));

        return ApiResponse::success($page);
    }

    /** Set a commune's seats for a year (adds the row, or changes it). */
    public function saveSeats(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission(Permissions::ELECTION_OFFICES_CREATE) || $user?->hasPermission(Permissions::ELECTION_OFFICES_UPDATE), 403);

        $data = $request->validate([
            'election_year' => ['required', 'integer', 'between:1993,2100'],
            'commune_id' => ['required', 'integer'],
            'seats' => ['required', 'integer', 'min:1', 'max:500'],
        ]);
        $places = Places::ancestry('commune', (int) $data['commune_id']);
        if ($places === null) {
            throw ValidationException::withMessages(['commune_id' => __('Choose a commune.')]);
        }

        $seat = CommuneSeat::query()->updateOrCreate(
            ['election_year' => $data['election_year'], 'commune_id' => $places['commune_id']],
            ['province_id' => $places['province_id'], 'district_id' => $places['district_id'], 'seats' => $data['seats']],
        );

        return ApiResponse::success(['id' => $seat->id, 'election_year' => $seat->election_year, ...Places::namer(collect([$seat]))($seat), 'seats' => $seat->seats]);
    }

    public function deleteSeats(Request $request, CommuneSeat $seat): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permissions::ELECTION_OFFICES_DELETE), 403);

        $seat->delete();

        return ApiResponse::noContent();
    }

    /** The empty Excel template (Offices + Seats sheets) in the user's language. */
    public function template(Request $request): Response
    {
        $this->authorize('viewAny', ElectionOffice::class);

        return response((new ElectionImport)->template(app()->getLocale()), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="election-offices-template.xlsx"',
        ]);
    }

    /** Load a year's offices and seats from Excel; nothing is saved when a row has a problem. */
    public function import(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission(Permissions::ELECTION_OFFICES_CREATE) && $user->hasPermission(Permissions::ELECTION_OFFICES_UPDATE), 403);
        $data = $request->validate([
            'election_year' => ['required', 'integer', 'between:1993,2100'],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
        ]);

        $result = (new ElectionImport)->run($data['file']->getRealPath(), (int) $data['election_year']);

        return ApiResponse::success($result);
    }

    /** The year asked for, else the newest year with data, else this year. */
    private function year(Request $request): int
    {
        $request->validate(['election_year' => ['nullable', 'integer', 'between:1993,2100']]);
        if ($request->filled('election_year')) {
            return $request->integer('election_year');
        }

        return (int) (ElectionOffice::query()->max('election_year') ?? CommuneSeat::query()->max('election_year') ?? now()->year);
    }

    /**
     * Narrowed to the picked province / district / commune (/ village).
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function scoped(Builder $query, Request $request, bool $villages = true): Builder
    {
        $table = $query->getModel()->getTable();
        foreach (self::PLACE_FILTERS as $key) {
            if ($key === 'village_id' && ! $villages) {
                continue;
            }
            if ($request->filled($key)) {
                $query->where("{$table}.{$key}", $request->integer($key));
            }
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function validatedOffice(Request $request, ?ElectionOffice $office): array
    {
        $data = $request->validate([
            'election_year' => ['required', 'integer', 'between:1993,2100'],
            'code' => ['required', 'string', 'max:30', Rule::unique('tenant.election_offices', 'code')->where('election_year', (int) $request->input('election_year'))->ignore($office?->id)],
            'name' => ['required', 'string', 'max:255'],
            'commune_id' => ['required', 'integer'],
            'village_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:255'],
            'voters' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $places = Places::ancestry('commune', (int) $data['commune_id']);
        if ($places === null) {
            throw ValidationException::withMessages(['commune_id' => __('Choose a commune.')]);
        }
        if (! empty($data['village_id'])) {
            if (! Village::query()->whereKey($data['village_id'])->where('commune_id', $places['commune_id'])->exists()) {
                throw ValidationException::withMessages(['village_id' => __('Choose a village in this commune.')]);
            }
            $places['village_id'] = (int) $data['village_id'];
        }

        return [...collect($data)->except(['commune_id', 'village_id'])->all(), ...$places];
    }

    /**
     * @param  Collection<int, ElectionOffice>  $offices
     * @return Collection<int, array<string, mixed>>
     */
    private function presentOffices(Collection $offices): Collection
    {
        $names = Places::namer($offices);

        return $offices->map(fn (ElectionOffice $o) => [
            'id' => $o->id,
            'election_year' => $o->election_year,
            'code' => $o->code,
            'name' => $o->name,
            ...$names($o),
            'location' => $o->location,
            'voters' => $o->voters,
        ]);
    }
}
