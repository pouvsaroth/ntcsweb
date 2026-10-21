# Academic > Lesson plans

One sidebar item, **Academic → Lesson Plans**, with six tabs. Builds on the
Curriculum (School setup: lessons per subject per grade) and on who teaches a
class (Classes, Teacher assignment, Timetable).

| Request | Where |
|---|---|
| Lesson Plan, Learning Objectives, Teaching Materials, Homework / Exercises, Lesson Attachments | one **lesson plan**: class, subject, curriculum lesson, date, title, objectives, materials, activities, homework (+ due date), files (PDF, slides…; private) |
| Weekly Plan | **Weekly plan** tab: a week of plans by day; “+” plans a lesson on a day |
| Daily Teaching Log | **Teaching log** tab: what was taught on a day (taught / partly / not), homework given; can be filled from that day's plan |
| Lesson Review | plans are **sent for review**; a reviewer (head of department…) **approves** or **returns** with a comment (**Review** tab = the waiting list). A teacher can change a plan only while draft or returned |
| Curriculum Progress | **Curriculum progress** tab: per subject of a class, curriculum lessons taught (log “taught”), partly, remaining, next |
| Teaching Delay Report | **Delays** tab: planned lessons whose day has passed with nothing taught yet (for the plan or its curriculum lesson), days late |

Tables: `lesson_plans`, `lesson_plan_files`, `teaching_logs`.

Permissions: `lesson-plans.write` — a teacher writes their own plans and log,
for the classes they teach; `lesson-plans.view` — see (and write for) every
teacher; `lesson-plans.review` — review. School admin: all; teacher: write
(backfilled by 2026_10_21_140000). A teacher's account must be linked to
their Staff record (Staff → user account).
