<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\StaffCardDesign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add / edit a staff card design. Sent as multipart (the photos), so each
 * side's fields arrive as a JSON string and are decoded before validation.
 * Positions and sizes are in mm from the card's top-left corner.
 */
class SaveStaffCardDesignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $design = $this->route('design');

        return $design instanceof StaffCardDesign
            ? ($this->user()?->can('update', $design) ?? false)
            : ($this->user()?->can('create', [StaffCardDesign::class, $this->cardFor()]) ?? false);
    }

    /** Staff or student card: fixed once the design exists. */
    public function cardFor(): string
    {
        $design = $this->route('design');
        if ($design instanceof StaffCardDesign) {
            return $design->card_for;
        }
        $cardFor = $this->input('card_for');

        return is_string($cardFor) && in_array($cardFor, StaffCardDesign::CARD_FOR, true) ? $cardFor : StaffCardDesign::FOR_STAFF;
    }

    protected function prepareForValidation(): void
    {
        foreach (['front_fields', 'back_fields'] as $side) {
            $value = $this->input($side);
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $this->merge([$side => is_array($decoded) ? $decoded : $value]);
            }
        }
    }

    public function rules(): array
    {
        $creating = ! $this->route('design') instanceof StaffCardDesign;
        $rules = [
            'card_for' => ['sometimes', Rule::in(StaffCardDesign::CARD_FOR)],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'width_mm' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:20', 'max:300'],
            'height_mm' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:20', 'max:300'],
            'front_background' => ['nullable', 'image', 'max:8192'],
            'back_background' => ['nullable', 'image', 'max:8192'],
            'signature' => ['nullable', 'image', 'max:4096'],
            'remove_front_background' => ['sometimes', 'boolean'],
            'remove_back_background' => ['sometimes', 'boolean'],
            'remove_signature' => ['sometimes', 'boolean'],
        ];

        foreach (['front_fields', 'back_fields'] as $side) {
            $rules[$side] = [$creating ? 'present' : 'sometimes', 'array', 'max:60'];
            $rules["{$side}.*.id"] = ['required', 'string', 'max:40'];
            $rules["{$side}.*.type"] = ['required', Rule::in(StaffCardDesign::fieldTypes($this->cardFor()))];
            $rules["{$side}.*.x"] = ['required', 'numeric', 'min:-300', 'max:300'];
            $rules["{$side}.*.y"] = ['required', 'numeric', 'min:-300', 'max:300'];
            $rules["{$side}.*.w"] = ['required', 'numeric', 'min:1', 'max:300'];
            $rules["{$side}.*.h"] = ['required', 'numeric', 'min:1', 'max:300'];
            $rules["{$side}.*.font_size"] = ['nullable', 'numeric', 'min:3', 'max:72'];
            $rules["{$side}.*.color"] = ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
            $rules["{$side}.*.bold"] = ['nullable', 'boolean'];
            $rules["{$side}.*.align"] = ['nullable', Rule::in(StaffCardDesign::ALIGNS)];
            $rules["{$side}.*.text"] = ['nullable', 'string', 'max:300'];
            $rules["{$side}.*.shape"] = ['nullable', Rule::in(StaffCardDesign::SHAPES)];
            $rules["{$side}.*.font"] = ['nullable', Rule::in(StaffCardDesign::FONTS)];
        }

        return $rules;
    }

    /** Only the known keys of each field, numbers rounded to 0.1 mm; null when the side wasn't sent. */
    public function fields(string $side): ?array
    {
        $fields = $this->validated()[$side] ?? null;
        if ($fields === null) {
            return null;
        }

        return array_values(array_map(static fn (array $f): array => array_filter([
            'id' => $f['id'],
            'type' => $f['type'],
            'x' => round((float) $f['x'], 1),
            'y' => round((float) $f['y'], 1),
            'w' => round((float) $f['w'], 1),
            'h' => round((float) $f['h'], 1),
            'font_size' => isset($f['font_size']) ? round((float) $f['font_size'], 1) : null,
            'color' => $f['color'] ?? null,
            'bold' => isset($f['bold']) ? (bool) $f['bold'] : null,
            'align' => $f['align'] ?? null,
            'text' => $f['text'] ?? null,
            'shape' => $f['shape'] ?? null,
            'font' => $f['font'] ?? null,
        ], static fn ($v) => $v !== null), $fields));
    }
}
