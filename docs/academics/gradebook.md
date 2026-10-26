# Module 07 — Examination, Gradebook & Assessment

Results for general schools (Grade 1–12): what is assessed and how much it
counts, when exams are, entering scores, checking and calculating them,
approving them, correcting them, and the student's record over the years.

## What already exists (checked 2026-10-22)

| Already there | Used for | In this module |
|---|---|---|
| Academic years, `academic_terms`, `grade_levels` (stage: primary / lower / upper secondary) | School Setup | Reused: every result is for a class + subject + term |
| Subjects = `books`, per grade = `book_grade_level` | School Setup → Curriculum | Reused: the subjects of a class's grade are its gradebook columns |
| `classes.grade_level_id` / `academic_year_id`, enrollments | Classes | Reused: the students of a sheet are the class's enrollments |
| `teacher_assignments` (teacher + class + subject) | HRM → Teachers | Reused: a teacher enters scores for their own class/subject |
| `classrooms`, timetable, `school_calendar_events` (type exam) | Timetable, School Setup | Reused by Exam Schedule (rooms, clashes, shown on the calendar) |
| `promotion_rules` (pass mark, min average, max failed subjects) | Promote students | Reused: the pass mark; Promote students reads the new results |
| `exam_applications` + `exam_scores`, certificates | Examination (Computer School: one exam per course) | **Kept as is**, as tabs of the same page |
| eApprovals: `DocumentType`, `ApprovalFlow`, Approvals queue | Leave, overtime, corrections… | Reused: result sheets and grade corrections are two new document types |

Nothing existing is migrated or removed: the Computer School exam flow keeps
its data; the gradebook is new tables next to it.

## Decisions (owner, 2026-10-22)

| Question | Answer |
|---|---|
| Menu | **Extend Academic → Examination**: one page with tabs — the new features, then the existing Exams, Grades, Make-up Exam, Certificate |
| Who approves results / corrections | **eApprovals flows** (Approval Flow → Flow Setting). No flow set = holders of the approve permission decide |
| Primary (competency) | **Both, per grade level**: each assessment setup is *scores* (weighted components) or *competency* (levels per criterion) |
| Promote students | **Uses the gradebook** for classes with a grade level (approved yearly results); course classes keep exam applications |

Nothing about the Ministry's rules is hard-coded. A **starter setup** button
fills an example (monthly tests + semester exam, A–F bands) that the school
must check against the Ministry's current assessment guidance before use.

## Data (school tables, `SchoolTables::secure`)

- `assessment_schemes` — one per grade level (null = default): mode
  `score | competency`, name, `max_score` (the scale results are shown on,
  e.g. 100 or 10 or 50), notes.
- `assessment_components` (score mode) — name/name_km, kind (monthly, quiz,
  assignment, midterm, exam, project, other), `max_score`, `weight` (%; a
  scheme's weights add up to 100), `occurrences` per term (e.g. 3 monthly
  tests, averaged), order.
- `grade_bands` (score mode) — letter, label/label_km, `min_percent`.
- `competency_criteria` / `competency_levels` (competency mode) — criteria
  (optionally per subject); levels with label/label_km, points, passing.
- `exam_schedules` — academic year, term, component, grade level or class,
  subject, date, start/end time, room, invigilator, notes.
- `result_sheets` — class + subject + term: status `draft → pending →
  approved | returned`, who submitted / decided.
- `grade_entries` — sheet + student + component + occurrence (or criterion):
  score or level, absent, remark.
- `subject_results` — calculated per sheet + student: percent, scaled score,
  band, passed, rank in class.
- `grade_corrections` — entry, old → new, reason, status (eApprovals);
  `grade_entry_histories` — every change to an entry after approval.
- `remedial_sessions` (+ students) — subject, class, teacher, date, topic,
  students, outcome.

## Tabs (Academic → Examination), in order

1. **Assessment Setup** — schemes per grade level, components and weights, bands / competency levels.
2. **Exam Schedule** — exams by term, clash check (class, room, invigilator), print.
3. **Grade Entry** — one class + subject + term; per student or **bulk** (all students and components on one page).
4. **Grade Validation** — scores above the maximum, missing entries, absent without remark, weights not 100.
5. **Score Calculation** — totals, averages, bands, ranks per class and term.
6. **Result Approval** — submit a sheet; decided in Approvals (eApprovals).
7. **Grade Correction** — request a change with a reason; history of changes.
8. **Academic Transcript** — a student's results by year and term (PDF).
9. **Student Progress** — a student's or class's results over terms.
10. **Remedial Teaching** — students below the pass mark, sessions and outcomes.
11. **Result Export** — class score sheet (Excel), report cards (PDF).
12–15. Exams · Grades · Make-up Exam · Certificate (existing, Computer School).

## Steps (one at a time, check in after each)

1. **7a Setup** — Examination as a tabbed page; Assessment Setup (both modes, starter setup); Exam Schedule; permissions; tests.
2. **7b Entry & calculation** — result sheets, Grade Entry (single + bulk), Grade Validation, Score Calculation.
3. **7c Approval & correction** — two eApprovals document types, locking, Grade Correction with history.
4. **7d Results** — Transcript, Student Progress, Result Export, the student's own My Scores.
5. **7e Remedial & promotion** — Remedial Teaching; Promote students reads approved results. (Done 2026-10-26.)

Each step: tests, `vue-tsc -b` + build, render check (desktop + phone cards),
English + Khmer, and the new tabs added to `docs/NTCSWEB-Modules.xlsx` (edited
in place).

## As built — 7a (2026-10-22)

- School migration `2026_10_22_120000_create_assessment_and_exam_schedule_tables`
  (6 tables, each `SchoolTables::secure`); models `AssessmentScheme`
  (`for(gradeLevelId)` = own else default), `AssessmentComponent`, `GradeBand`,
  `CompetencyCriterion`, `CompetencyLevel`, `ExamSchedule`.
- Permissions `assessment.view|manage`, `exam-schedules.view|manage` (plan
  module Academics); school admin all four, teacher view; backfilled by
  `2026_10_22_120000_grant_assessment_permissions_to_default_roles`.
- `GET/POST /assessment-schemes`, `PUT/DELETE /assessment-schemes/{id}` — saved
  whole; components / bands / criteria / levels keep their ids (sent with an id
  = updated, without = added, left out = removed; a key not sent at all is left
  as is). One default (no grade) and one per grade. Each row carries
  `weights_total` and `warnings` (no_components, weights_not_100, no_bands,
  no_levels, no_criteria). `POST /assessment-schemes/{id}/starter` fills an
  empty scheme with the example (Monthly test ×3 50 % + Semester exam 50 %,
  A–F from 90/80/70/60/50/0; or levels 4–1 with 1 not passing and criteria
  Knowledge / Skills / Attitude).
- `GET/POST /exam-schedules`, `PUT/DELETE /exam-schedules/{id}`: a grade or one
  class (a class clears the grade); filters year, term, grade (also its
  classes' exams), class (also its grade's), from/to. Overlapping times on
  the same day are refused when they share students (same class, the class's
  grade, same grade), the room or the invigilator — one error per field.
- Console: Academic → Examination now opens `/admin/examination` → the first
  tab the account can open (`examinationTabs.ts`, decided in `beforeEnter`
  after the session loads). Header tabs: Assessment Setup, Exam Schedule,
  Exams, Grades, Make-up Exam, Certificate. Pages
  `pages/admin/examination/AssessmentSetup.vue` (cards; editor dialog with
  both modes) and `ExamSchedule.vue` (filters, print, phone cards).
- Text: `gradebook.*` in English and Khmer (ja/ko/zh carry the English).
- Tests: `tests/Feature/Academic/AssessmentSetupTest.php` (8).
- Checked in the browser as a demo School Director in the local demo school
  (sample year, Grade 1–12, two subjects, a room, two setups and two exams
  added there): desktop and phone, no page errors.

## As built — 7b (2026-10-22)

- School migration `2026_10_22_130000_create_gradebook_tables`: `result_sheets`
  (class + subject + term, unique; the setup it was entered under; status
  draft | pending | approved | returned), `grade_entries` (score / absent per
  component and time, or a level per criterion; remark), `subject_results`
  (percent, score on the setup's scale, band, passed, rank, complete), and
  `assessment_schemes.absent_counts_as_zero` (default yes; Assessment Setup has
  the tick).
- Permissions `gradebook.view` (own classes: the class's teacher list, a subject
  assignment or homeroom), `gradebook.enter` (own subjects: teacher list, or
  assigned that subject or all subjects), `gradebook.manage-all`. School admin
  all three, teacher view + enter (`2026_10_22_130000_grant_gradebook_permissions_to_default_roles`).
- `GradebookAccess` (who sees / enters what; a class's subjects = its grade's
  curriculum + assigned subjects), `Calculator`, `GradebookService`.
- Calculation: each component = average of its times (absent = 0 or left out)
  over its maximum; percent = weighted average (weights normalised); score =
  percent on the setup scale with its decimals; band = highest band at or below;
  passed = percent ≥ the grade's pass mark (promotion rules, default 50).
  Competency: percent = average points over the top level; band = nearest
  level; passed = no level that fails. Band / passed / rank only once complete;
  ranks shared on ties. Recalculated on every save.
- API: `GET gradebook/classes`, `GET|PUT gradebook/sheet` (a sheet is created on
  the first save; every cell is checked first — score 0..max, student in the
  class, component/time or criterion/level in the setup — nothing saved if one
  is wrong; an empty cell removes the entry; pending / approved sheets are
  locked), `GET gradebook/validation` (per class × subject: expected vs entered,
  missing, absent without remark, over max, weights not 100, no setup, not
  started), `GET gradebook/results` (subjects, average, score, band, failed
  subjects, rank — only when every subject is complete).
- Assessment Setup refuses to remove a component, criterion or level that already
  has scores.
- Console tabs Grade Entry (`/admin/examination/entry`: whole-class grid — type
  A for absent, Enter moves down, wrong cells red — or one student at a time
  with remarks; opens in one-student mode on a phone), Grade Validation (links
  into Grade Entry), Score Calculation (scores or percents, print, phone
  cards). Shared `GradebookPicker` keeps year/term/class/subject in the
  address. Header tabs now shrink and scroll instead of pushing the icons down.
- Tests: `tests/Feature/Academic/GradebookTest.php` (10).
- Local demo school: class "7A (sample)" (Grade 7) with five sample students
  and Mathematics scores entered, for render checks.

## As built — 7c (2026-10-22)

- School migration `2026_10_22_140000_create_grade_corrections_tables`:
  `result_sheets.requested_by`, `grade_corrections` (one cell of an approved
  sheet: old → new score / absent / level, reason, pending → approved |
  rejected), `grade_entry_histories` (every change to an approved cell, with
  the correction it came from; never edited).
- eApprovals document types `result_sheet` and `grade_correction`
  (`DocumentType`), with policies `ResultSheetPolicy` / `GradeCorrectionPolicy`
  and permission `gradebook.approve` (School Admin; backfilled by
  `2026_10_22_140000_grant_gradebook_approve_permission`). No flow set up =
  holders of `gradebook.approve` decide; with a flow (Approval Flow → Flow
  Setting, "Results (Examination)" / "Grade correction (Examination)") each
  step's group decides in turn. `gradebook.approve` also sees every class's
  scores (view only).
- `ResultApprovals`: submit (draft | returned → pending; refused while scores
  are missing, a score is above its maximum, weights are not 100 % or there is
  no setup), approve (→ approved, locked), return with a reason (→ returned,
  editable, the flow starts over on the next submission); request a
  correction (approved sheets only; one waiting per cell; refused when nothing
  changes), approve (entry changed, history row, sheet recalculated; the sheet
  stays approved), reject with a reason, withdraw (requester, while waiting).
  Notifications: result_sheet_submitted / approved / returned,
  grade_correction_submitted / approved / rejected.
- API: `GET gradebook/sheets` (`approval_queue=1` for the Approvals queue),
  `POST gradebook/sheets/submit`, `POST gradebook/sheets/{id}/approve|return`,
  `GET|POST gradebook/corrections`, `POST gradebook/corrections/{id}/approve|reject`,
  `DELETE gradebook/corrections/{id}`, `GET gradebook/history`.
- Console: Grade Entry — Submit for approval; approved scores are read-only
  and a click asks for a correction (dialog with the reason); cells with a
  correction waiting are amber; Change history (n). New tabs Result Approval
  (status chips, submit / approve / return, flow step) and Grade Correction
  (Waiting / Approved / Rejected; old → new, reason; approve / reject /
  withdraw). Both items are in eApprovals → Approvals (Approved / Rejected
  tabs; a returned sheet shows under Rejected) and in Flow Setting.
- Tests: `tests/Feature/Academic/ResultApprovalTest.php` (5).

## As built — 7d (2026-10-22, checked 2026-10-26)

- No new tables: everything reads the result sheets' calculated subject
  results (`StudentResults`, `ResultDocuments`).
- Access: a student's results are open to whoever may open one of the classes
  the student is (or was) in, or every class (`manage-all` / `gradebook.approve`).
  A student sees only their own approved results.
- API: `GET gradebook/students` (picker), `GET gradebook/transcript`
  (`approved_only=1` optional), `GET gradebook/transcript/pdf` (approved only),
  `GET gradebook/progress/student|class`, `GET gradebook/export/report-cards`
  (PDF, one A4 page per student, approved results of one class + term),
  `GET gradebook/export/score-sheet` (Excel via `openspout/openspout`: a summary
  sheet with every subject, average, band, rank and result, then one sheet per
  subject with every score; all results, each subject's status shown),
  `GET my-results` (the signed-in student).
- Transcript: year by year, term by term — each subject's score, band and
  rank in the class, the term average, then a year line (each subject's
  average over the terms and the year average).
- Console: new tabs Academic Transcript, Student Progress (one student or the
  whole class, `ProgressChart`) and Result Export, after Grade Correction.
  My Scores shows the student's approved school results (`TranscriptView`)
  above the computer-school exam cards.
- PDF views `pdf/transcript`, `pdf/report-cards` (shared
  `pdf/partials/gradebook-style`).
- Tests: `tests/Feature/Academic/StudentResultsTest.php` (5).

## As built — 7e (2026-10-26)

- School migration `2026_10_26_120000_create_remedial_sessions_tables`:
  `remedial_sessions` (class + subject + term, teacher, date, from–to, topic,
  notes) and `remedial_session_students` (the student's percent in the subject
  when added, came yes / no / not yet known, outcome improved | no_change |
  needs_more, remark).
- No new permissions: Remedial Teaching follows the gradebook — whoever may
  open the class sees it (`gradebook.view` own classes, `manage-all`,
  `gradebook.approve`); recording a session needs the right to enter that
  subject's scores (`gradebook.enter` own subjects, or `manage-all`).
- `RemedialTeaching`: students below the pass mark = subject results of the
  class + term under the grade's pass mark (promotion rules, default 50),
  drafts included so teachers can act early (each row shows whether its
  scores are approved / complete), with how many sessions the student was
  taken to and the latest outcome. A new session with no teacher picked gets
  the person recording it.
- API: `GET gradebook/remedial` (class + term, optional subject),
  `POST gradebook/remedial`, `PUT|DELETE gradebook/remedial/{id}`.
- Promote students (`PromotionService`): a class with a grade level that has
  result sheets uses the gradebook — each subject's average over the class's
  **approved, complete** term results; failed subjects = subject averages
  below the pass mark; drafts, pending and returned sheets do not count (the
  page says how many are not approved yet and links to Result Approval).
  Other classes keep the latest exam application scores. The response now
  has `source` (`gradebook` | `exams`) and `unapproved_sheets`.
- Console: new tab Remedial Teaching (`/admin/examination/remedial`) after
  Student Progress: the students below the mark (table; phone cards) with
  "Plan a session", and the sessions as cards; the form ticks the students
  below the mark in the chosen subject first, then attendance, outcome and a
  remark per student.
- Tests: `tests/Feature/Academic/RemedialAndPromotionTest.php` (4).
