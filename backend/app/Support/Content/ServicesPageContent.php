<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * The website's "group of services" front page: a card for the Computer
 * School (the school's own pages, from /computer-school) and one for the
 * School Management System (its own product page). Stored in
 * `tenants.settings->services`, one per school, like AboutPageContent;
 * edited in back office → Website → Services page.
 *
 * Off unless switched on: the website keeps opening on the school's own
 * home page until then. Text is kept in Khmer and English; the public site
 * shows the visitor's language and falls back to the other when blank.
 */
final class ServicesPageContent
{
    public const CARDS = ['computer_school', 'sms'];

    /** Each card's text fields; each has a _km and an _en version. */
    public const CARD_TEXT = ['title', 'text'];

    /** The School Management System product page's text fields (_km / _en). */
    public const SMS_PAGE_TEXT = ['heading', 'description', 'features'];

    /** Where the product page's Sign in button goes until another address is set. */
    public const DEFAULT_SIGN_IN_URL = 'https://erp.newtechkh.com';

    /** Every field, blank when never saved. @return array<string, mixed> */
    public static function forTenant(Tenant $tenant): array
    {
        $saved = $tenant->setting('services') ?? [];

        $out = ['enabled' => (bool) ($saved['enabled'] ?? false)];
        foreach (self::CARDS as $card) {
            $out[$card] = self::card($saved[$card] ?? [], $card === 'sms');
        }

        return $out;
    }

    /** What the public site needs: null while the services page is off. @return array<string, mixed>|null */
    public static function publicFor(Tenant $tenant): ?array
    {
        $content = self::forTenant($tenant);

        return $content['enabled'] ? $content : null;
    }

    /** @return list<string> the text fields of one card */
    public static function textFields(string $card): array
    {
        return $card === 'sms' ? [...self::CARD_TEXT, ...self::SMS_PAGE_TEXT] : self::CARD_TEXT;
    }

    /** Validation rules for a save (multipart: two optional pictures). @return array<string, mixed> */
    public static function rules(): array
    {
        $rules = ['enabled' => ['required', 'boolean']];

        foreach (self::CARDS as $card) {
            $rules[$card] = ['nullable', 'array'];
            foreach (self::textFields($card) as $field) {
                $max = match ($field) {
                    'title', 'heading' => 255,
                    'text' => 1000,
                    default => 5000,
                };
                foreach (['km', 'en'] as $lang) {
                    $rules["{$card}.{$field}_{$lang}"] = ['nullable', 'string', "max:{$max}"];
                }
            }
            // 10M matches upload_max_filesize in docker/php/uploads.ini.
            $rules["{$card}_image"] = ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'];
        }

        $rules['sms.sign_in_url'] = ['nullable', 'url:http,https', 'max:255'];

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $saved
     * @return array<string, mixed>
     */
    private static function card(array $saved, bool $isSms): array
    {
        $card = [];
        foreach (self::textFields($isSms ? 'sms' : 'computer_school') as $field) {
            foreach (['km', 'en'] as $lang) {
                $card["{$field}_{$lang}"] = (string) ($saved["{$field}_{$lang}"] ?? '');
            }
        }

        $path = $saved['image_path'] ?? null;
        $card['image_url'] = $path !== null ? Storage::disk('public')->url($path) : null;

        if ($isSms) {
            $card['sign_in_url'] = ((string) ($saved['sign_in_url'] ?? '')) ?: self::DEFAULT_SIGN_IN_URL;
        }

        return $card;
    }
}
