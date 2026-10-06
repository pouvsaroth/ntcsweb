<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\JobGrade;

final class JobGradeController extends OrganizationUnitController
{
    protected function modelClass(): string
    {
        return JobGrade::class;
    }

    protected function usedBy(): array
    {
        return ['staff'];
    }
}
