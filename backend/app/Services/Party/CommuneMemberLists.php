<?php

declare(strict_types=1);

namespace App\Services\Party;

use App\Models\CommuneMember;
use Illuminate\Database\Eloquent\Builder;

/**
 * Political Party > Commune members: keeps each commune's full list and
 * reserve list numbered 1, 2, 3 … with no gaps. Putting a member at a
 * position moves the ones from there down by one; no position = the end.
 * Callers run inside a transaction.
 */
final class CommuneMemberLists
{
    /**
     * Save `$member` with `$data` at its position, and renumber the list it
     * left (if it moved) and the list it joined.
     *
     * @param  array{election_year: int, province_id: int, district_id: int, commune_id: int, staff_id: int, role: string, position: int|null, remark: string|null}  $data
     */
    public function place(CommuneMember $member, array $data): CommuneMember
    {
        $old = $member->exists ? [$member->election_year, $member->commune_id, $member->role] : null;
        $target = [$data['election_year'], $data['commune_id'], $data['role']];
        $samelist = $old === $target;

        $others = $this->list(...$target)->when($member->exists, fn ($q) => $q->whereKeyNot($member->id))
            ->orderBy('position')->orderBy('id')->get();
        $wanted = $data['position'] ?? ($samelist ? $member->position : PHP_INT_MAX);
        $at = max(1, min((int) $wanted, $others->count() + 1));

        // Park the member away from the numbers while the others shift.
        $member->fill([...$data, 'position' => 0])->save();
        $n = 1;
        foreach ($others as $other) {
            if ($n === $at) {
                $n++;
            }
            if ($other->position !== $n) {
                $other->forceFill(['position' => $n])->save();
            }
            $n++;
        }
        $member->forceFill(['position' => $at])->save();

        if ($old !== null && ! $samelist) {
            $this->renumber(...$old);
        }

        return $member;
    }

    /** Close the gaps in one commune's full or reserve list. */
    public function renumber(int $year, int $communeId, string $role): void
    {
        $n = 1;
        foreach ($this->list($year, $communeId, $role)->orderBy('position')->orderBy('id')->get() as $member) {
            if ($member->position !== $n) {
                $member->forceFill(['position' => $n])->save();
            }
            $n++;
        }
    }

    /** @return Builder<CommuneMember> */
    private function list(int $year, int $communeId, string $role)
    {
        return CommuneMember::query()->where('election_year', $year)->where('commune_id', $communeId)->where('role', $role);
    }
}
