<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { hideTabSlug } from '@/router/hideTabSlug'
import { rolesService, type Role } from '@/services/roles'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  /** Present when editing; absent when creating a new role. May be one of the 4 built-in system roles — see isSystemRole below. */
  role?: Role | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

type Action =
  | 'view'
  | 'create'
  | 'update'
  | 'delete'
  | 'assign'
  | 'cancel'
  | 'send'
  | 'deactivate'
  | 'approve'
  | 'reject'
  | 'pay'
  | 'export'
  | 'transfer'
  | 'table'
  | 'note'
  | 'responsibleStaff'
  | 'return'
  | 'retire'
  | 'dispose'
  | 'lost'
  | 'found'
  | 'resolve'
  | 'complete'
  | 'status'
  | 'manage'
  | 'reply'
  | 'close'
  | 'translations'
  | 'languages'
  | 'manageAll'
  | 'request'
  | 'enter'
  | 'run'

/**
 * The same section headings as AdminSidebar.vue's own adminNav.ts, so a
 * module here lands under whichever menu group actually links to it — a
 * school admin building a role shouldn't have to guess where "Enrollments"
 * or "Currency rates" belong. "Platform" is deliberately excluded, same as
 * before this became a tree: those permissions only ever matter for
 * platform (super admin) accounts, so offering them here would just be
 * noise for a school admin building a job-title role like "Accountant".
 * "Other" catches the handful of modules with no direct sidebar entry of
 * their own (e.g. Leave requests, folded into the Approvals queue).
 */
const GROUPS = [
  'Dashboard',
  'Project Management',
  'Front Office',
  'Academic',
  'Students',
  'Staff',
  'Billing',
  'Accounting',
  'Loan',
  'Assets',
  'Website',
  'Communication',
  'Settings',
  'E-Approvals',
  'Approval Flow',
  'Other',
] as const;

/**
 * Mirrors App\Support\Authorization\Permissions::catalog() on the backend.
 *
 * A matrix (module rows × action columns), not a flat checkbox list: every
 * module repeats the same four action words, so a flat list reads as
 * "View, Create, Update, Delete, View, Create, Update, Delete, …" with no
 * visual distinction between them at a glance.
 */
type Group = (typeof GROUPS)[number]

interface ModuleEntry {
  name: string
  group: Group
  /**
   * Some sidebar entries are one page with a tab bar switching between
   * several otherwise-unrelated permission modules (see ProgramsTabs.vue,
   * StudyBuildingTabs.vue) — set this to that entry's own name so the tree
   * nests these modules one level deeper, under that shared page, instead
   * of listing them as flat siblings of everything else in the group.
   */
  parent?: string
  /** The menu's own row of a page with tabs: its actions show on the menu line. */
  menu?: boolean
  /** A tab row: the tab's path. Its main box is "Show" (see router/tabVisibility.ts). */
  tab?: string
  /** A tab row: the permissions that open the tab (any one). Show only matters once one is ticked. */
  needs?: string[]
  actions: Partial<Record<Action, string>>
}

/** One node in the permission tree below a top-level group: either a single module, or a "parent" bucket for a page whose own tab bar switches between several modules (see ModuleEntry.parent). */
type GroupChild =
  | { kind: 'module'; key: string; module: ModuleEntry }
  | { kind: 'parent'; key: string; name: string; menuRow?: ModuleEntry; modules: ModuleEntry[] }

/**
 * Listed in sidebar order: main menu (group) > menu > tabs. A menu whose page
 * has a tab bar is a `parent`: its `menu` row's actions sit on the menu line
 * (e.g. Payroll: View, Manage), and every tab has its own row with a Show box
 * (`tab`) plus any action only that tab has. A permission used in two places
 * shows in both, as one shared checkbox.
 */
const tab = (group: Group, parent: string, name: string, to: string, needs: string[], actions: ModuleEntry['actions'] = {}): ModuleEntry => ({
  name,
  group,
  parent,
  tab: to,
  needs,
  actions,
})
const crud = (slug: string) => ({ view: `${slug}.view`, create: `${slug}.create`, update: `${slug}.update`, delete: `${slug}.delete` })
/** Add / Update / Delete of a tab whose View is the menu's. */
const tabCud = (slug: string) => ({ create: `${slug}.create`, update: `${slug}.update`, delete: `${slug}.delete` })

const MODULES: ModuleEntry[] = [
  // ── Dashboard ──
  { name: 'Dashboard', group: 'Dashboard', actions: { view: 'dashboard.view' } },
  // Every dashboard card has its own permission, independent of the page it links to
  // (e.g. Register student card ≠ students.create) — see Dashboard.vue.
  { name: 'Stat cards: attendance', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.attendance.view' } },
  { name: 'Stat cards: financial & enrollment', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.finance.view' } },
  { name: 'Monthly payment alerts', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.payment-alerts.view' } },
  { name: 'Register student card', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.cards.register-student' } },
  { name: 'Registration pending card', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.cards.registration-pending' } },
  { name: 'Enrollment card', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.cards.enrollment' } },
  { name: 'Classes card', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.cards.classes' } },
  { name: 'Student payment card', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.cards.student-payment' } },
  { name: 'Invoices card', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.cards.invoices' } },
  { name: 'Expense card', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.cards.expense' } },
  { name: 'Borrowings card (total owed)', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.cards.borrowings' } },
  { name: 'Student attendance card', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.cards.student-attendance' } },
  { name: 'Teacher attendance card', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.cards.teacher-attendance' } },
  { name: 'Users card', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.cards.users' } },
  { name: 'Roles card', group: 'Dashboard', parent: 'Dashboard cards', actions: { view: 'dashboard.cards.roles' } },

  // ── Project Management ──
  { name: 'Projects', group: 'Project Management', actions: crud('projects') },

  // ── Front Office ──
  tab('Front Office', 'Front Office', 'Inquiry', '/admin/front-office/inquiries', ['inquiries.view'], crud('inquiries')),
  tab('Front Office', 'Front Office', 'Meeting arrangement', '/admin/front-office/meetings', ['meetings.view'], crud('meetings')),
  tab('Front Office', 'Front Office', 'Appointment', '/admin/front-office/appointments', ['appointments.view'], crud('appointments')),
  tab('Front Office', 'Front Office', 'Visitor', '/admin/front-office/visitors', ['visitors.view'], crud('visitors')),

  // ── Academic ──
  // Not the website's "Programs" (Website group) — this is the internal program catalog.
  tab('Academic', 'Programs', 'Academic Programs', '/admin/academic-programs', ['academic-programs.view'], crud('academic-programs')),
  tab('Academic', 'Programs', 'Academic Years', '/admin/academic-years', ['academic-years.view'], crud('academic-years')),
  tab('Academic', 'Programs', 'Course', '/admin/course-packages', ['course-packages.view'], crud('course-packages')),
  tab('Academic', 'Programs', 'Books', '/admin/books', ['books.view'], crud('books')),
  tab('Academic', 'Programs', 'Book Categories', '/admin/book-categories', ['book-categories.view'], crud('book-categories')),
  tab('Academic', 'School Setup', 'Terms / Semesters', '/admin/school-setup/terms', ['academic-terms.view'], crud('academic-terms')),
  tab('Academic', 'School Setup', 'Grade levels', '/admin/school-setup/grade-levels', ['grade-levels.view'], crud('grade-levels')),
  tab('Academic', 'School Setup', 'Curriculum', '/admin/school-setup/curriculum', ['curriculum.view'], crud('curriculum')),
  tab('Academic', 'School Setup', 'School calendar', '/admin/school-setup/calendar', ['school-calendar.view'], crud('school-calendar')),
  tab('Academic', 'School Setup', 'Promotion rules', '/admin/school-setup/promotion-rules', ['promotion-rules.view'], crud('promotion-rules')),
  tab('Academic', 'School Setup', 'Promote students', '/admin/school-setup/promote', ['student-promotions.view'], { view: 'student-promotions.view', run: 'student-promotions.run' }),
  { name: 'Videos', group: 'Academic', actions: crud('videos') },
  tab('Academic', 'Study Building', 'Buildings', '/admin/buildings', ['buildings.view'], crud('buildings')),
  tab('Academic', 'Study Building', 'Classrooms', '/admin/classrooms', ['classrooms.view'], crud('classrooms')),
  { name: 'Classes', group: 'Academic', actions: crud('classes') },
  // Add / Update / Delete = the times on the Class tab.
  { name: 'Timetable', group: 'Academic', parent: 'Timetable', menu: true, actions: crud('timetable') },
  tab('Academic', 'Timetable', 'Class', '/admin/timetable/class', ['timetable.view']),
  tab('Academic', 'Timetable', 'Teacher', '/admin/timetable/teacher', ['timetable.view']),
  tab('Academic', 'Timetable', 'Room', '/admin/timetable/room', ['timetable.view']),
  tab('Academic', 'Timetable', 'Subject hours', '/admin/timetable/subjects', ['timetable.view']),
  tab('Academic', 'Timetable', 'Conflicts', '/admin/timetable/conflicts', ['timetable.view']),
  // Approve / Reject = decide everyone's requests (and see them all).
  tab('Academic', 'Timetable', 'Schedule changes', '/admin/timetable/changes', ['timetable.request', 'timetable.approve', 'timetable.reject'], { request: 'timetable.request', approve: 'timetable.approve', reject: 'timetable.reject' }),
  // Create = write lesson plans and the teaching log.
  { name: 'Lesson Plans', group: 'Academic', parent: 'Lesson Plans', menu: true, actions: { view: 'lesson-plans.view', create: 'lesson-plans.write' } },
  tab('Academic', 'Lesson Plans', 'Lesson plans', '/admin/lessons/plans', ['lesson-plans.view', 'lesson-plans.write', 'lesson-plans.review']),
  tab('Academic', 'Lesson Plans', 'Weekly plan', '/admin/lessons/week', ['lesson-plans.view', 'lesson-plans.write', 'lesson-plans.review']),
  tab('Academic', 'Lesson Plans', 'Teaching log', '/admin/lessons/log', ['lesson-plans.view', 'lesson-plans.write']),
  tab('Academic', 'Lesson Plans', 'Review', '/admin/lessons/review', ['lesson-plans.review'], { approve: 'lesson-plans.review' }),
  tab('Academic', 'Lesson Plans', 'Curriculum progress', '/admin/lessons/progress', ['lesson-plans.view', 'lesson-plans.write']),
  tab('Academic', 'Lesson Plans', 'Delays', '/admin/lessons/delays', ['lesson-plans.view', 'lesson-plans.write']),
  { name: 'Attendance', group: 'Academic', parent: 'Attendance', menu: true, actions: { view: 'attendance.view', create: 'attendance.create', update: 'attendance.update' } },
  tab('Academic', 'Attendance', 'Enrollments', '/admin/attendance', ['attendance.view']),
  tab('Academic', 'Attendance', 'Attendance Summary', '/admin/attendance/summary', ['attendance.view']),
  tab('Academic', 'Attendance', 'Attendance Sheet', '/admin/attendance/sheet', ['attendance.view']),
  { name: 'Examination', group: 'Academic', parent: 'Examination', menu: true, actions: { view: 'examination.view' } },
  tab('Academic', 'Examination', 'Assessment Setup', '/admin/examination/setup', ['assessment.view'], crud('assessment')),
  tab('Academic', 'Examination', 'Exam Schedule', '/admin/examination/schedule', ['exam-schedules.view'], crud('exam-schedules')),
  // The nine grade tabs below all follow these: View/Enter = own classes and subjects; Manage all = every class.
  { name: 'Gradebook (the grade tabs below)', group: 'Academic', parent: 'Examination', actions: { view: 'gradebook.view', enter: 'gradebook.enter', manageAll: 'gradebook.manage-all', approve: 'gradebook.approve' } },
  ...(
    [
      ['Grade Entry', 'entry'],
      ['Grade Validation', 'validation'],
      ['Score Calculation', 'calculation'],
      ['Result Approval', 'approval'],
      ['Grade Correction', 'corrections'],
      ['Academic Transcript', 'transcript'],
      ['Student Progress', 'progress'],
      ['Remedial Teaching', 'remedial'],
      ['Result Export', 'export'],
    ] as const
  ).map(([name, path]) => tab('Academic', 'Examination', name, `/admin/examination/${path}`, ['gradebook.view', 'gradebook.enter', 'gradebook.manage-all', 'gradebook.approve'])),
  tab('Academic', 'Examination', 'Exams', '/admin/exams', ['exam-applications.view'], {
    view: 'exam-applications.view',
    create: 'exam-applications.create',
    update: 'exam-applications.update',
    delete: 'exam-applications.delete',
    approve: 'exam-applications.approve',
    reject: 'exam-applications.reject',
  }),
  tab('Academic', 'Examination', 'Grades', '/admin/grades', ['exam-scores.view', 'exam-scores.manage-all'], { view: 'exam-scores.view', update: 'exam-scores.update', manageAll: 'exam-scores.manage-all' }),
  // These two open with Exams: View.
  tab('Academic', 'Examination', 'Make-up Exam', '/admin/exams/make-up', ['exam-applications.view']),
  tab('Academic', 'Examination', 'Certificate', '/admin/exams/certificate', ['exam-applications.view']),
  { name: 'Academic reports', group: 'Academic', actions: { view: 'academic-reports.view', export: 'academic-reports.export' } },

  // ── Students ──
  // Create also opens Student imports; Approve = approve online registrations.
  { name: 'Students (and Student imports)', group: 'Students', actions: { ...crud('students'), approve: 'students.approve-registration' } },
  {
    name: 'Enrollments',
    group: 'Students',
    actions: {
      ...crud('enrollments'),
      cancel: 'enrollments.cancel',
      transfer: 'enrollments.transfer',
      table: 'enrollments.change-table',
      status: 'enrollments.change-status',
      note: 'enrollments.note',
      responsibleStaff: 'enrollments.responsible-staff',
    },
  },

  // ── HRM ──
  { name: 'Staff', group: 'Staff', actions: { ...crud('staff'), status: 'staff.change-status' } },
  { name: 'Own staff profile only (see and edit just their own)', group: 'Staff', actions: { update: 'staff.update-own' } },
  { name: 'Teachers', group: 'Staff', parent: 'Teachers', menu: true, actions: { view: 'teachers.view' } },
  tab('Staff', 'Teachers', 'Qualification', '/admin/teachers/qualifications', ['teachers.view'], tabCud('teacher-qualifications')),
  tab('Staff', 'Teachers', 'Assignment', '/admin/teachers/assignments', ['teachers.view'], tabCud('teacher-assignments')),
  tab('Staff', 'Teachers', 'Homeroom', '/admin/teachers/homeroom', ['teachers.view'], { update: 'teacher-homeroom.update' }),
  tab('Staff', 'Teachers', 'Teaching load', '/admin/teachers/load', ['teachers.view']),
  tab('Staff', 'Teachers', 'Availability', '/admin/teachers/availability', ['teachers.view'], tabCud('teacher-availability')),
  tab('Staff', 'Teachers', 'Substitute', '/admin/teachers/substitutes', ['teachers.view'], { create: 'teacher-substitutes.create', delete: 'teacher-substitutes.delete' }),
  tab('Staff', 'Teachers', 'Documents', '/admin/teachers/documents', ['teachers.view'], { create: 'teacher-documents.create', delete: 'teacher-documents.delete' }),
  { name: 'Staff status history', group: 'Staff', actions: { view: 'staff.view' } },
  // Branch … Hierarchy tabs follow these.
  { name: 'Organization Management', group: 'Staff', parent: 'Organization Management', menu: true, actions: { view: 'organization.view' } },
  tab('Staff', 'Organization Management', 'School', '/admin/organization/school', ['tenant-settings.view'], { view: 'tenant-settings.view', update: 'tenant-settings.update' }),
  tab('Staff', 'Organization Management', 'Branch', '/admin/organization/branches', ['organization.view'], tabCud('branches')),
  tab('Staff', 'Organization Management', 'Department', '/admin/organization/departments', ['organization.view', 'departments.view'], crud('departments')),
  tab('Staff', 'Organization Management', 'Team', '/admin/organization/teams', ['organization.view'], tabCud('teams')),
  tab('Staff', 'Organization Management', 'Position', '/admin/organization/positions', ['positions.view'], crud('positions')),
  tab('Staff', 'Organization Management', 'Job grade', '/admin/organization/job-grades', ['organization.view'], tabCud('job-grades')),
  tab('Staff', 'Organization Management', 'Job level', '/admin/organization/job-levels', ['organization.view'], tabCud('job-levels')),
  tab('Staff', 'Organization Management', 'Reporting manager', '/admin/organization/reporting', ['organization.view']),
  tab('Staff', 'Organization Management', 'Organization hierarchy', '/admin/organization/hierarchy', ['organization.view']),
  { name: 'Recruitment', group: 'Staff', parent: 'Recruitment', menu: true, actions: { view: 'recruitment.view' } },
  tab('Staff', 'Recruitment', 'Manpower request', '/admin/recruitment/manpower-requests', ['recruitment.view'], { ...tabCud('manpower-requests'), approve: 'manpower-requests.approve', reject: 'manpower-requests.reject' }),
  tab('Staff', 'Recruitment', 'Job positions', '/admin/recruitment/job-positions', ['recruitment.view'], tabCud('job-positions')),
  tab('Staff', 'Recruitment', 'Job postings', '/admin/recruitment/job-postings', ['recruitment.view'], tabCud('job-postings')),
  tab('Staff', 'Recruitment', 'Applicant management', '/admin/recruitment/applicants', ['recruitment.view'], tabCud('applicants')),
  tab('Staff', 'Recruitment', 'CV/resume', '/admin/recruitment/cvs', ['recruitment.view'], { create: 'applicant-documents.create', delete: 'applicant-documents.delete' }),
  tab('Staff', 'Recruitment', 'Interview', '/admin/recruitment/interviews', ['recruitment.view'], tabCud('interviews')),
  // Interviewers always add / delete their own evaluations; these are anyone's.
  tab('Staff', 'Recruitment', 'Interview evaluation', '/admin/recruitment/evaluations', ['recruitment.view'], { create: 'interview-evaluations.create', delete: 'interview-evaluations.delete' }),
  // Make offer = Offer letter's Create; Reject = Applicant management's Update.
  tab('Staff', 'Recruitment', 'Candidate selection', '/admin/recruitment/selection', ['recruitment.view'], { create: 'offer-letters.create', update: 'applicants.update' }),
  tab('Staff', 'Recruitment', 'Offer letter', '/admin/recruitment/offers', ['recruitment.view'], tabCud('offer-letters')),
  // Moving a card = Applicant management's Update.
  tab('Staff', 'Recruitment', 'Recruitment pipeline', '/admin/recruitment/pipeline', ['recruitment.view'], { update: 'applicants.update' }),
  { name: 'Time & Attendance', group: 'Staff', parent: 'Time & Attendance', menu: true, actions: { view: 'staff-attendance.view' } },
  // Editing a day on Employee attendance is Check-in / check-out's Add / Update.
  tab('Staff', 'Time & Attendance', 'Employee attendance', '/admin/time-attendance/employees', ['staff-attendance.view'], { create: 'check-ins.create', update: 'check-ins.update' }),
  tab('Staff', 'Time & Attendance', 'Check-in / check-out', '/admin/time-attendance/check-ins', ['staff-attendance.view'], tabCud('check-ins')),
  tab('Staff', 'Time & Attendance', 'Attendance records', '/admin/time-attendance/records', ['staff-attendance.view']),
  tab('Staff', 'Time & Attendance', 'Shift', '/admin/time-attendance/shifts', ['staff-attendance.view'], tabCud('shifts')),
  tab('Staff', 'Time & Attendance', 'Staff shift', '/admin/time-attendance/work-schedules', ['staff-attendance.view'], tabCud('staff-shifts')),
  tab('Staff', 'Time & Attendance', 'Holidays', '/admin/time-attendance/holidays', ['staff-attendance.view'], tabCud('holidays')),
  // Approve = sign off (lock) a month.
  tab('Staff', 'Time & Attendance', 'Attendance approval', '/admin/time-attendance/approval', ['staff-attendance.view'], { approve: 'staff-attendance.approve' }),
  tab('Staff', 'Time & Attendance', 'Late', '/admin/time-attendance/records/late', ['staff-attendance.view']),
  tab('Staff', 'Time & Attendance', 'Early leave', '/admin/time-attendance/records/early-leave', ['staff-attendance.view']),
  tab('Staff', 'Time & Attendance', 'Absence', '/admin/time-attendance/records/absence', ['staff-attendance.view']),
  tab('Staff', 'Time & Attendance', 'Overtime', '/admin/time-attendance/records/overtime', ['staff-attendance.view'], { create: 'overtime-requests.create', delete: 'overtime-requests.delete', approve: 'overtime-requests.approve', reject: 'overtime-requests.reject' }),
  tab('Staff', 'Time & Attendance', 'Attendance correction', '/admin/time-attendance/records/corrections', ['staff-attendance.view'], { create: 'attendance-corrections.create', delete: 'attendance-corrections.delete', approve: 'attendance-corrections.approve', reject: 'attendance-corrections.reject' }),
  { name: 'Leave Management', group: 'Staff', parent: 'Leave Management', menu: true, actions: { view: 'leave-management.view' } },
  tab('Staff', 'Leave Management', 'Leave types', '/admin/leave-management/types', ['leave-management.view'], tabCud('leave-types')),
  tab('Staff', 'Leave Management', 'Leave policies', '/admin/leave-management/policies', ['leave-management.view'], tabCud('leave-policies')),
  tab('Staff', 'Leave Management', 'Leave balance', '/admin/leave-management/balances', ['leave-management.view'], { create: 'leave-balances.create', delete: 'leave-balances.delete' }),
  tab('Staff', 'Leave Management', 'Leave request', '/admin/leave-management/requests', ['leave-requests.view'], { view: 'leave-requests.view', create: 'leave-requests.create', approve: 'leave-requests.approve', reject: 'leave-requests.reject' }),
  tab('Staff', 'Leave Management', 'Approval workflow', '/admin/leave-management/workflow', ['leave-management.view']),
  tab('Staff', 'Leave Management', 'Leave calendar', '/admin/leave-management/calendar', ['leave-management.view']),
  tab('Staff', 'Leave Management', 'Carry forward', '/admin/leave-management/carry-forward', ['leave-management.view'], { run: 'leave-carry-forward.run' }),
  tab('Staff', 'Leave Management', 'Leave reports', '/admin/leave-management/reports', ['leave-management.view']),
  // View opens every Payroll tab; each set-up tab has its own Add / Update / Delete (payroll-<tab>.*).
  { name: 'Payroll', group: 'Staff', parent: 'Payroll', menu: true, actions: { view: 'payroll.view' } },
  ...(
    [
      ['Salary structure', 'salary-structures'],
      ['Basic salary', 'basic-salary'],
      ['Allowances', 'allowances'],
      ['Bonuses', 'bonuses'],
      ['Deductions', 'deductions'],
    ] as const
  ).map(([name, path]) => tab('Staff', 'Payroll', name, `/admin/payroll/${path}`, ['payroll.view'], tabCud(`payroll-${path}`))),
  // Rules held in one setting: Update only.
  tab('Staff', 'Payroll', 'Overtime', '/admin/payroll/overtime', ['payroll.view'], { update: 'payroll-overtime.update' }),
  tab('Staff', 'Payroll', 'Attendance deduction', '/admin/payroll/attendance-deduction', ['payroll.view'], { update: 'payroll-attendance-deduction.update' }),
  // Update also covers each staff member's tax profile.
  tab('Staff', 'Payroll', 'Tax', '/admin/payroll/tax', ['payroll.view'], { update: 'payroll-tax.update' }),
  // Update also covers each staff member's NSSF profile.
  tab('Staff', 'Payroll', 'Social security', '/admin/payroll/social-security', ['payroll.view'], tabCud('payroll-social-security')),
  // Update also covers cancelling a loan and cash repayments.
  tab('Staff', 'Payroll', 'Loan/advance', '/admin/payroll/loans', ['payroll.view'], tabCud('payroll-loans')),
  // Work out = start a payroll, send it for approval and pay it.
  tab('Staff', 'Payroll', 'Payroll calculation', '/admin/payroll/calculation', ['payroll.view'], { run: 'payroll.run' }),
  tab('Staff', 'Payroll', 'Payslip', '/admin/payroll/payslips', ['payroll.view']),
  tab('Staff', 'Payroll', 'Payroll approval', '/admin/payroll/approval', ['payroll.approve'], { approve: 'payroll.approve' }),
  tab('Staff', 'Payroll', 'Payroll history', '/admin/payroll/history', ['payroll.view']),
  // Approve = apply promotion recommendations.
  { name: 'Performance', group: 'Staff', parent: 'Performance', menu: true, actions: { view: 'performance.view' } },
  tab('Staff', 'Performance', 'KPI', '/admin/performance/kpis', ['performance.view'], tabCud('performance-kpis')),
  tab('Staff', 'Performance', 'Goals', '/admin/performance/goals', ['performance.view'], tabCud('performance-goals')),
  tab('Staff', 'Performance', 'Performance review', '/admin/performance/reviews', ['performance.view'], tabCud('performance-reviews')),
  tab('Staff', 'Performance', 'Evaluation forms', '/admin/performance/forms', ['performance.view'], tabCud('performance-forms')),
  // Self-service: every staff account (the My requests permission).
  tab('Staff', 'Performance', 'Employee self-assessment', '/admin/performance/self-assessment', ['my-requests.view']),
  tab('Staff', 'Performance', 'Manager assessment', '/admin/performance/manager-assessment', ['my-requests.view']),
  tab('Staff', 'Performance', 'Performance score', '/admin/performance/scores', ['performance.view']),
  tab('Staff', 'Performance', 'Promotion recommendation', '/admin/performance/promotions', ['performance.view'], { create: 'performance-promotions.create', update: 'performance-promotions.update', approve: 'performance.approve-promotion' }),
  { name: 'Professional Development', group: 'Staff', parent: 'Professional Development', menu: true, actions: { view: 'professional-development.view' } },
  tab('Staff', 'Professional Development', 'Training records', '/admin/professional-development/records', ['professional-development.view'], tabCud('trainings')),
  tab('Staff', 'Professional Development', 'Training calendar', '/admin/professional-development/calendar', ['professional-development.view'], tabCud('trainings')),
  tab('Staff', 'Professional Development', 'Certificates', '/admin/professional-development/certificates', ['professional-development.view'], { create: 'training-certificates.create', delete: 'training-certificates.delete' }),
  // These three are also self-service for every staff account.
  tab('Staff', 'Professional Development', 'Classroom observation', '/admin/professional-development/observations', ['professional-development.view', 'my-requests.view'], tabCud('classroom-observations')),
  tab('Staff', 'Professional Development', 'Coaching & mentoring', '/admin/professional-development/coaching', ['professional-development.view', 'my-requests.view'], tabCud('coaching')),
  tab('Staff', 'Professional Development', 'Development plan', '/admin/professional-development/plans', ['professional-development.view', 'my-requests.view'], tabCud('development-plans')),
  tab('Staff', 'Professional Development', 'Training reports', '/admin/professional-development/reports', ['professional-development.view']),
  // The same self-service slug as E-Approvals > My requests: one shared checkbox.
  { name: 'My check-in, Request Leave, Resignation Form', group: 'Staff', actions: { view: 'my-requests.view' } },
  { name: 'Resignation requests', group: 'Staff', actions: { view: 'resignation-requests.view', approve: 'resignation-requests.approve', reject: 'resignation-requests.reject' } },

  // ── Billing ──
  { name: 'Billing dashboard & reports', group: 'Billing', actions: { view: 'billing-reports.view' } },
  { name: 'Products', group: 'Billing', actions: crud('products') },
  { name: 'Invoices', group: 'Billing', parent: 'Invoices', menu: true, actions: { view: 'invoices.view', create: 'invoices.create', update: 'invoices.update', cancel: 'invoices.cancel' } },
  tab('Billing', 'Invoices', 'Invoices', '/admin/invoices', ['invoices.view']),
  tab('Billing', 'Invoices', 'Monthly Invoice', '/admin/invoices/monthly', ['invoices.view']),
  tab('Billing', 'Invoices', 'Discount codes', '/admin/invoices/discount-codes', ['discount-codes.view'], crud('discount-codes')),
  { name: 'Payments', group: 'Billing', actions: { view: 'payments.view', create: 'payments.create', update: 'payments.update', cancel: 'payments.cancel' } },
  { name: 'Receipts', group: 'Billing', actions: { view: 'receipts.view' } },
  { name: 'Billing notifications', group: 'Billing', actions: { send: 'notifications.send' } },
  { name: 'Companies', group: 'Billing', actions: crud('companies') },
  { name: 'Currency rates', group: 'Billing', actions: crud('currency-rates') },

  // ── Accounting ──
  { name: 'Accounting (module access)', group: 'Accounting', actions: { view: 'accounting.view' } },
  { name: 'Accounting dashboard', group: 'Accounting', actions: { view: 'accounting.dashboard.view' } },
  { name: 'Accounts', group: 'Accounting', actions: { view: 'accounts.view', create: 'accounts.create', update: 'accounts.update', deactivate: 'accounts.deactivate' } },
  { name: 'Income', group: 'Accounting', actions: { view: 'income.view', create: 'income.create', update: 'income.update', cancel: 'income.cancel' } },
  {
    name: 'Expenses',
    group: 'Accounting',
    actions: {
      view: 'expense.view',
      create: 'expense.create',
      update: 'expense.update',
      approve: 'expense.approve',
      reject: 'expense.reject',
      pay: 'expense.pay',
      cancel: 'expense.cancel',
    },
  },
  { name: 'Expense for', group: 'Accounting', actions: crud('expense-for') },
  // One list: Accounting > Vendors and Assets > Suppliers.
  { name: 'Vendors / Suppliers', group: 'Accounting', actions: crud('suppliers') },
  { name: 'Customers', group: 'Loan', actions: crud('loan-customers') },
  // Add = open accounts and record deposits / withdrawals; Update = edit or close; Delete = remove entries.
  { name: 'Savings', group: 'Loan', actions: crud('savings') },
  { name: 'Borrowings', group: 'Loan', parent: 'Borrowings', menu: true, actions: { view: 'borrowings.view' } },
  tab('Loan', 'Borrowings', 'Loans', '/admin/borrowings/loans', ['borrowings.view'], tabCud('borrowing-loans')),
  tab('Loan', 'Borrowings', 'Repayments', '/admin/borrowings/repayments', ['borrowings.view'], { create: 'borrowing-repayments.create', delete: 'borrowing-repayments.delete' }),
  tab('Loan', 'Borrowings', 'Lenders', '/admin/borrowings/lenders', ['borrowings.view'], tabCud('lenders')),
  tab('Loan', 'Borrowings', 'Reports', '/admin/borrowings/reports', ['borrowings.view']),
  { name: 'Transactions', group: 'Accounting', actions: { view: 'transactions.view', create: 'transactions.create' } },
  { name: 'Accounting reports', group: 'Accounting', actions: { view: 'reports.financial.view', export: 'reports.financial.export' } },
  { name: 'Accounting periods', group: 'Accounting', actions: { close: 'accounting.period.close', create: 'accounting.adjustment.create' } },

  // ── Assets ──
  { name: 'Asset dashboard & Asset reports', group: 'Assets', actions: { view: 'assets.reports.view', export: 'assets.reports.export' } },
  {
    name: 'Assets',
    group: 'Assets',
    actions: {
      ...crud('assets'),
      assign: 'assets.assign',
      return: 'assets.return',
      transfer: 'assets.transfer',
      retire: 'assets.retire',
      dispose: 'assets.dispose',
      lost: 'assets.mark_lost',
      found: 'assets.mark_found',
    },
  },
  { name: 'My assets', group: 'Assets', actions: { view: 'my-assets.view' } },
  { name: 'Asset issues', group: 'Assets', actions: { view: 'assets.issue.view', create: 'assets.issue.create', update: 'assets.issue.update', resolve: 'assets.issue.resolve' } },
  { name: 'Asset repairs', group: 'Assets', actions: { view: 'assets.repair.view', create: 'assets.repair.create', update: 'assets.repair.update', complete: 'assets.repair.complete' } },
  { name: 'Repair shops', group: 'Assets', actions: crud('repair-shops') },
  { name: 'Departments (same list as Organization > Department)', group: 'Assets', actions: crud('departments') },
  { name: 'Asset categories', group: 'Assets', actions: crud('asset-categories') },
  { name: 'Asset locations', group: 'Assets', actions: crud('asset-locations') },
  { name: 'Suppliers (same list as Vendors)', group: 'Assets', actions: crud('suppliers') },
  { name: 'Asset maintenance', group: 'Assets', actions: { view: 'assets.maintenance.view', create: 'assets.maintenance.create', update: 'assets.maintenance.update' } },

  // ── Website ──
  { name: 'Programs (website page)', group: 'Website', actions: crud('programs') },

  // ── Communication ──
  { name: 'Contact messages', group: 'Communication', actions: { view: 'contact-messages.view' } },
  { name: 'Notifications', group: 'Communication', actions: { view: 'notifications.view' } },
  { name: 'Student feedback', group: 'Communication', actions: { view: 'student-feedback.view', reply: 'student-feedback.reply' } },

  // ── Settings ──
  // Sidebar order: School Documents, Settings, Telegram groups (each its own permissions since 2026-11-07).
  { name: 'School Documents', group: 'Settings', actions: { view: 'school-documents.view', update: 'school-documents.update' } },
  { name: 'Settings (also Organization > School)', group: 'Settings', actions: { view: 'tenant-settings.view', update: 'tenant-settings.update' } },
  // Update also covers linking, testing and unlinking a group.
  { name: 'Telegram groups', group: 'Settings', actions: crud('telegram-groups') },
  { name: 'Users', group: 'Settings', actions: crud('users') },
  { name: 'Roles', group: 'Settings', actions: { ...crud('roles'), assign: 'roles.assign' } },
  { name: 'Audit logs', group: 'Settings', actions: { view: 'audit-logs.view', delete: 'audit-logs.delete' } },
  // Settings > Language: its two tabs each have their own permissions.
  { name: 'Language: Language tab', group: 'Settings', actions: crud('languages') },
  { name: 'Language: Translation tab', group: 'Settings', actions: { update: 'base-data.manage-translations' } },
  { name: 'Lookup categories', group: 'Settings', actions: crud('base-data') },
  { name: 'Backup (own school database)', group: 'Settings', actions: { view: 'database-backups.download' } },

  // ── E-Approvals ──
  { name: 'Forms', group: 'E-Approvals', actions: { view: 'forms.view' } },
  { name: 'My requests', group: 'E-Approvals', actions: { view: 'my-requests.view' } },
  // The Approvals queue shows each kind of request its permission allows.
  { name: 'Form requests', group: 'E-Approvals', parent: 'Approvals', actions: { view: 'approval-requests.view', approve: 'approval-requests.approve', reject: 'approval-requests.reject' } },
  { name: 'Make-up class requests', group: 'E-Approvals', parent: 'Approvals', actions: { view: 'make-up-class-requests.view', approve: 'make-up-class-requests.approve', reject: 'make-up-class-requests.reject' } },
  { name: 'Class change requests', group: 'E-Approvals', parent: 'Approvals', actions: { view: 'class-change-requests.view', approve: 'class-change-requests.approve', reject: 'class-change-requests.reject' } },
  { name: 'Form categories', group: 'E-Approvals', actions: crud('form-categories') },
  { name: 'Form templates', group: 'E-Approvals', actions: crud('form-templates') },

  // ── Approval Flow ──
  { name: 'Approval groups', group: 'Approval Flow', actions: crud('approval-groups') },
  // View also lists the groups to pick from.
  { name: 'Flow setting', group: 'Approval Flow', actions: { view: 'approval-flows.view', update: 'approval-flows.update' } },

  // ── Other ──
  { name: 'Help & Support', group: 'Other', parent: 'Help & Support', menu: true, actions: { view: 'help-support.view' } },
  tab('Other', 'Help & Support', 'Help articles', '/admin/help/articles', ['help-support.view']),
  tab('Other', 'Help & Support', 'FAQ', '/admin/help/faq', ['help-support.view']),
  // Add = open a new support ticket.
  tab('Other', 'Help & Support', 'Support tickets', '/admin/help/tickets', ['help-support.view'], { create: 'help-tickets.create' }),
  tab('Other', 'Help & Support', 'Contact NewTech', '/admin/help/contact', ['help-support.view']),
]

const COLUMNS: Action[] = [
  'view', 'create', 'update', 'delete', 'approve', 'reject', 'pay', 'cancel', 'deactivate', 'export', 'send', 'assign',
  'return', 'transfer', 'table', 'note', 'responsibleStaff', 'retire', 'dispose', 'lost', 'found', 'resolve', 'complete',
  'status', 'manage', 'reply', 'close', 'translations', 'languages', 'manageAll', 'request', 'enter', 'run',
]

const isEditing = computed(() => props.role != null)

/**
 * A system role's name/slug/level are locked server-side (see
 * UpdateRoleRequest — it simply drops those fields for a system role), so
 * disabling them here isn't just cosmetic: it keeps the form honest about
 * what a submit will actually change. Permissions/description stay editable
 * — that's the entire point of surfacing these 4 rows on this screen.
 */
const isSystemRole = computed(() => props.role?.is_system ?? false)

const form = reactive({
  name: '',
  description: '',
  level: 10,
  permissions: [] as string[],
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

function columnLabel(action: Action): string {
  return t(`admin.roles.action${action.charAt(0).toUpperCase()}${action.slice(1)}`)
}

function isChecked(slug: string): boolean {
  return form.permissions.includes(slug)
}

function toggle(slug: string, checked: boolean) {
  form.permissions = checked ? [...form.permissions, slug] : form.permissions.filter((p) => p !== slug)
}

/** A tab this role hides (its `hide-tab.*` mark is on the role). */
function isTabHidden(to: string): boolean {
  return form.permissions.includes(hideTabSlug(to))
}

/** Whether the role can open this tab at all (one of its permissions ticked) — Show only matters then. */
function canOpenTab(module: ModuleEntry): boolean {
  return !module.needs || module.needs.some((slug) => isChecked(slug))
}

/** Show or hide tabs: removes or adds their hide marks. */
function setTabsShown(tabs: string[], shown: boolean) {
  const marks = tabs.map(hideTabSlug)
  form.permissions = shown
    ? form.permissions.filter((p) => !marks.includes(p))
    : Array.from(new Set([...form.permissions, ...marks]))
}

function tabsOf(modules: ModuleEntry[]): string[] {
  return modules.flatMap((module) => (module.tab ? [module.tab] : []))
}

/**
 * One tree node per sidebar group, in the same order as adminNav.ts, and
 * menus/tabs in MODULES' own (sidebar) order. A group with no modules in it
 * (shouldn't happen, but cheap to guard) is dropped rather than shown as an
 * empty, uncollapsible header.
 */
const groupedModules = computed(() =>
  GROUPS.map((group) => {
    const modulesInGroup = MODULES.filter((module) => module.group === group)

    // Sidebar order (MODULES' own order): a menu with tabs becomes one parent
    // node, placed where its first tab row is declared.
    const children: GroupChild[] = []
    const parents = new Map<string, Extract<GroupChild, { kind: 'parent' }>>()
    for (const module of modulesInGroup) {
      if (!module.parent) {
        children.push({ kind: 'module', key: module.name, module })
        continue
      }
      let node = parents.get(module.parent)
      if (!node) {
        node = { kind: 'parent', key: `${group}::${module.parent}`, name: module.parent, modules: [] }
        parents.set(module.parent, node)
        children.push(node)
      }
      if (module.menu) node.menuRow = module
      else node.modules.push(module)
    }

    return { name: group, modules: modulesInGroup, children }
  }).filter((group) => group.children.length > 0),
)

/** Every group/nested page starts expanded — collapsing is purely a scan-reducing convenience, never how a permission goes unnoticed. */
const collapsedGroups = ref(new Set<string>())

function isGroupCollapsed(key: string): boolean {
  return collapsedGroups.value.has(key)
}

function toggleGroupCollapsed(key: string) {
  const next = new Set(collapsedGroups.value)
  if (next.has(key)) {
    next.delete(key)
  } else {
    next.add(key)
  }
  collapsedGroups.value = next
}

/** Every collapsible key across the whole tree — top-level groups and the nested "one page, several tabs" buckets alike — for Expand all/Collapse all. */
const allCollapsibleKeys = computed(() => groupedModules.value.flatMap((group) => [group.name, ...group.children.filter((c) => c.kind === 'parent').map((c) => c.key)]))

/** Every distinct permission slug across every module — several modules deliberately share slugs (e.g. Repair shops reuses assets.*), so this de-dupes. */
const allSlugs = computed(() => Array.from(new Set(MODULES.flatMap((module) => Object.values(module.actions) as string[]))))

const allTabs = tabsOf(MODULES)

const allChecked = computed(() => allSlugs.value.length > 0 && allSlugs.value.every((slug) => isChecked(slug)) && !allTabs.some(isTabHidden))

// Only the slugs listed here: a permission without a row (e.g. added later) is never dropped by Select all.
// Select all also shows every tab; clearing all removes the hide marks too (nothing left to hide).
function toggleAll(checked: boolean) {
  const marks = allTabs.map(hideTabSlug)
  form.permissions = checked
    ? Array.from(new Set([...form.permissions, ...allSlugs.value])).filter((p) => !marks.includes(p))
    : form.permissions.filter((p) => !allSlugs.value.includes(p) && !marks.includes(p))
}

/** A row's own actions, in the usual order (View, Create, Update, Delete, ...). */
function moduleActions(module: ModuleEntry): Action[] {
  return COLUMNS.filter((action) => module.actions[action])
}

/** Some but not all of these rows' boxes ticked: shown as a dash in the row's own box. */
function isGroupPartlyChecked(modules: ModuleEntry[]): boolean {
  const slugs = groupSlugs(modules)
  const ticked = slugs.filter((slug) => isChecked(slug)).length
  return ticked > 0 && (ticked < slugs.length || tabsOf(modules).some(isTabHidden))
}

// Search: a row matches on its own name, its menu's or its main menu's.
const search = ref('')
const visibleGroups = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (term === '') return groupedModules.value
  const hit = (text: string) => text.toLowerCase().includes(term)
  return groupedModules.value
    .map((group) => {
      if (hit(groupLabel(group.name))) return group
      const children = group.children
        .map((child): GroupChild | null => {
          if (child.kind === 'module') return hit(child.module.name) ? child : null
          if (hit(child.name)) return child
          const modules = child.modules.filter((module) => hit(module.name))
          return modules.length > 0 ? { ...child, modules } : null
        })
        .filter((child): child is GroupChild => child !== null)
      return children.length > 0 ? { ...group, children } : null
    })
    .filter((group): group is NonNullable<typeof group> => group !== null)
})

/** Open unless collapsed; a search opens everything it found. */
function isOpen(key: string): boolean {
  return search.value.trim() !== '' || !isGroupCollapsed(key)
}

function moduleSlugs(module: (typeof MODULES)[number]): string[] {
  return Object.values(module.actions) as string[]
}

function isModuleFullyChecked(module: (typeof MODULES)[number]): boolean {
  const slugs = moduleSlugs(module)
  if (module.tab) return canOpenTab(module) && !isTabHidden(module.tab) && slugs.every((slug) => isChecked(slug))
  return slugs.length > 0 && slugs.every((slug) => isChecked(slug))
}

/** A tab row's box shows or hides the tab (with its own actions); any other row's box ticks all its actions. */
function toggleModule(module: (typeof MODULES)[number], checked: boolean) {
  const slugs = moduleSlugs(module)
  form.permissions = checked
    ? Array.from(new Set([...form.permissions, ...slugs]))
    : form.permissions.filter((p) => !slugs.includes(p))
  if (module.tab) setTabsShown([module.tab], checked)
}

/**
 * adminNav.ts's own i18n key suffix for each group label above — an
 * explicit table rather than derived, since the two label sets don't share
 * a casing convention ("E-Approvals" here vs "eApprovals" there). "Other"
 * has no sidebar group of its own, so it falls through to its raw label.
 */
const GROUP_I18N_KEYS: Partial<Record<(typeof GROUPS)[number], string>> = {
  Dashboard: 'dashboard',
  'Project Management': 'projectManagement',
  'Front Office': 'frontOffice',
  Academic: 'academic',
  Students: 'students',
  Staff: 'staff',
  Billing: 'billing',
  Accounting: 'accounting',
  Loan: 'loan',
  Assets: 'assets',
  Website: 'website',
  Communication: 'communication',
  Settings: 'settings',
  'E-Approvals': 'eApprovals',
  'Approval Flow': 'approvalFlow',
}

function groupLabel(group: (typeof GROUPS)[number]): string {
  const key = GROUP_I18N_KEYS[group]
  return key ? t(`adminNav.groups.${key}`) : group
}

function groupSlugs(modules: (typeof MODULES)[number][]): string[] {
  return Array.from(new Set(modules.flatMap((module) => moduleSlugs(module))))
}

function isGroupFullyChecked(modules: (typeof MODULES)[number][]): boolean {
  const slugs = groupSlugs(modules)
  return slugs.length > 0 && slugs.every((slug) => isChecked(slug)) && !tabsOf(modules).some(isTabHidden)
}

// Ticking a whole menu shows all its tabs; unticking only removes its permissions (no hide marks,
// so ticking View again later brings every tab back).
function toggleGroup(modules: (typeof MODULES)[number][], checked: boolean) {
  const slugs = groupSlugs(modules)
  form.permissions = checked
    ? Array.from(new Set([...form.permissions, ...slugs]))
    : form.permissions.filter((p) => !slugs.includes(p))
  if (checked) setTabsShown(tabsOf(modules), true)
}

/** A parent's rows: its menu row (if any) and its tabs. */
function parentRows(child: Extract<GroupChild, { kind: 'parent' }>): ModuleEntry[] {
  return child.menuRow ? [child.menuRow, ...child.modules] : child.modules
}

function resetForm() {
  form.name = props.role?.name ?? ''
  form.description = props.role?.description ?? ''
  form.level = props.role?.level ?? 10
  form.permissions = props.role?.permissions ?? []
  errors.value = {}
  generalError.value = null
  copiedFrom.value = null
}

// Copy from another role: replaces every tick here (hidden tabs included)
// with that role's, nothing kept. Saved only with Save.
const otherRoles = ref<Role[]>([])
const copiedFrom = ref<string | null>(null)

async function loadOtherRoles() {
  otherRoles.value = (await rolesService.listAll().catch(() => [] as Role[])).filter((r) => r.id !== props.role?.id)
}

function copyFrom(id: string) {
  const source = otherRoles.value.find((r) => String(r.id) === id)
  if (!source) return
  form.permissions = [...(source.permissions ?? [])]
  copiedFrom.value = source.name
}

watch(
  () => [props.modelValue, props.role] as const,
  ([open]) => {
    if (open) {
      resetForm()
      void loadOtherRoles()
    }
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    if (isEditing.value) {
      await rolesService.update(props.role!.id, form)
    } else {
      await rolesService.create(form)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.roles.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.roles.editTitle') : t('admin.roles.createTitle')"
    size="full"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseInput
          v-model="form.name"
          required
          :disabled="isSystemRole"
          :label="t('admin.roles.roleName')"
          :error="errors.name?.[0]"
        />
        <BaseInput
          :model-value="String(form.level)"
          type="number"
          required
          :disabled="isSystemRole"
          :label="t('admin.roles.level')"
          :hint="t('admin.roles.levelHint')"
          :error="errors.level?.[0]"
          @update:model-value="form.level = Number($event) || 0"
        />
        <BaseInput v-model="form.description" :label="t('admin.roles.description')" :error="errors.description?.[0]" />
      </div>

      <div>
        <div class="mb-2 flex items-center justify-between gap-3">
          <label class="flex items-center gap-2 text-sm font-medium text-neutral-700">
            <input
              type="checkbox"
              :checked="allChecked"
              class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              @change="toggleAll(($event.target as HTMLInputElement).checked)"
            />
            {{ t('admin.roles.permissions') }}
          </label>
          <div class="flex items-center gap-3 text-sm">
            <button type="button" class="font-medium text-secondary-600 hover:text-secondary-700" @click="collapsedGroups = new Set()">
              {{ t('admin.roles.expandAll') }}
            </button>
            <button type="button" class="font-medium text-secondary-600 hover:text-secondary-700" @click="collapsedGroups = new Set(allCollapsibleKeys)">
              {{ t('admin.roles.collapseAll') }}
            </button>
          </div>
        </div>
        <div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center">
          <input
            v-model="search"
            type="search"
            :placeholder="t('admin.roles.searchMenus')"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-sm"
          />
          <select
            v-if="otherRoles.length > 0"
            :value="''"
            :title="t('admin.roles.copyFromHint')"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
            @change="copyFrom(($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''"
          >
            <option value="" disabled>{{ t('admin.roles.copyFrom') }}</option>
            <option v-for="r in otherRoles" :key="r.id" :value="String(r.id)">{{ r.name }}</option>
          </select>
        </div>
        <p v-if="copiedFrom" class="mb-2 rounded-lg bg-primary-50 px-3 py-2 text-sm text-primary-900">{{ t('admin.roles.copiedFrom', { name: copiedFrom }) }}</p>
        <!-- A tree, not a matrix: each row shows only the actions it has, next to its name. -->
        <div class="max-h-[60vh] overflow-auto rounded-lg border border-neutral-200 text-sm">
          <template v-for="group in visibleGroups" :key="group.name">
            <div class="sticky top-0 z-10 flex items-center gap-2 border-b border-neutral-200 bg-neutral-50 px-3 py-2 font-semibold text-neutral-800">
              <button
                type="button"
                class="w-4 text-neutral-400 hover:text-neutral-600"
                :aria-label="isGroupCollapsed(group.name) ? t('common.expand') : t('common.collapse')"
                @click="toggleGroupCollapsed(group.name)"
              >
                {{ isOpen(group.name) ? '▾' : '▸' }}
              </button>
              <input
                type="checkbox"
                :checked="isGroupFullyChecked(group.modules)"
                :indeterminate.prop="isGroupPartlyChecked(group.modules)"
                class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                @change="toggleGroup(group.modules, ($event.target as HTMLInputElement).checked)"
              />
              <span class="cursor-pointer" @click="toggleGroupCollapsed(group.name)">{{ groupLabel(group.name) }}</span>
            </div>

            <template v-if="isOpen(group.name)">
              <template v-for="child in group.children" :key="child.key">
                <!-- A menu with no tabs. -->
                <div v-if="child.kind === 'module'" class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-neutral-100 py-2 pl-9 pr-3 hover:bg-neutral-50/60">
                  <label class="flex min-w-0 flex-1 basis-60 items-center gap-2 text-neutral-800">
                    <input
                      type="checkbox"
                      :checked="isModuleFullyChecked(child.module)"
                      :indeterminate.prop="isGroupPartlyChecked([child.module])"
                      class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                      @change="toggleModule(child.module, ($event.target as HTMLInputElement).checked)"
                    />
                    <span>{{ child.module.name }}</span>
                  </label>
                  <div class="flex flex-wrap gap-x-3 gap-y-1">
                    <label v-for="action in moduleActions(child.module)" :key="action" class="flex items-center gap-1.5 whitespace-nowrap text-neutral-600">
                      <input
                        type="checkbox"
                        :checked="isChecked(child.module.actions[action]!)"
                        class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                        @change="toggle(child.module.actions[action]!, ($event.target as HTMLInputElement).checked)"
                      />
                      {{ columnLabel(action) }}
                    </label>
                  </div>
                </div>

                <!-- A menu whose page has tabs: the menu, then a row per tab. -->
                <template v-else>
                  <div class="flex items-center gap-2 border-b border-neutral-100 py-2 pl-9 pr-3 font-medium text-neutral-800">
                    <button
                      type="button"
                      class="w-4 text-neutral-400 hover:text-neutral-600"
                      :aria-label="isGroupCollapsed(child.key) ? t('common.expand') : t('common.collapse')"
                      @click="toggleGroupCollapsed(child.key)"
                    >
                      {{ isOpen(child.key) ? '▾' : '▸' }}
                    </button>
                    <input
                      type="checkbox"
                      :checked="isGroupFullyChecked(parentRows(child))"
                      :indeterminate.prop="isGroupPartlyChecked(parentRows(child))"
                      class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                      @change="toggleGroup(parentRows(child), ($event.target as HTMLInputElement).checked)"
                    />
                    <span class="min-w-0 flex-1 cursor-pointer" @click="toggleGroupCollapsed(child.key)">{{ child.name }}</span>
                    <!-- The menu's own actions, which its tabs follow. -->
                    <div v-if="child.menuRow" class="flex flex-wrap gap-x-3 gap-y-1 font-normal">
                      <label v-for="action in moduleActions(child.menuRow)" :key="action" class="flex items-center gap-1.5 whitespace-nowrap text-neutral-600">
                        <input
                          type="checkbox"
                          :checked="isChecked(child.menuRow.actions[action]!)"
                          class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                          @change="toggle(child.menuRow.actions[action]!, ($event.target as HTMLInputElement).checked)"
                        />
                        {{ columnLabel(action) }}
                      </label>
                    </div>
                  </div>
                  <template v-if="isOpen(child.key)">
                    <div
                      v-for="module in child.modules"
                      :key="module.name"
                      class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-neutral-100 py-2 pl-16 pr-3 hover:bg-neutral-50/60"
                    >
                      <label
                        class="flex min-w-0 flex-1 basis-60 items-center gap-2"
                        :class="module.tab && !canOpenTab(module) ? 'text-neutral-400' : 'text-neutral-700'"
                        :title="module.tab && !canOpenTab(module) ? t('admin.roles.tabNeedsMenu') : undefined"
                      >
                        <input
                          type="checkbox"
                          :checked="isModuleFullyChecked(module)"
                          :indeterminate.prop="isGroupPartlyChecked([module])"
                          :disabled="!!module.tab && !canOpenTab(module)"
                          class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500 disabled:opacity-40"
                          @change="toggleModule(module, ($event.target as HTMLInputElement).checked)"
                        />
                        <span>{{ module.name }}</span>
                        <span v-if="module.tab && isTabHidden(module.tab)" class="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">{{ t('admin.roles.tabHidden') }}</span>
                      </label>
                      <div class="flex flex-wrap gap-x-3 gap-y-1">
                        <label v-for="action in moduleActions(module)" :key="action" class="flex items-center gap-1.5 whitespace-nowrap text-neutral-600">
                          <input
                            type="checkbox"
                            :checked="isChecked(module.actions[action]!)"
                            class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                            @change="toggle(module.actions[action]!, ($event.target as HTMLInputElement).checked)"
                          />
                          {{ columnLabel(action) }}
                        </label>
                      </div>
                    </div>
                  </template>
                </template>
              </template>
            </template>
          </template>
          <p v-if="visibleGroups.length === 0" class="px-3 py-6 text-center text-neutral-500">{{ t('admin.roles.noMenusFound') }}</p>
        </div>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
