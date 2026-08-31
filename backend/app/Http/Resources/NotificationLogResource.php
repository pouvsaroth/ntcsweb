<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NotificationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationLog
 */
class NotificationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'channel' => $this->channel,
            'recipient' => $this->recipient,
            'type' => $this->type,
            'status' => $this->status,
            'provider_message_id' => $this->provider_message_id,
            'error_message' => $this->error_message,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'sent_by' => $this->whenLoaded('sentBy', fn () => $this->sentBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
