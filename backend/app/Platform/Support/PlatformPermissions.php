<?php

declare(strict_types=1);

namespace App\Platform\Support;

/**
 * The catalogue in config/platform.php, read as a tree (for the editor) or as
 * the flat list of keys a role may hold.
 */
final class PlatformPermissions
{
    /** @return list<array{key: string, label: string, label_km: ?string, children: list<array<string, mixed>>}> */
    public static function tree(): array
    {
        $normalise = static function (array $node) use (&$normalise): array {
            return [
                'key' => $node['key'],
                'label' => $node['label'],
                'label_km' => $node['label_km'] ?? null,
                'children' => array_map($normalise, $node['children'] ?? []),
            ];
        };

        return array_map($normalise, config('platform.permissions', []));
    }

    /** @return list<string> every leaf key */
    public static function keys(): array
    {
        $keys = [];
        $walk = static function (array $node) use (&$walk, &$keys): void {
            if (empty($node['children'])) {
                $keys[] = $node['key'];

                return;
            }
            foreach ($node['children'] as $child) {
                $walk($child);
            }
        };
        foreach (config('platform.permissions', []) as $node) {
            $walk($node);
        }

        return $keys;
    }
}
