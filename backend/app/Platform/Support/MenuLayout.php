<?php

declare(strict_types=1);

namespace App\Platform\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * An organization type's own sidebar (Back office > Organization types > Menu layout).
 *
 *   groups: [{ key, names?, icon?, hidden, items: [{ to, from?, names?, hidden }] }]
 *   tabs:   { "<first tab path of a page>": ["<tab path>", ...] }   tab order per page
 *
 * A group key is a standard group ("adminNav.groups.staff") or a new one
 * ("custom:politic", which needs an English name). An item is a menu link by
 * its path and the group it comes from (`from`: some paths sit in two
 * groups); a page tab's path placed in a group (`from: "tab"`) is that tab
 * taken out of its page. The console (frontend router/menuLayout.ts) keeps anything the
 * layout does not mention in its usual place, so new features still show.
 * Hiding only changes the sidebar: plans and roles still decide access.
 */
final class MenuLayout
{
    private const LANGS = ['en', 'km', 'zh', 'ja', 'ko'];

    /**
     * @param  array<string, mixed>  $input
     * @return array{groups: list<array<string, mixed>>, tabs: array<string, list<string>>}
     *
     * @throws ValidationException
     */
    public static function normalize(array $input): array
    {
        $path = ['required', 'string', 'max:200', 'regex:#^/admin(/[A-Za-z0-9_\-/]*)?(\?[A-Za-z0-9_=&\-]*)?$#'];
        $name = ['nullable', 'string', 'max:60'];
        $rules = [
            'groups' => ['present', 'array', 'max:60'],
            'groups.*.key' => ['required', 'string', 'max:80', 'regex:/^(adminNav\.groups\.[A-Za-z0-9]+|custom:[a-z0-9][a-z0-9-]*)$/', 'distinct'],
            'groups.*.icon' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9]+$/'],
            'groups.*.hidden' => ['sometimes', 'boolean'],
            'groups.*.names' => ['sometimes', 'nullable', 'array'],
            'groups.*.items' => ['present', 'array', 'max:300'],
            'groups.*.items.*.to' => $path,
            'groups.*.items.*.from' => ['nullable', 'string', 'max:80', 'regex:/^(adminNav\.groups\.[A-Za-z0-9]+|tab)$/'],
            'groups.*.items.*.hidden' => ['sometimes', 'boolean'],
            'groups.*.items.*.names' => ['sometimes', 'nullable', 'array'],
            'tabs' => ['sometimes', 'nullable', 'array', 'max:60'],
            'tabs.*' => ['array', 'max:40'],
            'tabs.*.*' => $path,
        ];
        foreach (self::LANGS as $lang) {
            $rules["groups.*.names.{$lang}"] = $name;
            $rules["groups.*.items.*.names.{$lang}"] = $name;
        }
        $data = Validator::make($input, $rules)->validate();

        $errors = [];
        $seen = [];
        $groups = [];
        foreach ($data['groups'] as $g => $group) {
            $names = self::names($group['names'] ?? null);
            if (str_starts_with($group['key'], 'custom:') && ! isset($names['en'])) {
                $errors["groups.{$g}.names.en"] = __('A new menu group needs an English name.');
            }
            $items = [];
            foreach ($group['items'] as $i => $item) {
                $id = ($item['from'] ?? '').'|'.$item['to'];
                if (isset($seen[$id])) {
                    $errors["groups.{$g}.items.{$i}.to"] = __('This menu item is already in another group.');
                }
                $seen[$id] = true;
                $items[] = array_filter([
                    'to' => $item['to'],
                    'from' => $item['from'] ?? null,
                    'names' => self::names($item['names'] ?? null) ?: null,
                    'hidden' => (bool) ($item['hidden'] ?? false) ?: null,
                ], fn ($v) => $v !== null);
            }
            $groups[] = array_filter([
                'key' => $group['key'],
                'names' => $names ?: null,
                'icon' => $group['icon'] ?? null,
                'hidden' => (bool) ($group['hidden'] ?? false) ?: null,
                'items' => $items,
            ], fn ($v) => $v !== null);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $tabs = [];
        foreach ($data['tabs'] ?? [] as $bar => $order) {
            if (! is_string($bar) || ! str_starts_with($bar, '/admin')) {
                throw ValidationException::withMessages(['tabs' => __('Unknown page tabs.')]);
            }
            $tabs[$bar] = array_values(array_unique($order));
        }

        return ['groups' => $groups, 'tabs' => $tabs];
    }

    /** @return array<string, string> */
    private static function names(mixed $names): array
    {
        $out = [];
        foreach (self::LANGS as $lang) {
            $word = trim((string) (is_array($names) ? ($names[$lang] ?? '') : ''));
            if ($word !== '') {
                $out[$lang] = $word;
            }
        }

        return $out;
    }
}
