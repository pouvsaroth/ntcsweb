<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Team;

final class TeamController extends OrganizationUnitController
{
    protected function modelClass(): string
    {
        return Team::class;
    }

    protected function usedBy(): array
    {
        return ['staff'];
    }

    protected function with(): array
    {
        return ['department'];
    }
}
