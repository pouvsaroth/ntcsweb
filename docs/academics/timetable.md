# Academic > Timetable

The timetable **is the classes' existing weekly times** (`class_schedules`,
set on the class form) — not a second schedule. Each time can now also have a
**subject**, its own **teacher** (else the class's teachers) and its own
**room** (else the class's room). The class form keeps these when it saves
(a time with the same day and start keeps its details).

| Request | Where |
|---|---|
| Class Timetable | Timetable → Class: a class's week; managers add/edit/delete times |
| Teacher Timetable | Timetable → Teacher: a teacher's week (a teacher opens on their own), with that week's substitutions |
| Subject Schedule | Timetable → Subject hours: hours/week per subject in the timetable vs the curriculum of the class's grade vs teacher assignments |
| Room Assignment | the class's room (class form) or a time's own room; Timetable → Room shows a room's week |
| Timetable Conflict | Timetable → Conflicts: a teacher in two places, a room used twice, a class twice, a teacher's weekly time off teaching. Saving a time warns about its conflicts (not refused) |
| Schedule Change | Timetable → Schedule changes: a teacher clicks one of their times to ask for another day/time, room or teacher; a manager approves (the time moves) or rejects; the asker can cancel |
| Substitute Schedule | HRM → Teachers → Substitute (already existed); shown on the Teacher timetable for that week (covering / away) |
| Printable Timetable | Print on the Class, Teacher and Room tabs (landscape, only the timetable) |

Data: `class_schedules.book_id / staff_id / classroom_id / note`;
`schedule_change_requests`. Permissions: `timetable.view`,
`timetable.manage`, `timetable.request` (admin all; teacher view + request;
staff view; backfilled by 2026_10_21_130000).
