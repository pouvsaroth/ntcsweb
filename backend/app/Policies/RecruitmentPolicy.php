<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Interview;
use App\Models\InterviewEvaluation;
use App\Models\JobPosition;
use App\Models\JobPosting;
use App\Models\OfferLetter;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * HRM > Recruitment's records other than the manpower request (which has its
 * own E-Approvals rules, see ManpowerRequestPolicy): recruitment.view to
 * read; each tab has its own Add / Update / Delete (job-postings.create,
 * interviews.update, ...). A create names its model:
 * authorize('create', [JobPosting::class, JobPosting::class]).
 */
class RecruitmentPolicy
{
    /** Model => permission prefix. */
    private const LISTS = [
        JobPosition::class => 'job-positions',
        JobPosting::class => 'job-postings',
        Applicant::class => 'applicants',
        ApplicantDocument::class => 'applicant-documents',
        Interview::class => 'interviews',
        InterviewEvaluation::class => 'interview-evaluations',
        OfferLetter::class => 'offer-letters',
    ];

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::RECRUITMENT_VIEW);
    }

    public function view(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::RECRUITMENT_VIEW);
    }

    public function create(User $user, ?string $class = null): bool
    {
        return $this->allows($user, $class, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, $record::class, 'update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, $record::class, 'delete');
    }

    private function allows(User $user, ?string $class, string $action): bool
    {
        $list = $class !== null ? (self::LISTS[$class] ?? null) : null;

        return $list !== null && $user->hasPermission("{$list}.{$action}");
    }
}
