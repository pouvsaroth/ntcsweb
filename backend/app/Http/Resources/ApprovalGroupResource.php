<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ApprovalGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `members` is set by ApprovalGroupController::withMembers() as plain
 * {id, name, email} arrays — the users live in the central database, so
 * they're looked up there rather than eager-loaded.
 *
 * @mixin ApprovalGroup
 */
class ApprovalGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'members' => $this->getAttribute('member_users') ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
