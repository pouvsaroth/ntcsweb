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

## Class not held: students and parents told

Saving a Substitute row with no substitute (class not held) for today or later
tells the class's active students (their login) and their parent portal
logins (`class_not_held`; students link to My attendance, parents to My
children). The form's "Tell students and parents" tick (shown only for not
held, on by default) turns it off. Removing such a row tells the same people
the class is on again (`class_back_on`), only when they were told it was off.
`ClassNotHeldNotifier`.

## Make-up class (whole class)

A not-held row on Teachers → Substitute can get one make-up session
(`class_makeup_sessions`, `ClassMakeupSession`): date (not before the
not-held day), start/end time, teacher (default the teacher who was away),
note. Refused when that teacher is on leave / unavailable then, or teaches,
covers or has another make-up at that time. `PUT|DELETE
/teachers/substitutions/{id}/makeup` (`teacher-substitutes.create` /
`.delete`). Students and parents are told (`class_makeup_set`, tick on by
default) and told when it is removed (`class_makeup_cancelled`, only if they
were told). The make-up teacher can take the class's attendance that day
(`ClassAccess`), and it shows on their Timetable → Teacher week. Removing the
not-held row removes its make-up too.

A make-up session counts as a class session: the "attendance not taken"
reminder goes out 30 minutes after it ends and tells its teacher too (and
still goes out when the class's own sessions that day were not held); the
attendance lock treats its day as a class day; and Class sessions lists it as
a session of its own (`makeup` true) for its teacher — taught when the class's
attendance was saved that day, else missed / no record.

Payroll link (HRM > Payroll, both off until the school turns them on):
- Overtime tab, "Pay teachers for classes they cover": a teacher covering a
  class for someone else (a Substitute row, or another teacher's make-up
  class) is paid the session's hours at their own hourly rate times the
  Cover pay rate (1 = 100%). Payslip line "Class cover", column cover_pay;
  counts toward tax and NSSF like overtime.
- Attendance deduction tab, "Deduct missed class sessions": every missed
  session comes off at the hourly rate, also on a day already deducted as
  absent (the school's choice). Not taken off when the salary's "Deduct for
  attendance" is off. Payslip line "Missed class sessions".
Only days up to today count; Class sessions never lists days still to come.
