<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Support\Assets\AssetStatus;
use InvalidArgumentException;

/**
 * Enforces `AssetStatus::TRANSITIONS` — the single gate every status change
 * on an Asset must pass through, so an illegal jump (e.g. DISPOSED straight
 * back to IN_USE) can never happen regardless of which service/controller
 * initiated the change.
 */
final class AssetStatusTransitionService
{
    public function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, AssetStatus::TRANSITIONS[$from] ?? [], true);
    }

    public function assertCanTransition(string $from, string $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidArgumentException("Cannot transition asset status from {$from} to {$to}.");
        }
    }
}
