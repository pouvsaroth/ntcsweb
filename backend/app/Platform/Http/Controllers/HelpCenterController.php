<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Billing\Modules;
use App\Platform\Models\HelpArticle;
use App\Platform\Models\HelpFaq;
use App\Platform\Models\SupportSettings;
use App\Platform\Support\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Back office → Support → Help center (docs/backoffice/stage-13-help-support.md):
 * the help articles, FAQ and contacts every school sees in Help & Support.
 * Reading needs platform.support.read; writing platform.support.help.
 */
final class HelpCenterController extends Controller
{
    public function __construct(private readonly PlatformAudit $audit) {}

    /** The console modules an article or question can belong to. */
    public function modules(): JsonResponse
    {
        return ApiResponse::success(collect(Modules::catalog())
            ->map(fn (array $m, string $code) => ['code' => $code, 'label' => $m['label'], 'label_km' => $m['label_km']])
            ->values());
    }

    // --- Articles -----------------------------------------------------------------

    public function articles(): JsonResponse
    {
        return ApiResponse::success(HelpArticle::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (HelpArticle $a) => $this->article($a))->values());
    }

    public function storeArticle(Request $request): JsonResponse
    {
        $data = $this->validateArticle($request);
        $article = HelpArticle::query()->create([
            ...$data,
            'sort_order' => $data['sort_order'] ?? ((int) HelpArticle::query()->max('sort_order') + 1),
            'updated_by' => auth('staff')->id(),
        ]);
        $this->audit->record('support.help_article_created', $article, new: ['title' => $article->title_en ?: $article->title_km, 'status' => $article->status]);

        return ApiResponse::created($this->article($article));
    }

    public function updateArticle(Request $request, HelpArticle $article): JsonResponse
    {
        $old = ['title' => $article->title_en ?: $article->title_km, 'status' => $article->status];
        $article->update([...$this->validateArticle($request), 'updated_by' => auth('staff')->id()]);
        $this->audit->record('support.help_article_updated', $article, $old, ['title' => $article->title_en ?: $article->title_km, 'status' => $article->status]);

        return ApiResponse::success($this->article($article->fresh()));
    }

    public function destroyArticle(HelpArticle $article): JsonResponse
    {
        $this->audit->record('support.help_article_deleted', $article, ['title' => $article->title_en ?: $article->title_km]);
        $article->delete();

        return ApiResponse::noContent();
    }

    // --- FAQ ----------------------------------------------------------------------

    public function faqs(): JsonResponse
    {
        return ApiResponse::success(HelpFaq::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (HelpFaq $f) => $this->faq($f))->values());
    }

    public function storeFaq(Request $request): JsonResponse
    {
        $data = $this->validateFaq($request);
        $faq = HelpFaq::query()->create([
            ...$data,
            'sort_order' => $data['sort_order'] ?? ((int) HelpFaq::query()->max('sort_order') + 1),
            'updated_by' => auth('staff')->id(),
        ]);
        $this->audit->record('support.help_faq_created', $faq, new: ['question' => $faq->question_en ?: $faq->question_km, 'status' => $faq->status]);

        return ApiResponse::created($this->faq($faq));
    }

    public function updateFaq(Request $request, HelpFaq $faq): JsonResponse
    {
        $old = ['question' => $faq->question_en ?: $faq->question_km, 'status' => $faq->status];
        $faq->update([...$this->validateFaq($request), 'updated_by' => auth('staff')->id()]);
        $this->audit->record('support.help_faq_updated', $faq, $old, ['question' => $faq->question_en ?: $faq->question_km, 'status' => $faq->status]);

        return ApiResponse::success($this->faq($faq->fresh()));
    }

    public function destroyFaq(HelpFaq $faq): JsonResponse
    {
        $this->audit->record('support.help_faq_deleted', $faq, ['question' => $faq->question_en ?: $faq->question_km]);
        $faq->delete();

        return ApiResponse::noContent();
    }

    // --- Contacts -----------------------------------------------------------------

    public function contact(): JsonResponse
    {
        return ApiResponse::success(self::presentContact(SupportSettings::current()));
    }

    public function updateContact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'help_phone' => ['nullable', 'string', 'max:100'],
            'help_telegram' => ['nullable', 'string', 'max:200'],
            'help_email' => ['nullable', 'email', 'max:200'],
            'help_hours_en' => ['nullable', 'string', 'max:300'],
            'help_hours_km' => ['nullable', 'string', 'max:300'],
        ]);
        $settings = SupportSettings::current();
        $old = self::presentContact($settings);
        $settings->update(array_map(fn ($v) => is_string($v) ? (trim($v) ?: null) : $v, $data));
        $this->audit->record('support.help_contact_updated', $settings, $old, self::presentContact($settings));

        return ApiResponse::success(self::presentContact($settings->fresh()));
    }

    /** @return array<string, string|null> */
    public static function presentContact(SupportSettings $s): array
    {
        return [
            'phone' => $s->help_phone,
            'telegram' => $s->help_telegram,
            'email' => $s->help_email,
            'hours_en' => $s->help_hours_en,
            'hours_km' => $s->help_hours_km,
        ];
    }

    // --- Helpers ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function validateArticle(Request $request): array
    {
        $data = $request->validate([
            'module' => ['nullable', 'string', Rule::in(array_keys(Modules::catalog()))],
            'title_en' => ['nullable', 'string', 'max:200'],
            'title_km' => ['nullable', 'string', 'max:200'],
            'body_en' => ['nullable', 'string', 'max:50000'],
            'body_km' => ['nullable', 'string', 'max:50000'],
            'status' => ['required', Rule::in(HelpArticle::STATUSES)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        if (blank($data['title_en'] ?? null) && blank($data['title_km'] ?? null)) {
            throw ValidationException::withMessages(['title_en' => __('Give the article a title in English or Khmer.')]);
        }
        if (blank($data['body_en'] ?? null) && blank($data['body_km'] ?? null)) {
            throw ValidationException::withMessages(['body_en' => __('Write the article in English or Khmer.')]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function validateFaq(Request $request): array
    {
        $data = $request->validate([
            'module' => ['nullable', 'string', Rule::in(array_keys(Modules::catalog()))],
            'question_en' => ['nullable', 'string', 'max:300'],
            'question_km' => ['nullable', 'string', 'max:300'],
            'answer_en' => ['nullable', 'string', 'max:10000'],
            'answer_km' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(HelpFaq::STATUSES)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        if (blank($data['question_en'] ?? null) && blank($data['question_km'] ?? null)) {
            throw ValidationException::withMessages(['question_en' => __('Write the question in English or Khmer.')]);
        }
        if (blank($data['answer_en'] ?? null) && blank($data['answer_km'] ?? null)) {
            throw ValidationException::withMessages(['answer_en' => __('Write the answer in English or Khmer.')]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function article(HelpArticle $a): array
    {
        return [
            ...$a->only(['id', 'module', 'title_en', 'title_km', 'body_en', 'body_km', 'status', 'sort_order']),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function faq(HelpFaq $f): array
    {
        return [
            ...$f->only(['id', 'module', 'question_en', 'question_km', 'answer_en', 'answer_km', 'status', 'sort_order']),
            'updated_at' => $f->updated_at?->toIso8601String(),
        ];
    }
}
