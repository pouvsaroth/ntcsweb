<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Platform\DataOps\SchoolMover;
use App\Platform\Models\SchoolMove;
use App\Platform\Models\SchoolPlacement;
use App\Platform\Services\SeparateServerProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Moving a school's data between placements (stage 4d): start a move, follow
 * it, and — once staff are satisfied — remove the old copy.
 */
final class SchoolMoveController extends Controller
{
    public function __construct(private readonly SchoolMover $mover) {}

    public function index(Tenant $school): JsonResponse
    {
        $moves = SchoolMove::query()->with('staff:id,name')->where('tenant_id', $school->id)->latest('id')->limit(20)->get();

        return ApiResponse::success($moves->map(fn (SchoolMove $m) => $this->row($m))->values());
    }

    public function store(Request $request, Tenant $school): JsonResponse
    {
        $data = $request->validate([
            'placement' => ['required', Rule::in([SchoolPlacement::DEDICATED, SchoolPlacement::SEPARATE])],
            'server' => ['exclude_unless:placement,separate', 'required', 'array'],
            'server.host' => ['exclude_unless:placement,separate', 'required', 'string', 'max:255'],
            'server.port' => ['exclude_unless:placement,separate', 'required', 'integer', 'between:1,65535'],
            'server.database' => ['exclude_unless:placement,separate', 'required', 'string', 'max:63', 'regex:/^[A-Za-z_][A-Za-z0-9_$-]*$/'],
            'server.username' => ['exclude_unless:placement,separate', 'required', 'string', 'max:63'],
            'server.password' => ['exclude_unless:placement,separate', 'required', 'string', 'max:255'],
            'server.sslmode' => ['exclude_unless:placement,separate', 'nullable', Rule::in(SeparateServerProbe::SSL_MODES)],
        ]);

        $move = $this->mover->start($school, ['mode' => $data['placement']] + ($data['server'] ?? []), $request->user('staff'));

        return ApiResponse::created($this->row($move->fresh()));
    }

    public function removeOldCopy(Request $request, SchoolMove $move): JsonResponse
    {
        $this->mover->removeOldCopy($move, $request->user('staff'));

        return ApiResponse::success($this->row($move->fresh()));
    }

    /** @return array<string, mixed> — never a password */
    private function row(SchoolMove $m): array
    {
        $describe = fn (array $p) => array_intersect_key($p, array_flip(['mode', 'database', 'host', 'port', 'username', 'sslmode']));

        return [
            'id' => $m->id,
            'status' => $m->status,
            'from' => $describe($m->from_placement),
            'to' => $describe($m->to_placement),
            'steps' => $m->steps ?? [],
            'counts' => $m->counts,
            'error' => $m->error,
            'staff' => $m->staff?->name,
            'old_copy_removed_at' => $m->old_copy_removed_at?->toIso8601String(),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
