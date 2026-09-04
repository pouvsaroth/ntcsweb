<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Video;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Video::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'course_package_id' => ['required', Rule::exists('course_packages', 'id')->where('tenant_id', $tenantId)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Just needs to look like a YouTube link at all — Video::
            // youtubeId() is what actually extracts the video id, and is
            // deliberately tolerant of watch?v=/youtu.be//embed//shorts/
            // shapes; this only rejects something that obviously isn't one.
            'video_url' => ['required', 'url', 'max:500', 'regex:/(?:youtube\.com|youtu\.be)/i'],
            // Same shape as CoursePackage's own thumbnail upload.
            'thumbnail' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in([Video::STATUS_ACTIVE, Video::STATUS_INACTIVE])],
        ];
    }
}
