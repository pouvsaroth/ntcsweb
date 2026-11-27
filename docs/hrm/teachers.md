# HRM > Teacher

One sidebar item, **HRM → Teachers**, with eight tabs. A teacher is a Staff
member whose **position carries the Teacher role** (HRM → Staff, Positions);
there is no separate teacher record.

| Request | Where | Note |
|---|---|---|
| Teacher Profile (name, gender, phone, address) | HRM → Staff (Current Info) | already in Staff, not repeated |
| Teacher Employment (staff ID, position, status, start date) | HRM → Staff + Staff Status History | already in Staff, not repeated |
| Qualification | Teachers → Qualification | degrees (with level), certificates, skills; specialised subjects (Books) |
| Teacher Assignment | Teachers → Assignment | teacher + class + subject + academic year + hours/week; also adds them to the class's teachers |
| Homeroom Assignment | Teachers → Homeroom | `classes.homeroom_staff_id`; saves on change |
| Teaching Load | Teachers → Teaching load | class hours/week from the weekly schedules of the upcoming/active classes they teach, assigned hours, classes, homeroom |
| Teacher Availability | Teachers → Availability | weekly slots or dates they cannot teach; their approved leave (Leave Management); class times that clash |
| Substitute Teacher | Teachers → Substitute | per class and day: who is away (leave / unavailable) and who is free (not away, not teaching or covering another class then) |
| Class sessions | Teachers → Class sessions | each scheduled session in a period (up to 93 days) and what happened: taught (student attendance saved; late when the teacher checked in after the start), covered (Substitute row with a substitute; the substitute gets a covering row), not held (Substitute row with no substitute), missed (no attendance and on leave / no check-in / no teacher), no record (checked in, no attendance). Per-teacher totals, Excel/PDF. Skips holidays, closing days, days outside the class dates, days with no students and sessions not ended yet. `ClassSessionReport`, nothing stored |
| Teacher Documents | Teachers → Documents | private files on the `local` disk, downloaded through the API only; type, expiry date |

Tables (school tables, `SchoolTables::secure`): `teacher_qualifications`,
`teacher_subjects`, `teacher_assignments`, `teacher_unavailabilities`,
`teacher_substitutions`, `teacher_documents`; `classes.homeroom_staff_id`.

Permissions: `teachers.view`, `teachers.manage` (HRM module menu
`teachers`), school admin by default (backfilled by
2026_10_21_110000_grant_teacher_permissions_to_school_admin_role).

## Teacher absence alert

`teachers:alert-absent` (every 5 minutes, `TeacherAbsenceAlertService`) tells
whoever can arrange substitutes (`teacher-substitutes.create`; the school
admins when nobody can) when a teacher with a class session today:

- is on approved leave (`teacher_on_leave_class`), as soon as it is seen, or
- has not checked in 10 minutes after the session started
  (`teacher_not_checked_in_class`) — only for teachers who checked in at
  least once in the last 30 days, so schools not using check-in get none.

Not sent when a Substitute row already covers the teacher (or the class is not
held), student attendance is already saved today, it is a day off, the class
has no students, or the session has ended. Once per class, teacher, day and
session. The link opens Teachers → Substitute with the form filled in
(`?class_id&date&absent_staff_id&start_time&end_time`).
