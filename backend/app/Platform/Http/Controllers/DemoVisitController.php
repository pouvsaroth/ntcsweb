<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Models\DemoVisit;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Billing → Demos: who tried the demo on the website (docs/backoffice/stage-12-demo.md). */
final class DemoVisitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DemoVisit::query()->with('tenant:id,name,slug');

        // live = still usable · ended = switched off or past its days
        $state = $request->input('filter.state') ?? $request->query('state');
        if ($state === 'live') {
            $query->whereNull('ended_at')->where('expires_at', '>', now());
        } elseif ($state === 'ended') {
            $query->where(fn ($q) => $q->whereNotNull('ended_at')->orWhere('expires_at', '<=', now()));
        }

        $page = ApiQuery::for($query, $request)
            ->searchable('name', 'phone', 'email')
            ->filterable(['role'])
            ->sortable(['created_at', 'last_sign_in_at'], default: '-id')
            ->paginate();

        $page->through(fn (DemoVisit $v) => [
            'id' => $v->id,
            'name' => $v->name,
            'phone' => $v->phone,
            'email' => $v->email,
            'role' => $v->role,
            'school' => $v->tenant?->only(['id', 'name', 'slug']),
            'user_id' => $v->user_id,
            'staff_id' => $v->staff_id,
            'student_id' => $v->student_id,
            'live' => $v->isLive(),
            'sign_ins' => $v->sign_ins,
            'last_sign_in_at' => $v->last_sign_in_at?->toIso8601String(),
            'expires_at' => $v->expires_at->toIso8601String(),
            'ended_at' => $v->ended_at?->toIso8601String(),
            'locale' => $v->locale,
            'ip_address' => $v->ip_address,
            'created_at' => $v->created_at?->toIso8601String(),
        ]);

        return ApiResponse::success($page);
    }
}
