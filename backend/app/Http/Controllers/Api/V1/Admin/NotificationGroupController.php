<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\NotificationGroup;
use App\Models\Role;
use App\Models\User;
use App\Platform\Telegram\Telegram;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Telegram notification groups (docs/backoffice/stage-11-telegram-notifications.md):
 * groups of this school's users, each linked to one Telegram group. Needs
 * the school settings permission.
 */
final class NotificationGroupController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Telegram $telegram,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->allowed($request);
        $groups = NotificationGroup::query()->where('tenant_id', $this->tenantId())->with('users:id,name,email')->orderBy('name')->get();

        return ApiResponse::success([
            'groups' => $groups->map(fn (NotificationGroup $g) => $this->present($g))->values(),
            // Whether NewTech has set up the bot at all.
            'bot_ready' => $this->telegram->token() !== null,
            'bot_username' => config('services.telegram.bot_username'),
        ]);
    }

    /** The school's staff users to pick from (not students). */
    public function users(Request $request): JsonResponse
    {
        $this->allowed($request);
        $users = User::query()->inTenant($this->tenantId())->active()
            ->whereDoesntHave('roles', fn ($q) => $q->where('slug', Role::STUDENT))
            ->orderBy('name')->get(['id', 'name', 'email']);

        return ApiResponse::success($users->map(fn (User $u) => $u->only(['id', 'name', 'email']))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $this->allowed($request);
        $data = $this->validated($request);
        $group = NotificationGroup::query()->create(['tenant_id' => $this->tenantId(), 'name' => $data['name']]);
        $group->users()->sync($data['user_ids'] ?? []);

        return ApiResponse::created($this->present($group->load('users:id,name,email')));
    }

    public function update(Request $request, int $group): JsonResponse
    {
        $this->allowed($request);
        $model = $this->find($group);
        $data = $this->validated($request);
        $model->update(['name' => $data['name']]);
        $model->users()->sync($data['user_ids'] ?? []);

        return ApiResponse::success($this->present($model->load('users:id,name,email')));
    }

    public function destroy(Request $request, int $group): JsonResponse
    {
        $this->allowed($request);
        $this->find($group)->delete();

        return ApiResponse::noContent();
    }

    /** A fresh "/link CODE", valid 30 minutes. */
    public function linkCode(Request $request, int $group): JsonResponse
    {
        $this->allowed($request);
        $model = $this->find($group);
        do {
            $code = strtoupper(Str::random(6));
        } while (! preg_match('/^[A-Z0-9]{6}$/', $code) || NotificationGroup::query()->where('link_code', $code)->exists());
        $model->forceFill(['link_code' => $code, 'link_code_expires_at' => now()->addMinutes(30)])->save();

        return ApiResponse::success($this->present($model->load('users:id,name,email')));
    }

    /** "Check now": read the bot's messages at once instead of waiting for the minute. */
    public function check(Request $request, int $group): JsonResponse
    {
        $this->allowed($request);
        $this->telegram->readUpdates();

        return ApiResponse::success($this->present($this->find($group)->load('users:id,name,email')));
    }

    public function test(Request $request, int $group): JsonResponse
    {
        $this->allowed($request);
        $model = $this->find($group);
        abort_unless($model->isLinked(), 422, __('Link this group to Telegram first.'));
        $school = $this->context->getOrFail()->name;

        return ApiResponse::success($this->telegram->send($model->telegram_chat_id, "✅ {$school} · {$model->name}: test message — notifications will arrive here."));
    }

    public function unlink(Request $request, int $group): JsonResponse
    {
        $this->allowed($request);
        $model = $this->find($group);
        $model->forceFill(['telegram_chat_id' => null, 'telegram_title' => null, 'linked_at' => null])->save();

        return ApiResponse::success($this->present($model->load('users:id,name,email')));
    }

    /** @return array{name: string, user_ids?: list<int>} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer'],
        ]);
        // Only this school's users.
        $ids = $data['user_ids'] ?? [];
        $valid = User::query()->inTenant($this->tenantId())->whereIn('id', $ids)->pluck('id')->all();
        if (count(array_unique($ids)) !== count($valid)) {
            throw ValidationException::withMessages(['user_ids' => __('Pick users of this school only.')]);
        }
        $data['user_ids'] = $valid;

        return $data;
    }

    private function find(int $id): NotificationGroup
    {
        return NotificationGroup::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    private function tenantId(): int
    {
        return (int) $this->context->getOrFail()->getKey();
    }

    private function allowed(Request $request): void
    {
        abort_unless($request->user()?->hasPermission(Permissions::TENANT_SETTINGS_UPDATE), 403);
    }

    /** @return array<string, mixed> */
    private function present(NotificationGroup $g): array
    {
        $codeLive = $g->link_code && $g->link_code_expires_at?->isFuture();

        return [
            'id' => $g->id,
            'name' => $g->name,
            'linked' => $g->isLinked(),
            'telegram_title' => $g->telegram_title,
            'linked_at' => $g->linked_at?->toIso8601String(),
            'link_code' => $codeLive ? $g->link_code : null,
            'link_code_expires_at' => $codeLive ? $g->link_code_expires_at->toIso8601String() : null,
            'users' => $g->relationLoaded('users') ? $g->users->map(fn ($u) => $u->only(['id', 'name', 'email']))->values() : [],
        ];
    }
}
