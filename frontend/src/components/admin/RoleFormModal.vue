<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
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
interface ModuleEntry {
  name: string
  group: (typeof GROUPS)[number]
  /**
   * Some sidebar entries are one page with a tab bar switching between
   * several otherwise-unrelated permission modules (see ProgramsTabs.vue,
   * StudyBuildingTabs.vue) — set this to that entry's own name so the tree
   * nests these modules one level deeper, under that shared page, instead
   * of listing them as flat siblings of everything else in the group.
   */
  parent?: string
  actions: Partial<Record<Action, string>>
}

/** One node in the permission tree below a top-level group: either a single module, or a "parent" bucket for a page whose own tab bar switches between several modules (see ModuleEntry.parent). */
type GroupChild =
  | { kind: 'module'; key: string; module: ModuleEntry }
  | { kind: 'parent'; key: string; name: string; modules: ModuleEntry[] }

/**
 * Listed in sidebar order: main menu (group) > menu > tabs. A menu whose page
 * has a tab bar is a `parent`, and its rows are that page's tabs — tabs that
 * share one permission are named together in one row (see router/*Tabs.ts).
 * A permission used in two places (e.g. My requests) shows in both, as one
 * shared checkbox.
 */
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
  { name: 'Projects', group: 'Project Management', actions: { view: 'projects.view', create: 'projects.create', update: 'projects.update', delete: 'projects.delete' } },

  // ── Front Office (one page, four tabs) ──
  { name: 'Inquiries', group: 'Front Office', parent: 'Front Office', actions: { view: 'inquiries.view', create: 'inquiries.create', update: 'inquiries.update', delete: 'inquiries.delete' } },
  { name: 'Meetings', group: 'Front Office', parent: 'Front Office', actions: { view: 'meetings.view', create: 'meetings.create', update: 'meetings.update', delete: 'meetings.delete' } },
  { name: 'Appointments', group: 'Front Office', parent: 'Front Office', actions: { view: 'appointments.view', create: 'appointments.create', update: 'appointments.update', delete: 'appointments.delete' } },
  { name: 'Visitors', group: 'Front Office', parent: 'Front Office', actions: { view: 'visitors.view', create: 'visitors.create', update: 'visitors.update', delete: 'visitors.delete' } },

  // ── Academic ──
  // Not the website's "Programs" (Website group) — this is the internal program catalog.
  { name: 'Academic programs', group: 'Academic', parent: 'Programs', actions: { view: 'academic-programs.view', create: 'academic-programs.create', update: 'academic-programs.update', delete: 'academic-programs.delete' } },
  { name: 'Academic years', group: 'Academic', parent: 'Programs', actions: { view: 'academic-years.view', create: 'academic-years.create', update: 'academic-years.update', delete: 'academic-years.delete' } },
  { name: 'Course packages', group: 'Academic', parent: 'Programs', actions: { view: 'course-packages.view', create: 'course-packages.create', update: 'course-packages.update', delete: 'course-packages.delete' } },
  { name: 'Books', group: 'Academic', parent: 'Programs', actions: { view: 'books.view', create: 'books.create', update: 'books.update', delete: 'books.delete' } },
  { name: 'Book categories', group: 'Academic', parent: 'Programs', actions: { view: 'book-categories.view', create: 'book-categories.create', update: 'book-categories.update', delete: 'book-categories.delete' } },
  { name: 'Terms / semesters', group: 'Academic', parent: 'School Setup', actions: { view: 'academic-terms.view', manage: 'academic-terms.manage' } },
  { name: 'Grade levels', group: 'Academic', parent: 'School Setup', actions: { view: 'grade-levels.view', manage: 'grade-levels.manage' } },
  { name: 'Curriculum', group: 'Academic', parent: 'School Setup', actions: { view: 'curriculum.view', manage: 'curriculum.manage' } },
  { name: 'School calendar', group: 'Academic', parent: 'School Setup', actions: { view: 'school-calendar.view', manage: 'school-calendar.manage' } },
  { name: 'Promotion rules', group: 'Academic', parent: 'School Setup', actions: { view: 'promotion-rules.view', manage: 'promotion-rules.manage' } },
  { name: 'Promote students', group: 'Academic', parent: 'School Setup', actions: { view: 'student-promotions.view', run: 'student-promotions.run' } },
  { name: 'Videos', group: 'Academic', actions: { view: 'videos.view', create: 'videos.create', update: 'videos.update', delete: 'videos.delete' } },
  { name: 'Buildings', group: 'Academic', parent: 'Study Building', actions: { view: 'buildings.view', create: 'buildings.create', update: 'buildings.update', delete: 'buildings.delete' } },
  { name: 'Classrooms', group: 'Academic', parent: 'Study Building', actions: { view: 'classrooms.view', create: 'classrooms.create', update: 'classrooms.update', delete: 'classrooms.delete' } },
  { name: 'Classes', group: 'Academic', actions: { view: 'classes.view', create: 'classes.create', update: 'classes.update', delete: 'classes.delete' } },
  // Manage = edit the timetable and decide schedule change requests.
  { name: 'Class, Teacher, Room, Subjects, Conflicts', group: 'Academic', parent: 'Timetable', actions: { view: 'timetable.view', manage: 'timetable.manage' } },
  { name: 'Schedule changes', group: 'Academic', parent: 'Timetable', actions: { request: 'timetable.request', manage: 'timetable.manage' } },
  // Create = write lesson plans and the teaching log.
  { name: 'Lesson plans, This week, Teaching log, Progress, Delays', group: 'Academic', parent: 'Lesson Plans', actions: { view: 'lesson-plans.view', create: 'lesson-plans.write' } },
  { name: 'Review', group: 'Academic', parent: 'Lesson Plans', actions: { approve: 'lesson-plans.review' } },
  { name: 'Attendance, Summary', group: 'Academic', parent: 'Attendance', actions: { view: 'attendance.view', create: 'attendance.create', update: 'attendance.update' } },
  { name: 'Examination menu', group: 'Academic', parent: 'Examination', actions: { view: 'examination.view' } },
  { name: 'Assessment Setup', group: 'Academic', parent: 'Examination', actions: { view: 'assessment.view', manage: 'assessment.manage' } },
  { name: 'Exam Schedule', group: 'Academic', parent: 'Examination', actions: { view: 'exam-schedules.view', manage: 'exam-schedules.manage' } },
  // View/Enter = own classes and subjects; Manage all = every class.
  {
    name: 'Grade Entry, Validation, Score Calculation, Result Approval, Grade Correction, Transcript, Student Progress, Remedial Teaching, Result Export',
    group: 'Academic',
    parent: 'Examination',
    actions: { view: 'gradebook.view', enter: 'gradebook.enter', manageAll: 'gradebook.manage-all', approve: 'gradebook.approve' },
  },
  {
    name: 'Exams, Make-up Exam, Certificate',
    group: 'Academic',
    parent: 'Examination',
    actions: { view: 'exam-applications.view', create: 'exam-applications.create', update: 'exam-applications.update', delete: 'exam-applications.delete', approve: 'exam-applications.approve', reject: 'exam-applications.reject' },
  },
  { name: 'Grades', group: 'Academic', parent: 'Examination', actions: { view: 'exam-scores.view', update: 'exam-scores.update', manageAll: 'exam-scores.manage-all' } },
  { name: 'Academic reports', group: 'Academic', actions: { view: 'academic-reports.view', export: 'academic-reports.export' } },

  // ── Students ──
  // Create also opens Student imports; Approve = approve online registrations.
  { name: 'Students (and Student imports)', group: 'Students', actions: { view: 'students.view', create: 'students.create', update: 'students.update', delete: 'students.delete', approve: 'students.approve-registration' } },
  {
    name: 'Enrollments',
    group: 'Students',
    actions: {
      view: 'enrollments.view',
      create: 'enrollments.create',
      update: 'enrollments.update',
      delete: 'enrollments.delete',
      cancel: 'enrollments.cancel',
      transfer: 'enrollments.transfer',
      table: 'enrollments.change-table',
      status: 'enrollments.change-status',
      note: 'enrollments.note',
      responsibleStaff: 'enrollments.responsible-staff',
    },
  },

  // ── HRM ──
  { name: 'Staff list', group: 'Staff', parent: 'Staff', actions: { view: 'staff.view', create: 'staff.create', update: 'staff.update', delete: 'staff.delete', status: 'staff.change-status' } },
  { name: 'Own staff profile only (see and edit just their own)', group: 'Staff', parent: 'Staff', actions: { update: 'staff.update-own' } },
  { name: 'Qualifications, Assignments, Homeroom, Load, Availability, Substitutes, Documents', group: 'Staff', parent: 'Teachers', actions: { view: 'teachers.view', manage: 'teachers.manage' } },
  { name: 'Staff status history', group: 'Staff', actions: { view: 'staff.view' } },
  { name: 'School', group: 'Staff', parent: 'Organization Management', actions: { view: 'tenant-settings.view', update: 'tenant-settings.update' } },
  { name: 'Branch, Department, Team, Job grade, Job level, Reporting manager, Hierarchy', group: 'Staff', parent: 'Organization Management', actions: { view: 'organization.view', create: 'organization.create', update: 'organization.update', delete: 'organization.delete' } },
  { name: 'Position', group: 'Staff', parent: 'Organization Management', actions: { view: 'positions.view', create: 'positions.create', update: 'positions.update', delete: 'positions.delete' } },
  { name: 'Manpower requests, Job positions, Job postings, Applicants, CVs, Interviews, Evaluations, Selection, Offers, Pipeline', group: 'Staff', parent: 'Recruitment', actions: { view: 'recruitment.view', create: 'recruitment.create', update: 'recruitment.update', delete: 'recruitment.delete' } },
  { name: 'Manpower request approval', group: 'Staff', parent: 'Recruitment', actions: { approve: 'manpower-requests.approve', reject: 'manpower-requests.reject' } },
  { name: 'Employees, Check-ins, Records, Shifts, Work schedules, Holidays, Late, Early leave, Absence, Overtime, Corrections', group: 'Staff', parent: 'Time & Attendance', actions: { view: 'staff-attendance.view', manage: 'staff-attendance.manage' } },
  { name: 'Approval (sign off a month)', group: 'Staff', parent: 'Time & Attendance', actions: { approve: 'staff-attendance.approve' } },
  { name: 'Overtime requests', group: 'Staff', parent: 'Time & Attendance', actions: { approve: 'overtime-requests.approve', reject: 'overtime-requests.reject' } },
  { name: 'Attendance corrections', group: 'Staff', parent: 'Time & Attendance', actions: { approve: 'attendance-corrections.approve', reject: 'attendance-corrections.reject' } },
  { name: 'Types, Policies, Balances, Workflow, Calendar, Carry forward, Reports', group: 'Staff', parent: 'Leave Management', actions: { view: 'leave-management.view', manage: 'leave-management.manage' } },
  { name: 'Leave requests', group: 'Staff', parent: 'Leave Management', actions: { view: 'leave-requests.view', approve: 'leave-requests.approve', reject: 'leave-requests.reject' } },
  { name: 'Salary structures, Basic salary, Allowances, Bonuses, Deductions, Overtime, Tax, NSSF, Loan/advance, Calculation, Payslip, History', group: 'Staff', parent: 'Payroll', actions: { view: 'payroll.view', manage: 'payroll.manage' } },
  // Create = work out a payroll, send it for approval and pay it.
  { name: 'Payroll runs', group: 'Staff', parent: 'Payroll', actions: { create: 'payroll.run' } },
  { name: 'Approval', group: 'Staff', parent: 'Payroll', actions: { approve: 'payroll.approve' } },
  // Approve = apply promotion recommendations.
  { name: 'KPI, Goals, Review, Evaluation forms, Score, Promotion', group: 'Staff', parent: 'Performance', actions: { view: 'performance.view', manage: 'performance.manage', approve: 'performance.approve-promotion' } },
  { name: 'Self assessment, Manager assessment (every staff account)', group: 'Staff', parent: 'Performance', actions: { view: 'my-requests.view' } },
  { name: 'Training records, Calendar, Certificates, Observations, Coaching, Development plans, Reports', group: 'Staff', parent: 'Professional Development', actions: { view: 'professional-development.view', manage: 'professional-development.manage' } },
  // The same self-service slug as E-Approvals > My requests: one shared checkbox.
  { name: 'My check-in, Request Leave, Resignation Form', group: 'Staff', actions: { view: 'my-requests.view' } },
  { name: 'Resignation requests', group: 'Staff', actions: { view: 'resignation-requests.view', approve: 'resignation-requests.approve', reject: 'resignation-requests.reject' } },

  // ── Billing ──
  { name: 'Billing dashboard & reports', group: 'Billing', actions: { view: 'billing-reports.view' } },
  { name: 'Products', group: 'Billing', actions: { view: 'products.view', create: 'products.create', update: 'products.update', delete: 'products.delete' } },
  { name: 'Invoices, Monthly', group: 'Billing', parent: 'Invoices', actions: { view: 'invoices.view', create: 'invoices.create', update: 'invoices.update', cancel: 'invoices.cancel' } },
  { name: 'Payments', group: 'Billing', actions: { view: 'payments.view', create: 'payments.create', update: 'payments.update', cancel: 'payments.cancel' } },
  { name: 'Receipts', group: 'Billing', actions: { view: 'receipts.view' } },
  { name: 'Billing notifications', group: 'Billing', actions: { send: 'notifications.send' } },
  { name: 'Companies', group: 'Billing', actions: { view: 'companies.view', create: 'companies.create', update: 'companies.update', delete: 'companies.delete' } },
  { name: 'Currency rates', group: 'Billing', actions: { view: 'currency-rates.view', create: 'currency-rates.create', update: 'currency-rates.update', delete: 'currency-rates.delete' } },

  // ── Accounting ──
  { name: 'Accounting (module access)', group: 'Accounting', actions: { view: 'accounting.view' } },
  { name: 'Accounting dashboard', group: 'Accounting', actions: { view: 'accounting.dashboard.view' } },
  { name: 'Accounts', group: 'Accounting', actions: { view: 'accounts.view', create: 'accounts.create', update: 'accounts.update', deactivate: 'accounts.deactivate' } },
  { name: 'Income', group: 'Accounting', actions: { view: 'income.view', create: 'income.create', update: 'income.update', cancel: 'income.cancel' } },
  {
    name: 'Expenses (also Expense for, Vendors)',
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
  { name: 'Loans, Repayments, Lenders, Reports', group: 'Accounting', parent: 'Borrowings', actions: { view: 'borrowings.view', manage: 'borrowings.manage' } },
  { name: 'Transactions', group: 'Accounting', actions: { view: 'transactions.view', create: 'transactions.create' } },
  { name: 'Accounting reports', group: 'Accounting', actions: { view: 'reports.financial.view', export: 'reports.financial.export' } },
  { name: 'Accounting periods', group: 'Accounting', actions: { close: 'accounting.period.close', create: 'accounting.adjustment.create' } },

  // ── Assets ──
  { name: 'Asset dashboard & Asset reports', group: 'Assets', actions: { view: 'assets.reports.view', export: 'assets.reports.export' } },
  {
    // Categories/Locations/Departments/Suppliers share these slugs (see AssetCategoryPolicy and friends).
    name: 'Assets (also Categories, Locations, Departments, Suppliers)',
    group: 'Assets',
    actions: {
      view: 'assets.view',
      create: 'assets.create',
      update: 'assets.update',
      delete: 'assets.delete',
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
  { name: 'Repair shops', group: 'Assets', actions: { view: 'assets.repair.view', create: 'assets.create', update: 'assets.update', delete: 'assets.delete' } },
  { name: 'Asset maintenance', group: 'Assets', actions: { view: 'assets.maintenance.view', create: 'assets.maintenance.create', update: 'assets.maintenance.update' } },

  // ── Website ──
  { name: 'Programs (website page)', group: 'Website', actions: { view: 'programs.view', create: 'programs.create', update: 'programs.update', delete: 'programs.delete' } },

  // ── Communication ──
  { name: 'Contact messages', group: 'Communication', actions: { view: 'contact-messages.view' } },
  { name: 'Notifications', group: 'Communication', actions: { view: 'notifications.view' } },
  { name: 'Student feedback', group: 'Communication', actions: { view: 'student-feedback.view', reply: 'student-feedback.reply' } },

  // ── Settings ──
  // Update also covers Telegram groups.
  { name: 'Settings, School documents, Telegram groups', group: 'Settings', actions: { view: 'tenant-settings.view', update: 'tenant-settings.update' } },
  { name: 'Users', group: 'Settings', actions: { view: 'users.view', create: 'users.create', update: 'users.update', delete: 'users.delete' } },
  { name: 'Roles', group: 'Settings', actions: { view: 'roles.view', create: 'roles.create', update: 'roles.update', delete: 'roles.delete', assign: 'roles.assign' } },
  { name: 'Audit logs', group: 'Settings', actions: { view: 'audit-logs.view', delete: 'audit-logs.delete' } },
  {
    name: 'Lookup categories, Languages, Translations',
    group: 'Settings',
    actions: {
      view: 'base-data.view',
      create: 'base-data.create',
      update: 'base-data.update',
      delete: 'base-data.delete',
      translations: 'base-data.manage-translations',
      languages: 'base-data.manage-languages',
    },
  },
  { name: 'Backup (own school database)', group: 'Settings', actions: { view: 'database-backups.download' } },

  // ── E-Approvals ──
  { name: 'Forms', group: 'E-Approvals', actions: { view: 'forms.view' } },
  { name: 'My requests', group: 'E-Approvals', actions: { view: 'my-requests.view' } },
  // The Approvals queue shows each kind of request its permission allows.
  { name: 'Form requests', group: 'E-Approvals', parent: 'Approvals', actions: { view: 'approval-requests.view', approve: 'approval-requests.approve', reject: 'approval-requests.reject' } },
  { name: 'Make-up class requests', group: 'E-Approvals', parent: 'Approvals', actions: { view: 'make-up-class-requests.view', approve: 'make-up-class-requests.approve', reject: 'make-up-class-requests.reject' } },
  { name: 'Class change requests', group: 'E-Approvals', parent: 'Approvals', actions: { view: 'class-change-requests.view', approve: 'class-change-requests.approve', reject: 'class-change-requests.reject' } },
  { name: 'Form categories', group: 'E-Approvals', actions: { manage: 'form-categories.manage' } },
  { name: 'Form templates', group: 'E-Approvals', actions: { manage: 'form-templates.manage' } },

  // ── Approval Flow ──
  { name: 'Approval groups, Flow setting', group: 'Approval Flow', actions: { manage: 'approval-groups.manage' } },

  // ── Other ──
  { name: 'Help & Support (help articles, FAQ, NewTech contacts, support tickets)', group: 'Other', actions: { view: 'help-support.view' } },
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
      node.modules.push(module)
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

const allChecked = computed(() => allSlugs.value.length > 0 && allSlugs.value.every((slug) => isChecked(slug)))

// Only the slugs listed here: a permission without a row (e.g. added later) is never dropped by Select all.
function toggleAll(checked: boolean) {
  form.permissions = checked
    ? Array.from(new Set([...form.permissions, ...allSlugs.value]))
    : form.permissions.filter((p) => !allSlugs.value.includes(p))
}

function moduleSlugs(module: (typeof MODULES)[number]): string[] {
  return Object.values(module.actions) as string[]
}

function isModuleFullyChecked(module: (typeof MODULES)[number]): boolean {
  const slugs = moduleSlugs(module)
  return slugs.length > 0 && slugs.every((slug) => isChecked(slug))
}

function toggleModule(module: (typeof MODULES)[number], checked: boolean) {
  const slugs = moduleSlugs(module)
  form.permissions = checked
    ? Array.from(new Set([...form.permissions, ...slugs]))
    : form.permissions.filter((p) => !slugs.includes(p))
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
  return slugs.length > 0 && slugs.every((slug) => isChecked(slug))
}

function toggleGroup(modules: (typeof MODULES)[number][], checked: boolean) {
  const slugs = groupSlugs(modules)
  form.permissions = checked
    ? Array.from(new Set([...form.permissions, ...slugs]))
    : form.permissions.filter((p) => !slugs.includes(p))
}

function resetForm() {
  form.name = props.role?.name ?? ''
  form.description = props.role?.description ?? ''
  form.level = props.role?.level ?? 10
  form.permissions = props.role?.permissions ?? []
  errors.value = {}
  generalError.value = null
}

watch(
  () => [props.modelValue, props.role] as const,
  ([open]) => {
    if (open) resetForm()
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

      <div class="grid grid-cols-3 gap-4">
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
        <div class="max-h-[60vh] overflow-auto rounded-lg border border-neutral-200">
          <table class="min-w-full text-sm">
            <thead class="sticky top-0 z-20 bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
              <tr>
                <th class="w-px whitespace-nowrap border-r border-neutral-200 bg-neutral-50 px-3 py-2 text-left lg:sticky lg:left-0 lg:z-30">
                  {{ t('admin.roles.module') }}
                </th>
                <th v-for="column in COLUMNS" :key="column" class="px-3 py-2 text-center">{{ columnLabel(column) }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="group in groupedModules" :key="group.name">
                <tr class="bg-neutral-50/80">
                  <td :colspan="COLUMNS.length + 1" class="px-3 py-1.5">
                    <label class="flex items-center gap-2 font-semibold text-neutral-800">
                      <button
                        type="button"
                        class="w-4 text-neutral-400 hover:text-neutral-600"
                        :aria-label="isGroupCollapsed(group.name) ? t('common.expand') : t('common.collapse')"
                        @click="toggleGroupCollapsed(group.name)"
                      >
                        {{ isGroupCollapsed(group.name) ? '▸' : '▾' }}
                      </button>
                      <input
                        type="checkbox"
                        :checked="isGroupFullyChecked(group.modules)"
                        class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                        @change="toggleGroup(group.modules, ($event.target as HTMLInputElement).checked)"
                      />
                      <span class="cursor-pointer" @click="toggleGroupCollapsed(group.name)">{{ groupLabel(group.name) }}</span>
                    </label>
                  </td>
                </tr>

                <template v-for="child in group.children" :key="child.key">
                  <!-- A single module, listed directly under the group. -->
                  <tr v-if="child.kind === 'module'" v-show="!isGroupCollapsed(group.name)">
                    <td class="w-px whitespace-nowrap border-r border-neutral-200 bg-white py-2 pl-16 pr-3 text-neutral-700 lg:sticky lg:left-0 lg:z-10">
                      <label class="flex items-center gap-2">
                        <input
                          type="checkbox"
                          :checked="isModuleFullyChecked(child.module)"
                          class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                          @change="toggleModule(child.module, ($event.target as HTMLInputElement).checked)"
                        />
                        {{ child.module.name }}
                      </label>
                    </td>
                    <td v-for="column in COLUMNS" :key="column" class="px-3 py-2 text-center">
                      <input
                        v-if="child.module.actions[column]"
                        type="checkbox"
                        :checked="isChecked(child.module.actions[column]!)"
                        class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                        @change="toggle(child.module.actions[column]!, ($event.target as HTMLInputElement).checked)"
                      />
                      <span v-else class="text-neutral-300">—</span>
                    </td>
                  </tr>

                  <!-- One page whose own tab bar switches between several modules (Programs, Study Building) — nested one level deeper still. -->
                  <template v-else>
                    <tr v-show="!isGroupCollapsed(group.name)">
                      <td :colspan="COLUMNS.length + 1" class="py-1.5 pl-9 pr-3">
                        <label class="flex items-center gap-2 font-medium text-neutral-700">
                          <button
                            type="button"
                            class="w-4 text-neutral-400 hover:text-neutral-600"
                            :aria-label="isGroupCollapsed(child.key) ? t('common.expand') : t('common.collapse')"
                            @click="toggleGroupCollapsed(child.key)"
                          >
                            {{ isGroupCollapsed(child.key) ? '▸' : '▾' }}
                          </button>
                          <input
                            type="checkbox"
                            :checked="isGroupFullyChecked(child.modules)"
                            class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                            @change="toggleGroup(child.modules, ($event.target as HTMLInputElement).checked)"
                          />
                          <span class="cursor-pointer" @click="toggleGroupCollapsed(child.key)">{{ child.name }}</span>
                        </label>
                      </td>
                    </tr>
                    <tr v-for="module in child.modules" v-show="!isGroupCollapsed(group.name) && !isGroupCollapsed(child.key)" :key="module.name">
                      <td class="w-px whitespace-nowrap border-r border-neutral-200 bg-white py-2 pl-24 pr-3 text-neutral-700 lg:sticky lg:left-0 lg:z-10">
                        <label class="flex items-center gap-2">
                          <input
                            type="checkbox"
                            :checked="isModuleFullyChecked(module)"
                            class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                            @change="toggleModule(module, ($event.target as HTMLInputElement).checked)"
                          />
                          {{ module.name }}
                        </label>
                      </td>
                      <td v-for="column in COLUMNS" :key="column" class="px-3 py-2 text-center">
                        <input
                          v-if="module.actions[column]"
                          type="checkbox"
                          :checked="isChecked(module.actions[column]!)"
                          class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                          @change="toggle(module.actions[column]!, ($event.target as HTMLInputElement).checked)"
                        />
                        <span v-else class="text-neutral-300">—</span>
                      </td>
                    </tr>
                  </template>
                </template>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
