# Request to change class (students)

A student asks to move to another class from eApprovals → My Request; the
school decides it in Approvals; approving moves the student.

## Owner decisions (2026-10-26)

| Question | Answer |
|---|---|
| Form | Button "Request to change class" (ស្នើសុំប្តូរថ្នាក់) on My Request, students only: current class, **start date (ថ្ងៃចាប់ផ្តើមរៀន)**, then the list of classes, then **Submit (ដាក់ស្នើ)** |
| Which classes | **Every open class** (upcoming or active), except the current one — any course or program |
| When the class changes | **On approval** — the start date is kept on the request and in the history |
| Who approves | An Approval Flow if the school sets one up ("Class change request (student)"), else holders of the new `class-change-requests.approve` / `.reject` (School Admin by default) |
| What changes | **Only the class**, on the same enrollment: no new enrollment; the enrollment date, code, course, fee and the course start date stay. The seat (table) is cleared, since it belonged to the old room. A transfer history row is saved |

## As built

- School migration `2026_10_26_130000_create_class_change_requests_table`:
  `class_change_requests` (student, enrollment, from / to class, start date,
  reason, pending → approved | rejected, who decided, decision reason), and
  `enrollment_transfer_histories.start_date` + `class_change_request_id`.
- Central migration `2026_10_26_130000_grant_class_change_request_permissions`:
  `class-change-requests.view|approve|reject` for School Admin.
- eApprovals document type `class_change` (`ClassChangeRequestPolicy` when no
  flow); notifications `class_change_request_submitted|approved|rejected`.
- `ClassChangeRequestService`: options (the student's active enrollments,
  open classes), submit (one waiting request per enrollment; not into the
  current class; only an open class), withdraw (the student, while waiting),
  approve (`EnrollmentService::transferClass(..., anyProgram: true,
  history: [...])`), reject (reason required).
- API: student `GET my-class-change-requests/options`,
  `GET|POST my-class-change-requests`, `DELETE my-class-change-requests/{id}`;
  approvers `GET class-change-requests`, `POST class-change-requests/{id}/approve|reject`.
- Console: the button and its form (`ClassChangeRequestModal`) on My Request,
  rows and details there and in Approvals (reference `CC-000001`), Flow
  Setting, the Role editor ("Class change requests"), and the enrollment's
  history shows the start date and the request.
- Tests: `tests/Feature/Academic/ClassChangeRequestTest.php` (3).
