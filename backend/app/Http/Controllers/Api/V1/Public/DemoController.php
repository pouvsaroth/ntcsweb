<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Demo\Demos;
use App\Platform\Models\DemoVisit;
use App\Platform\Support\SupportViewSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Try the demo" on NewTech's Plans page (docs/backoffice/stage-12-demo.md).
 * Only on the website school (`platform.website_school`); 404 anywhere else.
 */
final class DemoController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Demos $demos,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $this->websiteOnly();

        // A hidden field people never see; a bot that fills it gets a polite nothing.
        if (filled($request->input('website'))) {
            return ApiResponse::error(__('Please try again.'), 422, 'DEMO_REJECTED');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+\s()-]{6,}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'role' => ['required', Rule::in(DemoVisit::ROLES)],
            'locale' => ['nullable', 'string', 'max:5'],
        ]);
        $data['name'] = trim($data['name']);

        ['visit' => $visit, 'ticket' => $ticket] = $this->demos->start($data, $request);

        return ApiResponse::created($this->present($visit, $ticket));
    }

    /** "Continue my demo" from the same browser, within its days. */
    public function resume(string $token): JsonResponse
    {
        $this->websiteOnly();

        ['visit' => $visit, 'ticket' => $ticket] = $this->demos->resume($token);

        return ApiResponse::success($this->present($visit, $ticket));
    }

    /** @return array<string, mixed> */
    private function present(DemoVisit $visit, string $ticket): array
    {
        return [
            'token' => $visit->token,
            'role' => $visit->role,
            'name' => $visit->name,
            'expires_at' => SupportViewSession::inUtc(fn () => $visit->expires_at->toIso8601String()),
            'sign_in_url' => $this->demos->signInUrl($visit, $ticket),
        ];
    }

    private function websiteOnly(): void
    {
        abort_unless($this->context->getOrFail()->slug === config('platform.website_school'), 404);
    }
}
