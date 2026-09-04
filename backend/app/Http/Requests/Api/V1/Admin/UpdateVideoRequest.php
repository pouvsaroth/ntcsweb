<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Video;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Video $video */
        $video = $this->route('video');

        return $this->user()?->can('update', $video) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'course_package_id' => ['sometimes', 'required', Rule::exists('course_packages', 'id')->where('tenant_id', $tenantId)],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'video_url' => ['sometimes', 'required', 'url', 'max:500', 'regex:/(?:youtube\.com|youtu\.be)/i'],
            'thumbnail' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in([Video::STATUS_ACTIVE, Video::STATUS_INACTIVE])],
        ];
    }
}
