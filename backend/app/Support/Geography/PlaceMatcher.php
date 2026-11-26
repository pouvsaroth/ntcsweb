<?php

declare(strict_types=1);

namespace App\Support\Geography;

use App\Models\Commune;
use App\Models\District;
use App\Models\Province;
use App\Models\Village;
use Illuminate\Support\Collection;

/**
 * Finds the place an imported Excel row names: Province, District, Commune
 * (and optionally Village), each by Khmer name, Latin name or code, looked
 * up inside the one above it (codes are not prefix-consistent). Lists are
 * loaded once per parent, so a long file stays quick.
 */
final class PlaceMatcher
{
    /** @var Collection<int, Province>|null */
    private ?Collection $provinces = null;

    /** @var array<string, Collection<int, mixed>> */
    private array $children = [];

    /**
     * The row's place ids, or a message saying which name was not found.
     *
     * @param  array<string, mixed>  $values
     * @return array{province_id: int, district_id: int, commune_id: int, village_id: int|null}|string
     */
    public function find(array $values): array|string
    {
        $this->provinces ??= Province::query()->get(['id', 'code', 'name_km', 'name_latin']);

        $province = $this->match($this->provinces, $values['province'] ?? null);
        if ($province === null) {
            return __('party.import.notFound', ['level' => __('party.levels.province'), 'name' => (string) ($values['province'] ?? '')]);
        }
        $district = $this->match($this->childrenOf(District::class, 'province_id', $province->id), $values['district'] ?? null);
        if ($district === null) {
            return __('party.import.notFound', ['level' => __('party.levels.district'), 'name' => (string) ($values['district'] ?? '')]);
        }
        $commune = $this->match($this->childrenOf(Commune::class, 'district_id', $district->id), $values['commune'] ?? null);
        if ($commune === null) {
            return __('party.import.notFound', ['level' => __('party.levels.commune'), 'name' => (string) ($values['commune'] ?? '')]);
        }
        $village = null;
        if (($values['village'] ?? null) !== null) {
            $village = $this->match($this->childrenOf(Village::class, 'commune_id', $commune->id), $values['village']);
            if ($village === null) {
                return __('party.import.notFound', ['level' => __('party.levels.village'), 'name' => (string) $values['village']]);
            }
        }

        return ['province_id' => $province->id, 'district_id' => $district->id, 'commune_id' => $commune->id, 'village_id' => $village?->id];
    }

    /**
     * @param  class-string<Model>  $model
     * @return Collection<int, mixed>
     */
    private function childrenOf(string $model, string $parentKey, int $parentId): Collection
    {
        return $this->children[$model.':'.$parentId] ??= $model::query()->where($parentKey, $parentId)->get(['id', 'code', 'name_km', 'name_latin']);
    }

    /** The place whose code, Khmer name or Latin name is `$value` (any case, extra spaces ignored). */
    private function match(Collection $places, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $wanted = $this->normal((string) $value);

        return $places->first(fn ($p) => $this->normal((string) $p->code) === $wanted
            || $this->normal((string) $p->name_km) === $wanted
            || $this->normal((string) $p->name_latin) === $wanted);
    }

    private function normal(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }
}
