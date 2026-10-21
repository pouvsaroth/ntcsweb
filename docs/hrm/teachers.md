# HRM > Teacher

One sidebar item, **HRM → Teachers**, with seven tabs. A teacher is a Staff
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
| Teacher Documents | Teachers → Documents | private files on the `local` disk, downloaded through the API only; type, expiry date |

Tables (school tables, `SchoolTables::secure`): `teacher_qualifications`,
`teacher_subjects`, `teacher_assignments`, `teacher_unavailabilities`,
`teacher_substitutions`, `teacher_documents`; `classes.homeroom_staff_id`.

Permissions: `teachers.view`, `teachers.manage` (HRM module menu
`teachers`), school admin by default (backfilled by
2026_10_21_110000_grant_teacher_permissions_to_school_admin_role).
