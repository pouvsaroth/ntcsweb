# School setup (Academics)

One sidebar item, **Academic → School Setup**, with six tabs. Built for
general schools (Grade 1–12) next to the existing course school; everything
is optional, so course classes keep working as before.

| Function (request)  | Where                                   | Status before |
|---------------------|-----------------------------------------|---------------|
| Academic Year       | Programs → Academic Years               | already existed (unchanged) |
| Term / Semester     | School Setup → Terms / Semesters        | new |
| Grade Management    | School Setup → Grade levels             | new |
| Class / Section     | Classes (grade level + academic year added to the form) | extended |
| Subject Management  | Books (= subjects) + Curriculum per grade; Books list shows/filter grade levels | extended |
| Curriculum Setup    | School Setup → Curriculum               | new |
| School Calendar     | School Setup → School calendar          | new (shows the existing Holidays) |
| Promotion Rules     | School Setup → Promotion rules / Promote students | new |

## Data (school tables, each secured with `SchoolTables::secure`)

- `academic_terms` — academic_year_id, name (unique per year), dates, sort_order.
- `grade_levels` — code (unique), name, stage (kindergarten | primary |
  lower_secondary | upper_secondary | other), level_order, is_active.
  "Add Grade 1–12" creates G1–G12 (1–6 primary, 7–9 lower, 10–12 upper secondary).
  The next active level by `level_order` is where students are promoted.
- `classes.grade_level_id`, `classes.academic_year_id` (nullable).
- `book_grade_level` — the subjects (Books) of a grade, `hours_per_week`.
- `curriculum_lessons` — lessons of one subject at one grade, hours, order.
- `school_calendar_events` — opening | closing | exam | event | meeting | other,
  start/end date, optional academic year. Holidays stay in HRM → Holidays
  (they already stop student attendance); the calendar shows both, plus terms.
- `promotion_rules` — one per grade level; the row with no grade level is the
  default. Optional checks: min_average (%), min_attendance (%),
  max_failed_subjects; pass_mark (a subject below it is failed).
- `student_promotions` — one per enrollment (never promoted twice): result
  (promoted | repeated | graduated), the figures seen, the new enrollment.

## Promote students

For each student of the chosen class (enrollments not cancelled/transferred):

- **average** — mean of the latest score in each subject (exam applications of
  that enrollment; of the class's academic year when set, or with no year);
- **failed subjects** — latest subject scores below the pass mark;
- **attendance** — present + late + excused days over all recorded days.

A check with no figure (no scores, no attendance taken) is not met. Staff
choose per student: promote (enrolled in the chosen class of the next grade,
no invoice), repeat (optionally enrolled in a class of the same grade) or
graduate. The old enrollment becomes Completed.

## Permissions (menus of the Academics module)

`academic-terms`, `grade-levels`, `curriculum`, `school-calendar`,
`promotion-rules` (view / manage) and `student-promotions` (view / run).
School admin gets all; teacher and staff get view on terms, grade levels,
curriculum and calendar (migration 2026_10_21_100000 backfills existing schools).
A plan saved with a menu list keeps its list: tick the new menus there to give them.
