<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Models\StaffEmergencyContact;
use App\Models\StaffFamilyMember;
use App\Models\StaffJobExperience;
use App\Models\StaffLanguage;
use App\Models\StaffOtherSkill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * The list-style tabs of the staff form (docs/hrm/staff-profile-tabs.md):
 * each kind is its own table of rows per staff member, served by
 * StaffRecordController. A kind names its model, its validation rules
 * and how its rows are ordered.
 */
final class StaffRecordKinds
{
    /**
     * @return array<string, array{model: class-string<Model>, rules: array<string, mixed>, order: list<array{0: string, 1: string}>}>
     */
    public static function all(): array
    {
        return [
            'emergency-contacts' => [
                'model' => StaffEmergencyContact::class,
                'rules' => [
                    'name' => ['required', 'string', 'max:150'],
                    'relationship' => ['required', Rule::in(StaffEmergencyContact::RELATIONSHIPS)],
                    'phone' => ['required', 'string', 'max:30'],
                    'alt_phone' => ['nullable', 'string', 'max:30'],
                    'address' => ['nullable', 'string', 'max:255'],
                    'note' => ['nullable', 'string', 'max:500'],
                ],
                'order' => [['id', 'asc']],
            ],
            'family-members' => [
                'model' => StaffFamilyMember::class,
                'rules' => [
                    'name' => ['required', 'string', 'max:150'],
                    'relationship' => ['required', Rule::in(StaffFamilyMember::RELATIONSHIPS)],
                    'gender' => ['nullable', Rule::in(StaffFamilyMember::GENDERS)],
                    'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
                    'occupation' => ['nullable', 'string', 'max:150'],
                    'phone' => ['nullable', 'string', 'max:30'],
                    'note' => ['nullable', 'string', 'max:500'],
                ],
                'order' => [['id', 'asc']],
            ],
            'languages' => [
                'model' => StaffLanguage::class,
                'rules' => [
                    'language' => ['required', 'string', 'max:100'],
                    'level' => ['required', Rule::in(StaffLanguage::LEVELS)],
                    'certificate' => ['nullable', 'string', 'max:150'],
                    'note' => ['nullable', 'string', 'max:500'],
                ],
                'order' => [['id', 'asc']],
            ],
            'job-experiences' => [
                'model' => StaffJobExperience::class,
                'rules' => [
                    'company' => ['required', 'string', 'max:150'],
                    'position' => ['required', 'string', 'max:150'],
                    'start_date' => ['nullable', 'date', 'before_or_equal:today'],
                    // Empty while still working there; never before the start.
                    'end_date' => ['nullable', 'date', Rule::when(fn ($input) => filled($input['start_date'] ?? null), ['after_or_equal:start_date'])],
                    'responsibilities' => ['nullable', 'string', 'max:1000'],
                    'reason_for_leaving' => ['nullable', 'string', 'max:255'],
                ],
                // Latest job first.
                'order' => [['start_date', 'desc'], ['id', 'desc']],
            ],
            'other-skills' => [
                'model' => StaffOtherSkill::class,
                'rules' => [
                    'skill' => ['required', 'string', 'max:150'],
                    'level' => ['nullable', Rule::in(StaffOtherSkill::LEVELS)],
                    'note' => ['nullable', 'string', 'max:500'],
                ],
                'order' => [['id', 'asc']],
            ],
        ];
    }

    /** @return array{model: class-string<Model>, rules: array<string, mixed>, order: list<array{0: string, 1: string}>} */
    public static function get(string $kind): array
    {
        return self::all()[$kind] ?? abort(404);
    }

    /** For the route constraint: emergency-contacts|family-members|... */
    public static function pattern(): string
    {
        return implode('|', array_map('preg_quote', array_keys(self::all())));
    }
}
