<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared shape for Province/District/Commune/Village — all four carry
 * exactly the same fields (see the migration), so one resource covers all of
 * them rather than four near-identical classes.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
class GeographyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_km' => $this->name_km,
            'name_latin' => $this->name_latin,
        ];
    }
}
