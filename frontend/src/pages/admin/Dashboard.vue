<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { accountingReportsService, type IncomeDetail } from '@/services/accounting'
import { approvalRequestsService } from '@/services/approvalRequests'
import { type AttendanceRecord, attendanceService, type AttendanceStatusValue, type MyAttendanceHoursRow } from '@/services/attendance'
import { enrollmentsService } from '@/services/enrollments'
import { examApplicationsService } from '@/services/examApplications'
import { type Invoice, invoicesService, type InvoiceStatusValue } from '@/services/invoices'
import { leaveRequestsService } from '@/services/leaveRequests'
import { makeUpClassCourseLabel, makeUpClassRequestsService } from '@/services/makeUpClassRequests'
import type { MonthlyInvoice } from '@/services/monthlyInvoices'
import { monthlyPaymentAlertsService } from '@/services/monthlyPaymentAlerts'
import { type TermStudyAlert, termStudyAlertsService } from '@/services/termStudyAlerts'
import { resignationRequestsService } from '@/services/resignationRequests'
import { useAuthStore } from '@/stores/auth'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

const auth = useAuthStore()
const { t } = useI18n()

interface QuickAccessItem {
  labelKey: string
  to: string
  /** Shown when the user holds any one of these. */
  permission?: string | string[]
  icon: string
}

function canSee(item: QuickAccessItem): boolean {
  if (!item.permission) return true
  return (Array.isArray(item.permission) ? item.permission : [item.permission]).some((p) => auth.can(p))
}

/**
 * Every icon is a single Heroicons outline path — kept inline so this tile
 * grid has no extra dependency.
 *
 * Each `permission` is its own dedicated `dashboard.cards.*` slug, not the
 * page's real CRUD permission (e.g. the Register Student tile checks
 * dashboard.cards.register-student, not students.create) — see
 * Permissions::DASHBOARD_CARDS_REGISTER_STUDENT etc. This is deliberate: a
 * role's dashboard shortcuts are a display choice a school admin makes in
 * the Role editor, independent of whether that role can actually reach
 * students.create elsewhere in the app.
 */
const quickAccessItems: QuickAccessItem[] = [
  {
    labelKey: 'admin.dashboard.registerStudent',
    to: '/admin/students/new',
    permission: 'dashboard.cards.register-student',
    icon: 'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z',
  },
  {
    labelKey: 'admin.dashboard.enrollment',
    to: '/admin/enrollments/new',
    permission: 'dashboard.cards.enrollment',
    icon: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z',
  },
  {
    labelKey: 'admin.dashboard.classes',
    to: '/admin/classes',
    permission: 'dashboard.cards.classes',
    icon: 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25',
  },
  {
    labelKey: 'admin.dashboard.studentPayment',
    to: '/admin/payments',
    permission: 'dashboard.cards.student-payment',
    icon: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z',
  },
  {
    labelKey: 'adminNav.items.invoices',
    to: '/admin/invoices',
    permission: 'dashboard.cards.invoices',
    icon: 'M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0c1.1.128 1.907 1.077 1.907 2.185zM9.75 9h.008v.008H9.75V9zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 4.5h.008v.008h-.008V13.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
  },
  {
    labelKey: 'admin.dashboard.studentAttendance',
    to: '/admin/attendance',
    permission: 'dashboard.cards.student-attendance',
    icon: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
  },
  {
    labelKey: 'admin.dashboard.teacherAttendance',
    to: '/admin/staff',
    permission: 'dashboard.cards.teacher-attendance',
    icon: 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
  },
  {
    labelKey: 'adminNav.items.users',
    to: '/admin/users',
    permission: 'dashboard.cards.users',
    icon: 'M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z',
  },
  {
    labelKey: 'adminNav.items.roles',
    to: '/admin/roles',
    permission: 'dashboard.cards.roles',
    icon: 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z',
  },
  {
    // Like Approvals below, gated on the page's own permission (the same as
    // adminNav.ts's Examination entry) rather than a dashboard.cards.* slug.
    labelKey: 'adminNav.items.examination',
    to: '/admin/exams',
    permission: 'examination.view',
    icon: 'M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5',
  },
  {
    // Unlike the tiles above, gated on the queue's own permissions (the same
    // list as adminNav.ts's Approvals entry): only someone who can actually
    // decide requests has a use for it.
    labelKey: 'adminNav.items.approvals',
    to: '/admin/approvals/queue',
    permission: ['approval-requests.view', 'leave-requests.view', 'resignation-requests.view', 'make-up-class-requests.view', 'exam-applications.view'],
    icon: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  },
]

/**
 * The Student role's own home page — this account never sees the grid
 * above (it holds none of those permissions by design, see
 * Permissions::defaultsForSystemRoles()). No `permission` on any of these:
 * they're the same identity-gated self-service pages already reachable from
 * adminNav.ts's "My Profile" group, just gathered here as the landing page
 * instead of scattered across the sidebar/tab bar.
 */
const studentQuickAccessItems: QuickAccessItem[] = [
  {
    labelKey: 'studentNav.score',
    to: '/admin/my-scores',
    icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
  },
  {
    labelKey: 'studentNav.attendant',
    to: '/admin/my-attendance',
    icon: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
  },
  {
    labelKey: 'studentNav.video',
    to: '/admin/my-videos',
    icon: 'M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z',
  },
  {
    labelKey: 'studentNav.myRequest',
    to: '/admin/my-feedback',
    icon: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
  },
  {
    labelKey: 'adminNav.items.myRequests',
    to: '/admin/approvals/my-requests',
    icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
  },
  {
    labelKey: 'adminNav.items.notifications',
    to: '/admin/notifications',
    icon: 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
  },
]

/**
 * The student's hour cards, one set per course they're studying. Hours left
 * above this turns that card red — the school's make-up threshold.
 */
const REMAINING_HOURS_ALERT = 6
const myHours = ref<MyAttendanceHoursRow[]>([])

async function loadMyHours() {
  try {
    myHours.value = await attendanceService.myHoursSummary()
  } catch {
    // Left empty — the cards simply don't render.
  }
}

function formatHours(hours: number): string {
  return t('admin.dashboard.hoursValue', { hours: Number.isInteger(hours) ? hours : hours.toFixed(1) })
}

/** Active enrollments right now — same "studying" definition as the Classes list's active-student count/filter, not Student.status. */
const studyingEnrollmentsCount = ref<string>('—')
const monthlyIncome = ref<string>('—')
const dailyIncome = ref<string>('—')
const monthlyExpense = ref<string>('—')
const dailyExpense = ref<string>('—')
const absentToday = ref<AttendanceRecord[]>([])
const absenceStatusVariant: Record<AttendanceStatusValue, 'success' | 'danger' | 'warning' | 'neutral'> = {
  PRESENT: 'success',
  ABSENT: 'danger',
  LATE: 'warning',
  EXCUSED: 'neutral',
}
function absenceStatusLabel(status: AttendanceStatusValue): string {
  return t(`admin.attendance.status${status.charAt(0)}${status.slice(1).toLowerCase()}`)
}
const absentYesterday = ref<AttendanceRecord[]>([])
const monthlyPaymentAlerts = ref<MonthlyInvoice[]>([])
/** Studying students billed by term past the school's day limit (School Settings). */
const termStudyAlerts = ref<TermStudyAlert[]>([])
const termStudyAlertDays = ref(31)

/**
 * Invoices not yet fully paid (Unpaid, Partially Paid, Overdue — the same set
 * as Invoice::scopeOutstanding), oldest due date first.
 */
const UNPAID_STATUSES = 'ISSUED,PARTIALLY_PAID,OVERDUE'
const UNPAID_LIMIT = 10
const unpaidInvoices = ref<Invoice[]>([])
const unpaidInvoicesTotal = ref(0)
const unpaidStatusVariant: Partial<Record<InvoiceStatusValue, 'primary' | 'warning' | 'danger'>> = {
  ISSUED: 'primary',
  PARTIALLY_PAID: 'warning',
  OVERDUE: 'danger',
}

function invoiceStatusLabel(status: InvoiceStatusValue): string {
  const key = status
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
  return t(`admin.invoices.status${key}`)
}

async function loadUnpaidInvoices(): Promise<void> {
  try {
    const result = await invoicesService.list({ page: 1, per_page: UNPAID_LIMIT, sort: 'due_date', filter: { status: UNPAID_STATUSES } })
    unpaidInvoices.value = result.data
    unpaidInvoicesTotal.value = result.pagination.type === 'length_aware' ? result.pagination.total : result.data.length
  } catch {
    // Left empty — most likely the signed-in admin just lacks invoices.view.
  }
}

let currency: 'USD' | 'KHR' = 'USD'

function money(amount: number): string {
  return formatMoney(amount, currency)
}

/**
 * The eye toggle: hides every money figure on this page (e.g. when the screen
 * is in view of students or parents). Remembered per user in this browser.
 */
const MASK = '••••••'
const showMoney = ref(true)

function moneyPrefKey(): string | null {
  return auth.user ? `dashboard.showMoney.${auth.user.id}` : null
}

function loadMoneyPref(): void {
  const key = moneyPrefKey()
  if (!key) return
  try {
    showMoney.value = localStorage.getItem(key) !== '0'
  } catch {
    // Storage blocked — keep the default (shown).
  }
}

function toggleMoney(): void {
  showMoney.value = !showMoney.value
  const key = moneyPrefKey()
  if (!key) return
  try {
    localStorage.setItem(key, showMoney.value ? '1' : '0')
  } catch {
    // Storage blocked — the choice just lasts until reload.
  }
}

/** A money figure, or the mask while amounts are hidden. */
function amount(value: number, cur: 'USD' | 'KHR'): string {
  return showMoney.value ? formatMoney(value, cur) : MASK
}

function maskedText(text: string): string {
  return showMoney.value || text === '—' ? text : MASK
}

const EYE_OPEN = 'M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178zM15 12a3 3 0 11-6 0 3 3 0 016 0z'
const EYE_CLOSED = 'M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88'

function firstOfMonth(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
}

function toDateString(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function today(): string {
  return toDateString(new Date())
}

function yesterday(): string {
  const date = new Date()
  date.setDate(date.getDate() - 1)
  return toDateString(date)
}

const incomeDetailOpen = ref(false)
const incomeDetail = ref<IncomeDetail | null>(null)
const incomeDetailLoading = ref(false)
const incomeDetailError = ref<string | null>(null)

/** Same range as the Monthly Income tile, so the rows add up to its figure. */
async function openIncomeDetail(): Promise<void> {
  incomeDetailOpen.value = true
  incomeDetailLoading.value = true
  incomeDetailError.value = null

  try {
    incomeDetail.value = await accountingReportsService.income({ date_from: firstOfMonth(), date_to: today() })
  } catch {
    incomeDetailError.value = t('admin.dashboard.incomeDetailLoadFailed')
  } finally {
    incomeDetailLoading.value = false
  }
}

/**
 * New (pending) requests across every queue the Approvals page shows that this
 * user can see — plus make-up classes approved to study, which still wait on
 * the approver to see the student come.
 */
const pendingApprovalsCount = ref(0)

async function loadPendingApprovals(): Promise<void> {
  const pending = { page: 1, per_page: 1, filter: { status: 'pending' } }
  const sources: [string, () => Promise<{ pagination?: { type?: string; total?: number } }>][] = [
    ['approval-requests.view', () => approvalRequestsService.list(pending)],
    ['leave-requests.view', () => leaveRequestsService.list(pending)],
    ['resignation-requests.view', () => resignationRequestsService.list(pending)],
    ['make-up-class-requests.view', () => makeUpClassRequestsService.list({ ...pending, filter: { status: 'pending,approved_to_study' } })],
    ['exam-applications.view', () => examApplicationsService.list(pending)],
  ]
  const results = await Promise.allSettled(sources.filter(([permission]) => auth.can(permission)).map(([, fetch]) => fetch()))
  pendingApprovalsCount.value = results.reduce((sum, r) => sum + (r.status === 'fulfilled' ? (r.value.pagination?.total ?? 0) : 0), 0)
}

async function loadStats(): Promise<void> {
  void loadPendingApprovals()

  if (auth.can('dashboard.finance.view')) {
    try {
      const result = await enrollmentsService.list({ page: 1, per_page: 1, filter: { status: 'active' } })
      if (result.pagination.type === 'length_aware') studyingEnrollmentsCount.value = String(result.pagination.total)
    } catch {
      // Left as '—' — most likely the signed-in admin just lacks enrollments.view.
    }

    try {
      const summary = await accountingReportsService.dashboard({ date_from: firstOfMonth(), date_to: today(), today: today() })
      currency = summary.currency
      monthlyIncome.value = money(summary.total_revenue)
      dailyIncome.value = money(summary.todays_income)
      monthlyExpense.value = money(summary.total_expenses)
      dailyExpense.value = money(summary.todays_expenses)
    } catch {
      // Left as '—' — most likely the signed-in admin just lacks accounting-dashboard.view.
    }
  }

  if (auth.can('dashboard.attendance.view')) {
    try {
      absentToday.value = await attendanceService.listAbsent(today())
      absentYesterday.value = await attendanceService.listAbsent(yesterday())
    } catch {
      // Left empty — most likely the signed-in admin just lacks attendance.view.
    }
  }

  if (auth.can('dashboard.payment-alerts.view')) {
    try {
      monthlyPaymentAlerts.value = await monthlyPaymentAlertsService.list()
    } catch {
      // Left empty — most likely the signed-in admin just lacks invoices.view.
    }
    try {
      const result = await termStudyAlertsService.list()
      termStudyAlerts.value = result.items
      termStudyAlertDays.value = result.days
    } catch {
      // Left empty, as above.
    }
    void loadUnpaidInvoices()
  }
}

/**
 * The dashboard is often left open — a tab overnight, or the phone's
 * home-screen app, which resumes rather than reloads — so its "today" would
 * keep showing the day it was opened. Load again on coming back to it once
 * the day has changed or it's been a while, and at midnight if left showing.
 */
const STALE_AFTER_MS = 5 * 60 * 1000
let loadedDay = ''
let loadedAt = 0

function loadAll(): void {
  loadedDay = today()
  loadedAt = Date.now()
  if (!auth.isSuperAdmin && !auth.hasRole('student')) void loadStats()
  if (auth.hasRole('student')) void loadMyHours()
}

function reloadIfStale(): void {
  if (document.visibilityState !== 'visible') return
  if (today() !== loadedDay || Date.now() - loadedAt > STALE_AFTER_MS) loadAll()
}

function reloadIfNewDay(): void {
  if (document.visibilityState === 'visible' && today() !== loadedDay) loadAll()
}

let dayTimer: ReturnType<typeof setInterval> | undefined

// auth.user can arrive after this page mounts (first load after sign-in).
watch(() => auth.user?.id, loadMoneyPref)

onMounted(() => {
  loadMoneyPref()
  loadAll()
  document.addEventListener('visibilitychange', reloadIfStale)
  window.addEventListener('focus', reloadIfStale)
  dayTimer = setInterval(reloadIfNewDay, 60 * 1000)
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', reloadIfStale)
  window.removeEventListener('focus', reloadIfStale)
  clearInterval(dayTimer)
})
</script>

<template>
  <div>
    <template v-if="auth.hasRole('student')">
      <div v-for="row in myHours" :key="row.enrollment_id" class="mb-6">
        <p v-if="myHours.length > 1" class="mb-2 text-sm font-semibold text-neutral-700">{{ makeUpClassCourseLabel(row) }}</p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div class="hidden rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] sm:block">
            <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.totalAbsentHours') }}</p>
            <p class="mt-1 text-2xl font-semibold text-neutral-900">{{ formatHours(row.absent_hours) }}</p>
            <p class="mt-1 text-xs text-neutral-400">{{ t('admin.dashboard.totalAbsentHoursHint') }}</p>
          </div>
          <div class="hidden rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] sm:block">
            <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.totalMakeUpHours') }}</p>
            <p class="mt-1 text-2xl font-semibold text-neutral-900">{{ formatHours(row.make_up_hours) }}</p>
            <p class="mt-1 text-xs text-neutral-400">{{ t('admin.dashboard.totalMakeUpHoursHint') }}</p>
          </div>
          <div
            class="rounded-[--radius-card] border p-4 shadow-[--shadow-card]"
            :class="row.remaining_hours > REMAINING_HOURS_ALERT ? 'border-danger-600 bg-danger-600 text-white' : 'border-neutral-200 bg-white'"
          >
            <p class="text-sm font-medium" :class="row.remaining_hours > REMAINING_HOURS_ALERT ? 'text-white/90' : 'text-neutral-500'">{{ t('admin.dashboard.totalHoursLeft') }}</p>
            <p class="mt-1 text-2xl font-semibold" :class="row.remaining_hours > REMAINING_HOURS_ALERT ? 'text-white' : 'text-neutral-900'">{{ formatHours(row.remaining_hours) }}</p>
            <p class="mt-1 text-xs" :class="row.remaining_hours > REMAINING_HOURS_ALERT ? 'text-white/80' : 'text-neutral-400'">{{ t('admin.dashboard.totalHoursLeftHint') }}</p>
            <p class="mt-1 text-xs font-medium" :class="row.remaining_hours > REMAINING_HOURS_ALERT ? 'text-white' : 'text-danger-600'">{{ t('admin.dashboard.totalHoursLeftNote') }}</p>
            <!-- Phones only: the two cards hidden above, as this card's detail. -->
            <dl
              class="mt-3 grid grid-cols-2 gap-3 border-t pt-3 sm:hidden"
              :class="row.remaining_hours > REMAINING_HOURS_ALERT ? 'border-white/30' : 'border-neutral-100'"
            >
              <div>
                <dt class="text-xs" :class="row.remaining_hours > REMAINING_HOURS_ALERT ? 'text-white/80' : 'text-neutral-500'">{{ t('admin.dashboard.totalAbsentHours') }}</dt>
                <dd class="mt-0.5 text-lg font-semibold" :class="row.remaining_hours > REMAINING_HOURS_ALERT ? 'text-white' : 'text-neutral-900'">{{ formatHours(row.absent_hours) }}</dd>
              </div>
              <div>
                <dt class="text-xs" :class="row.remaining_hours > REMAINING_HOURS_ALERT ? 'text-white/80' : 'text-neutral-500'">{{ t('admin.dashboard.totalMakeUpHours') }}</dt>
                <dd class="mt-0.5 text-lg font-semibold" :class="row.remaining_hours > REMAINING_HOURS_ALERT ? 'text-white' : 'text-neutral-900'">{{ formatHours(row.make_up_hours) }}</dd>
              </div>
            </dl>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <RouterLink
          v-for="item in studentQuickAccessItems"
          :key="item.to"
          :to="item.to"
          class="relative flex flex-col items-center gap-2 rounded-[--radius-card] border border-neutral-200 bg-white p-4 text-center shadow-[--shadow-card] transition-shadow hover:shadow-[--shadow-card-hover]"
        >
          <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-100 text-primary-700">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
            </svg>
          </span>
          <span class="text-xs font-medium text-neutral-700">{{ t(item.labelKey) }}</span>
        </RouterLink>
      </div>
    </template>

    <template v-else-if="!auth.isSuperAdmin">
      <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('admin.dashboard.quickAccess') }}</h2>
      <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <RouterLink
          v-for="item in quickAccessItems"
          v-show="canSee(item)"
          :key="item.to"
          :to="item.to"
          class="relative flex flex-col items-center gap-2 rounded-[--radius-card] border border-neutral-200 bg-white p-4 text-center shadow-[--shadow-card] transition-shadow hover:shadow-[--shadow-card-hover]"
        >
          <span
            v-if="item.to === '/admin/classes'"
            class="absolute right-2 top-2 rounded-full bg-primary-600 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white"
            :title="t('admin.dashboard.classesStudyingTooltip')"
          >
            {{ studyingEnrollmentsCount }}
          </span>
          <span
            v-if="item.to === '/admin/approvals/queue' && pendingApprovalsCount > 0"
            class="absolute right-2 top-2 rounded-full bg-danger-600 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white"
            :title="t('admin.approvals.tabNew')"
          >
            {{ pendingApprovalsCount }}
          </span>
          <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-100 text-primary-700">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
            </svg>
          </span>
          <span class="text-xs font-medium text-neutral-700">{{ t(item.labelKey) }}</span>
        </RouterLink>
      </div>

      <template v-if="auth.can('dashboard.attendance.view')">
        <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('admin.dashboard.attendanceSection') }}</h2>
        <div class="mt-3 grid grid-cols-1 gap-4 lg:grid-cols-2">
          <BaseCard>
            <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.statAbsentToday') }}</p>
            <p v-if="absentToday.length === 0" class="mt-2 text-sm text-neutral-400">{{ t('admin.dashboard.absentEmptyMessage') }}</p>
            <div v-else class="mt-2 overflow-x-auto">
              <table class="w-full text-left text-sm">
                <thead class="text-neutral-500">
                  <tr>
                    <th class="py-1.5 pr-4 font-medium">{{ t('admin.attendance.columnStudent') }}</th>
                    <th class="py-1.5 pr-4 font-medium">{{ t('admin.attendance.columnClass') }}</th>
                    <th class="py-1.5 pr-4 font-medium">{{ t('admin.attendance.columnStatus') }}</th>
                    <th class="py-1.5 pr-4 font-medium">{{ t('admin.attendance.columnRemarks') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="record in absentToday" :key="record.id">
                    <td class="py-1.5 pr-4 text-neutral-800">{{ record.student?.name ?? '—' }}</td>
                    <td class="py-1.5 pr-4 text-neutral-700">{{ record.class?.name ?? '—' }}</td>
                    <td class="py-1.5 pr-4">
                      <BaseBadge :variant="absenceStatusVariant[record.status]">{{ absenceStatusLabel(record.status) }}</BaseBadge>
                    </td>
                    <td class="py-1.5 pr-4 text-neutral-700">{{ record.remarks ?? '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </BaseCard>
          <BaseCard>
            <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.statAbsentYesterday') }}</p>
            <p v-if="absentYesterday.length === 0" class="mt-2 text-sm text-neutral-400">{{ t('admin.dashboard.absentEmptyMessage') }}</p>
            <div v-else class="mt-2 overflow-x-auto">
              <table class="w-full text-left text-sm">
                <thead class="text-neutral-500">
                  <tr>
                    <th class="py-1.5 pr-4 font-medium">{{ t('admin.attendance.columnStudent') }}</th>
                    <th class="py-1.5 pr-4 font-medium">{{ t('admin.attendance.columnClass') }}</th>
                    <th class="py-1.5 pr-4 font-medium">{{ t('admin.attendance.columnStatus') }}</th>
                    <th class="py-1.5 pr-4 font-medium">{{ t('admin.attendance.columnRemarks') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="record in absentYesterday" :key="record.id">
                    <td class="py-1.5 pr-4 text-neutral-800">{{ record.student?.name ?? '—' }}</td>
                    <td class="py-1.5 pr-4 text-neutral-700">{{ record.class?.name ?? '—' }}</td>
                    <td class="py-1.5 pr-4">
                      <BaseBadge :variant="absenceStatusVariant[record.status]">{{ absenceStatusLabel(record.status) }}</BaseBadge>
                    </td>
                    <td class="py-1.5 pr-4 text-neutral-700">{{ record.remarks ?? '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </BaseCard>
        </div>
      </template>

      <template v-if="auth.can('dashboard.payment-alerts.view') && monthlyPaymentAlerts.length > 0">
        <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('admin.dashboard.monthlyPaymentAlertsSection') }}</h2>
        <BaseCard class="mt-3">
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
              <thead class="text-neutral-500">
                <tr>
                  <th class="py-1.5 pr-4 font-medium">{{ t('admin.invoices.columnStudent') }}</th>
                  <th class="py-1.5 pr-4 font-medium">{{ t('admin.invoices.monthlyColumnCourse') }}</th>
                  <th class="py-1.5 pr-4 font-medium">{{ t('admin.invoices.monthlyColumnNextPayment') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in monthlyPaymentAlerts" :key="row.id">
                  <td class="py-1.5 pr-4 text-neutral-800">{{ row.student?.name ?? '—' }}</td>
                  <td class="py-1.5 pr-4 text-neutral-700">{{ row.course ?? '—' }}</td>
                  <td class="py-1.5 pr-4 font-medium text-danger-600">{{ formatDate(row.next_payment_date) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <RouterLink to="/admin/invoices/monthly" class="mt-3 inline-block text-sm font-medium text-primary-700 hover:text-primary-800">
            {{ t('admin.dashboard.monthlyPaymentAlertsViewAll') }}
          </RouterLink>
        </BaseCard>
      </template>

      <template v-if="auth.can('dashboard.payment-alerts.view') && termStudyAlerts.length > 0">
        <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          {{ t('admin.dashboard.termStudyAlertsSection', { days: termStudyAlertDays }) }} ({{ termStudyAlerts.length }})
        </h2>
        <BaseCard class="mt-3">
          <!-- Phones: cards. -->
          <ul class="divide-y divide-neutral-100 sm:hidden">
            <li v-for="row in termStudyAlerts" :key="row.id" class="py-2">
              <div class="flex items-center justify-between gap-2">
                <p class="font-medium text-neutral-800">{{ row.student?.name ?? '—' }}<span v-if="!row.type_recorded" class="ml-1 rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] font-medium text-neutral-500" :title="t('admin.dashboard.termStudyAlertsTypeUnknownHint')">{{ t('admin.dashboard.termStudyAlertsTypeUnknown') }}</span></p>
                <span class="whitespace-nowrap text-sm font-semibold text-danger-600">{{ t('admin.dashboard.termStudyAlertsDays', { n: row.days ?? 0 }) }}</span>
              </div>
              <p class="text-xs text-neutral-500">{{ [row.student?.student_code, row.course, row.class].filter(Boolean).join(' · ') }}</p>
              <p class="mt-1 text-xs text-neutral-500">{{ t('admin.dashboard.termStudyAlertsColumnEnrolled') }}: {{ formatDate(row.enrolled_at) }}</p>
            </li>
          </ul>
          <div class="hidden overflow-x-auto sm:block">
            <table class="w-full text-left text-sm">
              <thead class="text-neutral-500">
                <tr>
                  <th class="py-1.5 pr-4 font-medium">{{ t('admin.invoices.columnStudent') }}</th>
                  <th class="py-1.5 pr-4 font-medium">{{ t('admin.invoices.monthlyColumnCourse') }}</th>
                  <th class="py-1.5 pr-4 font-medium">{{ t('admin.dashboard.termStudyAlertsColumnClass') }}</th>
                  <th class="py-1.5 pr-4 font-medium">{{ t('admin.dashboard.termStudyAlertsColumnEnrolled') }}</th>
                  <th class="py-1.5 pr-4 font-medium">{{ t('admin.dashboard.termStudyAlertsColumnDays') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in termStudyAlerts" :key="row.id">
                  <td class="py-1.5 pr-4 text-neutral-800">
                    {{ row.student?.name ?? '—' }}
                    <span v-if="row.student?.student_code" class="text-xs text-neutral-500">({{ row.student.student_code }})</span>
                    <span v-if="!row.type_recorded" class="ml-1 rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] font-medium text-neutral-500" :title="t('admin.dashboard.termStudyAlertsTypeUnknownHint')">{{ t('admin.dashboard.termStudyAlertsTypeUnknown') }}</span>
                  </td>
                  <td class="py-1.5 pr-4 text-neutral-700">{{ row.course ?? '—' }}</td>
                  <td class="py-1.5 pr-4 text-neutral-700">{{ row.class ?? '—' }}</td>
                  <td class="whitespace-nowrap py-1.5 pr-4 text-neutral-700">{{ formatDate(row.enrolled_at) }}</td>
                  <td class="whitespace-nowrap py-1.5 pr-4 font-medium text-danger-600">{{ row.days ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
      </template>

      <template v-if="auth.can('dashboard.payment-alerts.view') && unpaidInvoices.length > 0">
        <div class="mt-8 flex items-center gap-2">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
            {{ t('admin.dashboard.unpaidInvoicesSection') }} ({{ unpaidInvoicesTotal }})
          </h2>
          <button
            type="button"
            class="rounded-full p-1 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            :title="showMoney ? t('admin.dashboard.hideAmounts') : t('admin.dashboard.showAmounts')"
            :aria-label="showMoney ? t('admin.dashboard.hideAmounts') : t('admin.dashboard.showAmounts')"
            :aria-pressed="!showMoney"
            @click="toggleMoney"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" :d="showMoney ? EYE_OPEN : EYE_CLOSED" />
            </svg>
          </button>
        </div>
        <BaseCard class="mt-3">
          <!-- Phones: cards. -->
          <ul class="divide-y divide-neutral-100 sm:hidden">
            <li v-for="row in unpaidInvoices" :key="row.id" class="py-2">
              <RouterLink :to="`/admin/invoices/${row.id}`" class="block">
                <div class="flex items-center justify-between gap-2">
                  <p class="font-medium text-neutral-800">{{ row.student?.name ?? '—' }}</p>
                  <BaseBadge :variant="unpaidStatusVariant[row.status] ?? 'primary'">{{ invoiceStatusLabel(row.status) }}</BaseBadge>
                </div>
                <p class="text-xs text-neutral-500">{{ [row.invoice_number, row.course, formatDate(row.due_date)].filter(Boolean).join(' · ') }}</p>
                <p class="mt-1 text-sm">
                  <span class="text-neutral-500">{{ t('admin.invoices.columnBalance') }}:</span>
                  <span class="font-semibold text-danger-600"> {{ amount(row.balance, row.currency) }}</span>
                  <span class="text-neutral-400"> / {{ amount(row.total, row.currency) }}</span>
                </p>
              </RouterLink>
            </li>
          </ul>
          <div class="hidden overflow-x-auto sm:block">
            <table class="w-full text-left text-sm">
              <thead class="text-neutral-500">
                <tr>
                  <th class="py-1.5 pr-4 font-medium">{{ t('admin.invoices.columnStudent') }}</th>
                  <th class="py-1.5 pr-4 font-medium">{{ t('admin.invoices.columnNumber') }}</th>
                  <th class="py-1.5 pr-4 font-medium">{{ t('admin.invoices.monthlyColumnCourse') }}</th>
                  <th class="py-1.5 pr-4 text-right font-medium">{{ t('admin.invoices.columnTotal') }}</th>
                  <th class="py-1.5 pr-4 text-right font-medium">{{ t('admin.dashboard.unpaidInvoicesColumnPaid') }}</th>
                  <th class="py-1.5 pr-4 text-right font-medium">{{ t('admin.invoices.columnBalance') }}</th>
                  <th class="py-1.5 pr-4 font-medium">{{ t('admin.invoices.columnStatus') }}</th>
                  <th class="py-1.5 pr-4 font-medium">{{ t('admin.invoices.columnDueDate') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in unpaidInvoices" :key="row.id">
                  <td class="py-1.5 pr-4 text-neutral-800">{{ row.student?.name ?? '—' }}</td>
                  <td class="py-1.5 pr-4">
                    <RouterLink :to="`/admin/invoices/${row.id}`" class="font-medium text-primary-700 hover:text-primary-800">{{ row.invoice_number }}</RouterLink>
                  </td>
                  <td class="py-1.5 pr-4 text-neutral-700">{{ row.course ?? '—' }}</td>
                  <td class="whitespace-nowrap py-1.5 pr-4 text-right text-neutral-700">{{ amount(row.total, row.currency) }}</td>
                  <td class="whitespace-nowrap py-1.5 pr-4 text-right text-neutral-700">{{ amount(row.paid_amount, row.currency) }}</td>
                  <td class="whitespace-nowrap py-1.5 pr-4 text-right font-semibold text-danger-600">{{ amount(row.balance, row.currency) }}</td>
                  <td class="py-1.5 pr-4">
                    <BaseBadge :variant="unpaidStatusVariant[row.status] ?? 'primary'">{{ invoiceStatusLabel(row.status) }}</BaseBadge>
                  </td>
                  <td class="whitespace-nowrap py-1.5 pr-4 text-neutral-700">{{ formatDate(row.due_date) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="unpaidInvoicesTotal > unpaidInvoices.length" class="mt-2 text-xs text-neutral-400">
            {{ t('admin.dashboard.unpaidInvoicesShowing', { shown: unpaidInvoices.length, total: unpaidInvoicesTotal }) }}
          </p>
          <RouterLink to="/admin/invoices" class="mt-3 inline-block text-sm font-medium text-primary-700 hover:text-primary-800">
            {{ t('admin.dashboard.unpaidInvoicesViewAll') }}
          </RouterLink>
        </BaseCard>
      </template>

      <template v-if="auth.can('dashboard.finance.view')">
        <div class="mt-8 flex items-center gap-2">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('admin.dashboard.title') }}</h2>
          <button
            type="button"
            class="rounded-full p-1 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            :title="showMoney ? t('admin.dashboard.hideAmounts') : t('admin.dashboard.showAmounts')"
            :aria-label="showMoney ? t('admin.dashboard.hideAmounts') : t('admin.dashboard.showAmounts')"
            :aria-pressed="!showMoney"
            @click="toggleMoney"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" :d="showMoney ? EYE_OPEN : EYE_CLOSED" />
            </svg>
          </button>
        </div>
        <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <!-- Counts active enrollments, so the number matches the list it opens. -->
          <RouterLink
            to="/admin/enrollments?status=active"
            class="rounded-[--radius-card] transition hover:ring-2 hover:ring-primary-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
          >
            <BaseCard class="h-full">
              <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.statStudentsStudying') }}</p>
              <p class="mt-1 text-3xl font-bold text-neutral-900">{{ studyingEnrollmentsCount }}</p>
            </BaseCard>
          </RouterLink>
          <button type="button" class="rounded-[--radius-card] text-left transition hover:ring-2 hover:ring-primary-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500" @click="openIncomeDetail">
            <BaseCard class="h-full">
              <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.statMonthlyIncome') }}</p>
              <p class="mt-1 text-3xl font-bold text-neutral-900">{{ maskedText(monthlyIncome) }}</p>
            </BaseCard>
          </button>
          <BaseCard>
            <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.statDailyIncome') }}</p>
            <p class="mt-1 text-3xl font-bold text-neutral-900">{{ maskedText(dailyIncome) }}</p>
          </BaseCard>
          <BaseCard>
            <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.statMonthlyExpense') }}</p>
            <p class="mt-1 text-3xl font-bold text-neutral-900">{{ maskedText(monthlyExpense) }}</p>
          </BaseCard>
          <BaseCard>
            <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.statDailyExpense') }}</p>
            <p class="mt-1 text-3xl font-bold text-neutral-900">{{ maskedText(dailyExpense) }}</p>
          </BaseCard>
        </div>
      </template>
    </template>

    <BaseModal v-model="incomeDetailOpen" :title="t('admin.dashboard.incomeDetailTitle')" size="lg">
      <p v-if="incomeDetailLoading" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <p v-else-if="incomeDetailError" class="py-6 text-center text-sm text-danger-600">{{ incomeDetailError }}</p>
      <p v-else-if="incomeDetail && incomeDetail.items.length === 0" class="py-6 text-center text-sm text-neutral-500">
        {{ t('admin.dashboard.incomeDetailEmpty') }}
      </p>
      <div v-else-if="incomeDetail" class="max-h-[60vh] overflow-auto">
        <table class="min-w-full text-sm">
          <thead class="sticky top-0 bg-neutral-50 text-left text-xs font-semibold uppercase tracking-wide text-neutral-500">
            <tr>
              <th class="px-3 py-2">{{ t('admin.dashboard.incomeDetailDate') }}</th>
              <th class="px-3 py-2">{{ t('admin.dashboard.incomeDetailDescription') }}</th>
              <th class="px-3 py-2 text-right">{{ t('admin.dashboard.incomeDetailAmount') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="item in incomeDetail.items" :key="item.id">
              <td class="whitespace-nowrap px-3 py-2 text-neutral-700">{{ formatDate(item.date) }}</td>
              <td class="px-3 py-2 text-neutral-700">{{ item.description ?? '—' }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right font-medium" :class="item.amount < 0 ? 'text-danger-600' : 'text-neutral-900'">
                {{ showMoney && item.amount < 0 ? '-' : '' }}{{ amount(Math.abs(item.amount), item.currency) }}
              </td>
            </tr>
          </tbody>
          <tfoot class="sticky bottom-0 bg-white">
            <tr class="border-t border-neutral-200 font-semibold text-neutral-900">
              <td class="px-3 py-2" colspan="2">{{ t('admin.dashboard.incomeDetailTotal') }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right">{{ amount(incomeDetail.total, incomeDetail.currency) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </BaseModal>
  </div>
</template>
