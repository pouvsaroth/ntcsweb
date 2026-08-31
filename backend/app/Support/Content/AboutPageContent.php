<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * The About page has one fixed layout per school (4 stat counters, one
 * history block, 3 pillars, 4 achievements) stored in
 * `tenants.settings->about`, not a dedicated table — there is exactly one
 * of these per tenant, so a whole CRUD resource would be the wrong shape.
 * Shared between the admin editor and the public read endpoint so the
 * "not configured yet" default and the image-path-to-URL resolution live
 * in exactly one place.
 */
final class AboutPageContent
{
    /**
     * @return array{
     *     history_title: string, history_paragraph_1: string, history_paragraph_2: string,
     *     history_image_url: string|null,
     *     stats: list<array{value: string, label: string}>,
     *     pillars: list<array{icon: string, title: string, description: string}>,
     *     achievements_title: string,
     *     achievements: list<array{icon: string, value: string, label: string}>,
     * }
     */
    public static function forTenant(Tenant $tenant): array
    {
        $about = $tenant->setting('about');

        $historyImagePath = $about['history_image_path'] ?? null;

        return [
            'history_title' => $about['history_title'] ?? '',
            'history_paragraph_1' => $about['history_paragraph_1'] ?? '',
            'history_paragraph_2' => $about['history_paragraph_2'] ?? '',
            'history_image_url' => $historyImagePath !== null ? Storage::disk('public')->url($historyImagePath) : null,
            'stats' => $about['stats'] ?? self::emptyRows(4, ['value', 'label']),
            'pillars' => $about['pillars'] ?? self::emptyRows(3, ['icon', 'title', 'description']),
            'achievements_title' => $about['achievements_title'] ?? '',
            'achievements' => $about['achievements'] ?? self::emptyRows(4, ['icon', 'value', 'label']),
        ];
    }

    /**
     * Whether this school has ever saved About content — the public page
     * uses this to decide between the rich layout and its static fallback.
     */
    public static function isConfigured(Tenant $tenant): bool
    {
        return $tenant->setting('about') !== null;
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, string>>
     */
    private static function emptyRows(int $count, array $keys): array
    {
        return array_fill(0, $count, array_fill_keys($keys, ''));
    }
}
