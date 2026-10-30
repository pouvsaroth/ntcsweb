# HRM > Professional Development

Module 10, Teacher Professional Development. It is one sidebar item,
**HRM → Professional development** (`/admin/professional-development`), with
its sections as tabs. It is part of the HRM plan module, menu
`professional-development`.

Decisions (2026-10-30):
- Any staff member can be assigned to a training, coaching or development
  plan, and the school chooses who. Classroom observation is for teachers only.
- A training certificate is issued per attendee and is also kept as a
  "certificate" row in **Teachers → Qualification**.
- Observations use school-made forms with 1–5 star criteria. They do not count
  toward the Performance score.

| Tab | Status | What it does |
|---|---|---|
| Training records | step 1 | Every training per person, with attendance, result, score and certificate; totals (trainings, attended, hours, certificates); add a past or outside course |
| Training calendar | step 1 | Month grid (busy days list on phone) or list; plan a training (category, internal/external, trainer, mode, place, dates, times, hours, cost); assign staff (notified); take attendance and results; mark completed or cancelled (attendees notified) |
| Certificates | step 1 | Issued, waiting (attended a completed training, not failed, none yet), expiring within 60 days; issue with a number (blank → `CERT-<year>-0001`), dates and an optional scan; revoke |
| Classroom observation | step 2 | Teachers only. Schedule (teacher, observer, class, subject, school-made form, date, focus; both notified); observer rates each criterion 1–5 stars with comments, strengths, areas to improve, next steps; complete (teacher notified, sees it only now) and teacher acknowledges with a comment. Forms sub-view: sections + criteria, starter set. Not counted in Performance |
| Coaching & mentoring | step 2 | Pair a coach/mentor with any staff member around a goal (optionally from an observation's next steps; both notified); log sessions (date, minutes, topic, notes, action items); close with an outcome |
| Development plan | step 3 | A plan per staff member for a period, with an optional reviewer: summary and goals (area, how, support, an optional linked training or coaching, target date). Draft until shared (staff and reviewer notified). Staff, reviewer or manager update each goal (status, progress, note; done = 100%); plan progress is the average of goals not dropped; overdue goals are flagged. Reviewer or manager writes the review and completes it |
| Training reports | step 3 | For a date range (default this year, max 3 years): totals (trainings, hours, attendance rate, cost of completed trainings, certificates, observations + average stars, coaching sessions, active plans), hours by month, by category, and one row per staff member including those with no training (teachers-only and no-training filters); CSV download of the staff table |

## Data

School tables (`SchoolTables::secure`):
- `trainings`: title, category, provider_type (internal | external),
  trainer, mode (in_person | online), location, start/end date, start/end
  time, hours, cost, description, status (planned | completed | cancelled).
- `training_participants`: training + staff (unique), attendance
  (registered | attended | absent), result (passed | failed), score, note,
  certificate number / issued / expires / private file (`local` disk,
  `tenants/<id>/training-certificates/<staff>`), `teacher_qualification_id`.

A training created as *completed* (a past or outside course) starts its people
as *attended* and does not notify anyone. Removing a person, deleting a
training or revoking a certificate also deletes the certificate file and its
Qualification row. Renaming a training or changing its trainer updates those
Qualification rows.

Step 2 tables: `observation_forms` + `observation_form_criteria` (section,
text, order); `classroom_observations` (teacher, observer, class, subject,
form + form name copy, date/times, focus, status scheduled | completed |
cancelled, strengths / improvements / next steps, average, acknowledged_at,
teacher_comment); `classroom_observation_ratings` (criteria copied from the
form, so later form edits don't change past observations); `coachings` (type
coaching | mentoring, coach, staff, optional observation, goal, dates, status
active | completed | cancelled, outcome); `coaching_sessions`.

Who sees what: holders of `professional-development.view` see everything;
other staff (`my-requests.view`) see only observations of or by them and
coachings they give or receive. The observer (or a manager) records; only the
teacher acknowledges; the coach (or a manager) logs sessions and closes.

Step 3 tables: `development_plans` (staff, reviewer, title, dates, status
draft | active | completed | cancelled, summary, review_note, completed_at)
and `development_plan_goals` (title, area, actions, support, training_id,
coaching_id, target_date, status not_started | in_progress | done | dropped,
progress 0–100, staff_note, sort_order). Saving a plan replaces its goal list
but keeps the goals whose ids are sent, so progress survives edits. Drafts are
manager-only; once shared, the staff member and the reviewer see the plan.
Training reports count hours only for people marked attended on a completed
training, by the month the training started.

## API

`/api/v1/professional-development/…`: `trainings` (CRUD, `summary`,
`calendar?month=`, `categories`, `{id}/attendance`), `staff-options`,
`records` (+ `totals`), `certificates?state=issued|pending|expiring`,
`records/{participant}/certificate` (POST issue, DELETE revoke, GET `/file`),
`observation-forms` (CRUD), `observations` (CRUD, `summary`, `options`,
`{id}/record`, `{id}/acknowledge`), `coachings` (CRUD, `summary`,
`{id}/sessions`), `coaching-sessions/{id}` (PUT, DELETE), `development-plans` (CRUD, `summary`,
`options?staff_id=`), `development-plan-goals/{id}/progress` (PUT),
`reports?from=&to=` (+ `&format=csv`).

Permissions: `professional-development.view`, `professional-development.manage`
(School Admin by default; backfilled by central migration
2026_10_30_010000_add_professional_development_menu, which also adds the menu
to plans that list their menus). Notifications: `training_assigned`,
`training_cancelled`, `observation_scheduled`, `observation_completed`,
`coaching_assigned`, `development_plan_shared`.

Tests: `tests/Feature/Hrm/TrainingTest.php`, `ObservationCoachingTest.php`, `DevelopmentPlanReportTest.php`.
