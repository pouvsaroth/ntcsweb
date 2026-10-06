<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Branch;

final class BranchController extends OrganizationUnitController
{
    protected function modelClass(): string
    {
        return Branch::class;
    }

    protected function usedBy(): array
    {
        return ['departments', 'staff'];
    }
}
