<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The website's "group of services" front page: a list of service cards,
 * edited in back office → Website → Services page. Each card opens its own
 * page on the website (/services/{slug}: heading, description, features, an
 * optional button) — except the one card that opens the Computer School's
 * pages (/computer-school).
 *
 * A card can also open any web address (kind `link`), or the website's
 * Downloads page (kind `downloads`, one card at most).
 *
 * Stored in `tenants.settings->services` = { enabled, cards: [...] }, one per
 * school. Off unless switched on: the website opens on the school's own home
 * page until then. Text is kept in Khmer and English; the public site shows
 * the visitor's language and falls back to the other when blank.
 *
 * The first version stored exactly two cards (`computer_school`, `sms`);
 * that shape is read as two cards and rewritten on the next save.
 */
final class ServicesPageContent
{
    /** Opens the Computer School's pages. At most one card. */
    public const KIND_SCHOOL = 'school';

    /** Opens its own page at /services/{slug}. */
    public const KIND_PAGE = 'page';

    /** Opens a web address (another site, or any page). */
    public const KIND_LINK = 'link';

    /** Opens the website's Downloads page (/downloads). At most one card. */
    public const KIND_DOWNLOADS = 'downloads';

    public const KINDS = [self::KIND_SCHOOL, self::KIND_PAGE, self::KIND_LINK, self::KIND_DOWNLOADS];

    /** Kinds only one card may have: each opens one fixed part of the website. */
    public const SINGLE_KINDS = [self::KIND_SCHOOL, self::KIND_DOWNLOADS];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** Fields every card has, each with a _km and an _en version. */
    public const CARD_TEXT = ['title', 'text'];

    /** Fields of a card's own page, each with a _km and an _en version. */
    public const PAGE_TEXT = ['heading', 'description', 'features', 'button_label'];

    /** Every field, cards in order, pictures as URLs. @return array{enabled: bool, cards: list<array<string, mixed>>} */
    public static function forTenant(Tenant $tenant): array
    {
        $saved = self::stored($tenant);

        return [
            'enabled' => $saved['enabled'],
            'cards' => array_map(self::present(...), $saved['cards']),
        ];
    }

    /** What the public site needs: the shown cards, or null while the page is off. @return array<string, mixed>|null */
    public static function publicFor(Tenant $tenant): ?array
    {
        $content = self::forTenant($tenant);
        if (! $content['enabled']) {
            return null;
        }

        $content['cards'] = array_values(array_filter($content['cards'], fn (array $c) => $c['status'] === self::STATUS_ACTIVE));

        return $content;
    }

    /**
     * The stored shape, cards sorted, the first version converted.
     *
     * @return array{enabled: bool, cards: list<array<string, mixed>>}
     */
    public static function stored(Tenant $tenant): array
    {
        $saved = $tenant->setting('services') ?? [];
        $cards = isset($saved['cards']) && is_array($saved['cards']) ? $saved['cards'] : self::fromFirstVersion($saved);

        usort($cards, fn (array $a, array $b) => [(int) ($a['sort_order'] ?? 0), (string) ($a['id'] ?? '')] <=> [(int) ($b['sort_order'] ?? 0), (string) ($b['id'] ?? '')]);

        return ['enabled' => (bool) ($saved['enabled'] ?? false), 'cards' => array_values($cards)];
    }

    /** Save the switch and the cards (as stored: picture paths, not URLs). @param  list<array<string, mixed>>  $cards */
    public static function save(Tenant $tenant, bool $enabled, array $cards): void
    {
        $tenant->update(['settings' => [...($tenant->settings ?? []), 'services' => ['enabled' => $enabled, 'cards' => array_values($cards)]]]);
    }

    /** Validation rules for adding or changing one card (multipart: an optional picture). @return array<string, mixed> */
    public static function cardRules(): array
    {
        $rules = [
            'kind' => ['required', Rule::in(self::KINDS)],
            'link_url' => ['nullable', 'required_if:kind,'.self::KIND_LINK, 'url:http,https', 'max:255'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'status' => ['required', Rule::in([self::STATUS_ACTIVE, self::STATUS_INACTIVE])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'button_url' => ['nullable', 'url:http,https', 'max:255'],
            // 10M matches upload_max_filesize in docker/php/uploads.ini.
            'image' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
        ];
        foreach ([...self::CARD_TEXT, ...self::PAGE_TEXT] as $field) {
            $max = match ($field) {
                'title', 'heading' => 255,
                'button_label' => 60,
                'text' => 1000,
                default => 5000,
            };
            foreach (['km', 'en'] as $lang) {
                $rules["{$field}_{$lang}"] = ['nullable', 'string', "max:{$max}"];
            }
        }

        return $rules;
    }

    /** A page address from the English title (or the Khmer one), unique among the other cards. @param  list<string>  $taken */
    public static function uniqueSlug(string $wanted, array $taken): string
    {
        $base = Str::slug($wanted) ?: 'service';
        $slug = $base;
        for ($i = 2; in_array($slug, $taken, true); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /** @param  array<string, mixed>  $card @return array<string, mixed> */
    private static function present(array $card): array
    {
        $out = [
            'id' => (string) ($card['id'] ?? ''),
            'kind' => in_array($card['kind'] ?? null, self::KINDS, true) ? $card['kind'] : self::KIND_PAGE,
            'slug' => (string) ($card['slug'] ?? ''),
            'link_url' => (string) ($card['link_url'] ?? ''),
            'status' => ($card['status'] ?? self::STATUS_ACTIVE) === self::STATUS_INACTIVE ? self::STATUS_INACTIVE : self::STATUS_ACTIVE,
            'sort_order' => (int) ($card['sort_order'] ?? 0),
            'button_url' => (string) ($card['button_url'] ?? ''),
        ];
        foreach ([...self::CARD_TEXT, ...self::PAGE_TEXT] as $field) {
            foreach (['km', 'en'] as $lang) {
                $out["{$field}_{$lang}"] = (string) ($card["{$field}_{$lang}"] ?? '');
            }
        }
        $path = $card['image_path'] ?? null;
        $out['image_url'] = $path !== null ? Storage::disk('public')->url($path) : null;

        return $out;
    }

    /**
     * The first version: { enabled, computer_school: {...}, sms: {...} } →
     * the Computer School card and a School Management System page card.
     *
     * @param  array<string, mixed>  $saved
     * @return list<array<string, mixed>>
     */
    private static function fromFirstVersion(array $saved): array
    {
        if (! isset($saved['computer_school']) && ! isset($saved['sms'])) {
            return [];
        }
        $school = (array) ($saved['computer_school'] ?? []);
        $sms = (array) ($saved['sms'] ?? []);

        return [
            [
                'id' => 'computer-school', 'kind' => self::KIND_SCHOOL, 'slug' => '', 'status' => self::STATUS_ACTIVE, 'sort_order' => 0,
                ...array_intersect_key($school, array_flip(['title_km', 'title_en', 'text_km', 'text_en', 'image_path'])),
            ],
            [
                'id' => 'school-management-system', 'kind' => self::KIND_PAGE, 'slug' => 'school-management-system', 'status' => self::STATUS_ACTIVE, 'sort_order' => 1,
                ...array_intersect_key($sms, array_flip(['title_km', 'title_en', 'text_km', 'text_en', 'image_path', 'heading_km', 'heading_en', 'description_km', 'description_en', 'features_km', 'features_en'])),
                'button_label_en' => 'Sign in',
                'button_label_km' => 'ចូលប្រើ',
                'button_url' => ((string) ($sms['sign_in_url'] ?? '')) ?: 'https://erp.newtechkh.com',
            ],
        ];
    }
}
