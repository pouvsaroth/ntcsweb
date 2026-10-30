<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Billing\Modules;
use App\Platform\Billing\SchoolModules;
use App\Platform\Http\Controllers\HelpCenterController;
use App\Platform\Models\HelpArticle;
use App\Platform\Models\HelpFaq;
use App\Platform\Models\SupportSettings;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Help & Support in the system (docs/backoffice/stage-13-help-support.md):
 * NewTech's contacts, published help articles and FAQ. Only articles and
 * questions for modules on the school's plan show, plus the general ones.
 * Both languages are sent; the screen picks one.
 */
final class HelpController extends Controller
{
    public function __construct(private readonly SchoolModules $modules) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeHelp($request);
        $enabled = $this->modules->enabled($request->user()->tenant_id);

        $articles = $this->visible(HelpArticle::query(), $enabled)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'module', 'title_en', 'title_km', 'body_en', 'body_km', 'updated_at'])
            ->map(fn (HelpArticle $a) => [
                ...$a->only(['id', 'module', 'title_en', 'title_km']),
                // A short preview for the list and its search.
                'summary_en' => $this->summary($a->body_en),
                'summary_km' => $this->summary($a->body_km),
                'updated_at' => $a->updated_at?->toIso8601String(),
            ]);

        $faqs = $this->visible(HelpFaq::query(), $enabled)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'module', 'question_en', 'question_km', 'answer_en', 'answer_km']);

        $catalog = Modules::catalog();
        $used = $articles->pluck('module')->merge($faqs->pluck('module'))->filter()->unique()->values();

        return ApiResponse::success([
            // Read only: a school request never creates the settings row.
            'contact' => HelpCenterController::presentContact(SupportSettings::query()->find(1) ?? new SupportSettings),
            'modules' => $used->map(fn (string $code) => ['code' => $code, 'label' => $catalog[$code]['label'] ?? $code, 'label_km' => $catalog[$code]['label_km'] ?? null])->values(),
            'articles' => $articles->values(),
            'faqs' => $faqs->map(fn (HelpFaq $f) => $f->only(['id', 'module', 'question_en', 'question_km', 'answer_en', 'answer_km']))->values(),
        ]);
    }

    public function article(Request $request, int $article): JsonResponse
    {
        $this->authorizeHelp($request);
        $enabled = $this->modules->enabled($request->user()->tenant_id);

        $a = $this->visible(HelpArticle::query(), $enabled)->whereKey($article)->firstOrFail();
        $catalog = Modules::catalog();

        return ApiResponse::success([
            ...$a->only(['id', 'module', 'title_en', 'title_km', 'body_en', 'body_km']),
            'module_label' => $a->module ? ($catalog[$a->module]['label'] ?? $a->module) : null,
            'module_label_km' => $a->module ? ($catalog[$a->module]['label_km'] ?? null) : null,
            'updated_at' => $a->updated_at?->toIso8601String(),
        ]);
    }

    private function authorizeHelp(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->tenant_id !== null && $user->hasPermission(Permissions::HELP_SUPPORT_VIEW), 403);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @param  list<string>|null  $enabled
     * @return Builder<T>
     */
    private function visible(Builder $query, ?array $enabled): Builder
    {
        return $query->where('status', 'published')
            ->when($enabled !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('module')->orWhereIn('module', $enabled)));
    }

    private function summary(?string $body): ?string
    {
        if ($body === null || trim($body) === '') {
            return null;
        }
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($body)));

        return mb_strlen($plain) > 220 ? mb_substr($plain, 0, 217).'…' : $plain;
    }
}
