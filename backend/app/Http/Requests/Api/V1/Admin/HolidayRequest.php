<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Holiday;
use App\Models\LookupValue;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Create/update a holiday — `end_date` defaults to `start_date` (a one-day holiday). */
class HolidayRequest extends FormRequest
{
    /** Here, not only in the controller, so a viewer gets 403 before any 422 for a missing field. */
    public function authorize(): bool
    {
        $holiday = $this->route('holiday');

        return $holiday instanceof Holiday
            ? ($this->user()?->can('update', $holiday) ?? false)
            : ($this->user()?->can('create', Holiday::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('start_date') && ! $this->filled('end_date')) {
            $this->merge(['end_date' => $this->input('start_date')]);
        }
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            // A HOLIDAY_TYPE value's code — an inactive one still counts, so
            // an older holiday keeps saving after a school retires its type.
            'holiday_type' => [$required, 'string', 'max:50', function (string $attribute, mixed $value, Closure $fail): void {
                $known = LookupValue::query()
                    ->where('code', $value)
                    ->whereHas('category', fn ($query) => $query->where('code', Holiday::TYPE_CATEGORY))
                    ->exists();
                if (! $known) {
                    $fail(__('Choose a holiday type from the list.'));
                }
            }],
            'start_date' => [$required, 'date'],
            'end_date' => [$required, 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
