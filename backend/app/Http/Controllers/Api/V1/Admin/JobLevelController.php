<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\JobLevel;

final class JobLevelController extends OrganizationUnitController
{
    protected function modelClass(): string
    {
        return JobLevel::class;
    }

    protected function usedBy(): array
    {
        return ['staff'];
    }
}
