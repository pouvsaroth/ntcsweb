# HRM > Staff > profile tabs

The list-style tabs of the staff form (HRM > Staff > a staff member). Each
tab holds as many rows as needed for one staff member. They all share one
engine, so a new tab is mostly configuration. (Education has its own older
tab; see `staff-education.md`.)

Built 2026-10-30, in steps:

1. Emergency Contacts, Family Members
2. Languages, Job experience (was the "Previous Work" placeholder), Other Skills
3. Documents (file uploads)

## Emergency Contacts

| Field | Notes |
|---|---|
| Name | Required |
| Relationship | Father, Mother, Spouse, Brother / sister, Child, Relative, Friend, Other (required) |
| Phone | Required |
| Other phone, Address, Note | Optional |

## Family Members

| Field | Notes |
|---|---|
| Name | Required |
| Relationship | Spouse, Child, Father, Mother, Brother / sister, Other (required) |
| Gender | Male, Female, Other (optional) |
| Date of birth | Optional, shown dd-mm-yyyy, cannot be in the future |
| Occupation, Phone, Note | Optional |

## Languages

| Field | Notes |
|---|---|
| Language | Required |
| Level | Basic, Intermediate, Advanced, Fluent, Native (required) |
| Certificate / score | Optional, e.g. IELTS 7.0 |
| Note | Optional |

## Job experience

Jobs before this school, latest first (by start date; rows with no start
date last).

| Field | Notes |
|---|---|
| Company / organisation, Position | Required |
| Start date | Optional, shown dd-mm-yyyy, cannot be in the future |
| End date | Optional; empty means still working there. Cannot be before the start date. |
| Responsibilities, Reason for leaving | Optional |

## Other Skills

| Field | Notes |
|---|---|
| Skill | Required, e.g. first aid, driving |
| Level | Basic, Intermediate, Advanced, Expert (optional) |
| Note | Optional |

## Documents

A staff member's personal / HR files. Separate from HRM > Teachers >
Documents, which holds teaching documents (the user's choice, 2026-10-30).

| Field | Notes |
|---|---|
| File | Required on upload: PDF, image, Word or Excel, up to 10 MB. On edit, choose a file only to replace it (the old file is deleted). |
| Type | ID card, Passport, Birth certificate, Family book, CV, Contract, Certificate, Work permit, Medical record, Other (required) |
| Title | Required; a new upload's file name fills it in |
| Document number | Optional |
| Issue date | Optional, cannot be in the future |
| Expiry date | Optional, cannot be before the issue date; shown in red as Expired once past |
| Note | Optional |

Files are private: stored on the `local` disk under
`tenants/{school}/staff-documents/{staff}/` and sent only through the
download route, to someone with `staff.view`. Deleting a document deletes
its file.

Each tab is a table on desktop and cards on phone, with add and edit in a form.

## Who can do what

- See the tabs: `staff.view`.
- Add, edit and delete rows: `staff.update`.
- A new staff member must be saved first; the tab says so.

## Built from

- Tables `staff_emergency_contacts`, `staff_family_members` (tenant
  migration `2026_10_30_070000`) and `staff_languages`,
  `staff_job_experiences`, `staff_other_skills` (`2026_10_30_080000`), all
  secured with `SchoolTables::secure()`; models `StaffEmergencyContact`,
  `StaffFamilyMember`, `StaffLanguage`, `StaffJobExperience`, `StaffOtherSkill`.
- Documents: table `staff_documents` (`2026_10_30_090000`), model
  `StaffDocument`, API `api/v1/staff/{staff}/documents` (update is a POST
  to `.../documents/{id}` so a new file can come with it; download is
  `.../documents/{id}/file`), `StaffDocumentController`; frontend
  `components/admin/StaffDocumentsTab.vue`, `services/staffDocuments.ts`,
  text under `admin.staff.documents`.
- `App\Support\Staff\StaffRecordKinds`: the list of kinds (slug → model,
  rules, order). Adding a kind there adds its API.
- API `api/v1/staff/{staff}/records/{kind}[/{record}]`
  (`StaffRecordController`); an unknown kind is a 404.
- Frontend `components/admin/StaffRecordsTab.vue` draws any kind from its
  config in `utils/staffRecordKinds.ts` (must match the backend rules);
  service `services/staffRecords.ts`. Text under `admin.staff.records`.
- Tests: `tests/Feature/Hrm/StaffRecordsTest.php`, `StaffDocumentsTest.php`.
- Modules Excel: B6.1 Staff tabs 1–8 on the System and Public School Menu
  sheets.
