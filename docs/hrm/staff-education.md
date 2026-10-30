# HRM > Staff > Education

The **Education** tab on the staff form (HRM > Staff > a staff member). It
lists the schools and universities the staff member studied at. Each staff
member can have as many rows as needed.

Built 2026-10-30. Teacher > Qualification stays as it is (degrees,
certificates, skills and specialised subjects of teachers). The two are
separate.

## A row

| Field | Notes |
|---|---|
| Level | High school, Diploma, Associate, Bachelor's, Master's, Doctorate, Other (required) |
| School / university | Required |
| Major / field of study | Optional |
| Country | Optional |
| Start year, End year | Optional; an empty end year means still studying. The end year cannot be before the start year. |
| Note | Optional |

The list shows rows still being studied first, then by end year (newest
first). It is a table on desktop and cards on phone.

## Who can do what

- See the tab: `staff.view`.
- Add, edit and delete rows: `staff.update`.
- A new staff member must be saved first; the tab says so.

## Built from

- Table `staff_educations` (tenant migration `2026_10_30_060000`, secured
  with `SchoolTables::secure()`); model `App\Models\StaffEducation`.
- API `api/v1/staff/{staff}/educations` (`StaffEducationController`).
- Frontend `components/admin/StaffEducationTab.vue`, used by
  `pages/admin/StaffForm.vue`; service `services/staffEducation.ts`.
- Tests: `tests/Feature/Hrm/StaffEducationTest.php`.

The staff list's name now opens the edit form, the same page as the
pencil icon.
