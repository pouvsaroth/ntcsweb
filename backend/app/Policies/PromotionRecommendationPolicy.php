<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PromotionRecommendation;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Promotion recommendations: performance.view to see them, performance.manage
 * to recommend / cancel, performance.approve-promotion to approve or reject
 * when the school has no Approval Flow for promotions (with one, the
 * current step's group decides — see ApprovalFlow).
 */
class PromotionRecommendationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::PERFORMANCE_VIEW) || $user->hasPermission(Permissions::PERFORMANCE_APPROVE_PROMOTION);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::PERFORMANCE_MANAGE);
    }

    public function update(User $user, PromotionRecommendation $recommendation): bool
    {
        return $user->hasPermission(Permissions::PERFORMANCE_MANAGE);
    }

    public function approve(User $user, PromotionRecommendation $recommendation): bool
    {
        return $user->hasPermission(Permissions::PERFORMANCE_APPROVE_PROMOTION);
    }

    public function reject(User $user, PromotionRecommendation $recommendation): bool
    {
        return $user->hasPermission(Permissions::PERFORMANCE_APPROVE_PROMOTION);
    }
}
