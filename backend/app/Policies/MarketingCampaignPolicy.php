<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MarketingCampaign;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Marketing > Campaigns: campaigns.view / create / update / delete. */
class MarketingCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CAMPAIGNS_VIEW);
    }

    public function view(User $user, MarketingCampaign $campaign): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::CAMPAIGNS_CREATE);
    }

    public function update(User $user, MarketingCampaign $campaign): bool
    {
        return $user->hasPermission(Permissions::CAMPAIGNS_UPDATE);
    }

    public function delete(User $user, MarketingCampaign $campaign): bool
    {
        return $user->hasPermission(Permissions::CAMPAIGNS_DELETE);
    }
}
