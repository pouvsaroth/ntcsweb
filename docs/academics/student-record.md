# Students: record, status, class assignment, search

No new sidebar menu: everything is in the existing **Students** menu.

| Request | Where | Status before |
|---|---|---|
| Student Profile (ID, name, birth date, gender) | Students → Add / Edit | already existed |
| Guardian Information | Student form → Guardians | already existed |
| Enrollment by academic year | Enrollments list → academic year filter (the class's year) | extended |
| Class Assignment | Class → Students → **Add students** (no course package, no invoice) | new (package enrollment unchanged) |
| Student Status | Student record → **Change status**: Studying, Transferred out (new), Dropped out (withdrawn), Graduated — with date, reason, new school; optional: end the open classes | extended |
| Transfer History | Student record: class transfers (EnrollmentTransferHistory) + school transfers (status history) | merged view |
| Student Academic History | Student record: per academic year and class — subject scores (latest per subject), average, attendance %, days absent, promotion result | new |
| Student Search | Students list: name / ID search + status, academic year and class filters | extended |

**Student record** = `/admin/students/{id}` (click a student's name on
Students or Enrollments). API: `GET students/{id}/record`,
`POST students/{id}/status` (students.update),
`POST classes/{id}/assign-students` (enrollments.create).

Table: `student_status_histories` (from/to status, date, reason, to_school).
`Student::STATUS_TRANSFERRED` = `transferred`, also in Base Data
STUDENT_STATUS ("Transferred out").
