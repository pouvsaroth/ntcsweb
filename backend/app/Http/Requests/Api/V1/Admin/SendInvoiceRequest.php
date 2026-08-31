<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Authorization\Permissions;
use App\Support\Billing\NotificationChannelName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permissions::NOTIFICATIONS_SEND) ?? false;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(NotificationChannelName::all())],
            // An email address for EMAIL, a chat ID for TELEGRAM — channel-
            // specific, so this is deliberately just "a non-empty string"
            // rather than e.g. an `email` rule that would reject a Telegram
            // chat ID.
            'recipient' => ['required', 'string', 'max:191'],
        ];
    }
}
