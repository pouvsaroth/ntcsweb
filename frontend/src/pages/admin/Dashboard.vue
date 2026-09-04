<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import BaseCard from '@/components/ui/BaseCard.vue'
import { accountingReportsService } from '@/services/accounting'
import { studentsService } from '@/services/students'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const { t } = useI18n()

interface QuickAccessItem {
  labelKey: string
  to: string
  permission?: string
  icon: string
}

/** Every icon is a single Heroicons outline path — kept inline so this tile grid has no extra dependency. */
const quickAccessItems: QuickAccessItem[] = [
  {
    labelKey: 'admin.dashboard.registerStudent',
    to: '/admin/students/new',
    permission: 'students.create',
    icon: 'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z',
  },
  {
    labelKey: 'admin.dashboard.enrollment',
    to: '/admin/enrollments/new',
    permission: 'enrollments.create',
    icon: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z',
  },
  {
    labelKey: 'admin.dashboard.studentPayment',
    to: '/admin/payments',
    permission: 'payments.view',
    icon: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z',
  },
  {
    labelKey: 'admin.dashboard.studentAttendance',
    to: '/admin/attendance',
    permission: 'attendance.view',
    icon: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
  },
  {
    labelKey: 'admin.dashboard.teacherAttendance',
    to: '/admin/staff',
    permission: 'staff.view',
    icon: 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
  },
  {
    labelKey: 'admin.dashboard.registrationPending',
    to: '/admin/student-registrations',
    permission: 'students.approve-registration',
    icon: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
  },
]

const studyingCount = ref<string>('—')
const monthlyIncome = ref<string>('—')
const dailyIncome = ref<string>('—')
const monthlyExpense = ref<string>('—')
const dailyExpense = ref<string>('—')

function money(amount: number): string {
  return `$${amount.toFixed(2)}`
}

function firstOfMonth(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
}

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

async function loadStats(): Promise<void> {
  try {
    const result = await studentsService.list({ per_page: 1, filter: { status: 'active' } })
    if (result.pagination.type === 'length_aware') studyingCount.value = String(result.pagination.total)
  } catch {
    // Left as '—' — most likely the signed-in admin just lacks students.view.
  }

  try {
    const summary = await accountingReportsService.dashboard({ date_from: firstOfMonth(), date_to: today() })
    monthlyIncome.value = money(summary.total_revenue)
    dailyIncome.value = money(summary.todays_income)
    monthlyExpense.value = money(summary.total_expenses)
    dailyExpense.value = money(summary.todays_expenses)
  } catch {
    // Left as '—' — most likely the signed-in admin just lacks accounting-dashboard.view.
  }
}

onMounted(() => {
  if (!auth.isSuperAdmin) void loadStats()
})
</script>

<template>
  <div>
    <h1 class="text-xl font-semibold text-neutral-900">
      {{ t('admin.dashboard.welcomeBack', { name: auth.user?.name ?? '' }) }}
    </h1>
    <p class="mt-1 text-sm text-neutral-500">
      {{ auth.isSuperAdmin ? t('admin.dashboard.platformAdministration') : auth.tenantName }}
    </p>

    <template v-if="!auth.isSuperAdmin">
      <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('admin.dashboard.quickAccess') }}</h2>
      <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <RouterLink
          v-for="item in quickAccessItems"
          v-show="!item.permission || auth.can(item.permission)"
          :key="item.to"
          :to="item.to"
          class="flex flex-col items-center gap-2 rounded-[--radius-card] border border-neutral-200 bg-white p-4 text-center shadow-[--shadow-card] transition-shadow hover:shadow-[--shadow-card-hover]"
        >
          <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-100 text-primary-700">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
            </svg>
          </span>
          <span class="text-xs font-medium text-neutral-700">{{ t(item.labelKey) }}</span>
        </RouterLink>
      </div>

      <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('admin.dashboard.title') }}</h2>
      <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <BaseCard>
          <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.statStudentsStudying') }}</p>
          <p class="mt-1 text-3xl font-bold text-neutral-900">{{ studyingCount }}</p>
        </BaseCard>
        <BaseCard>
          <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.statMonthlyIncome') }}</p>
          <p class="mt-1 text-3xl font-bold text-neutral-900">{{ monthlyIncome }}</p>
        </BaseCard>
        <BaseCard>
          <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.statDailyIncome') }}</p>
          <p class="mt-1 text-3xl font-bold text-neutral-900">{{ dailyIncome }}</p>
        </BaseCard>
        <BaseCard>
          <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.statMonthlyExpense') }}</p>
          <p class="mt-1 text-3xl font-bold text-neutral-900">{{ monthlyExpense }}</p>
        </BaseCard>
        <BaseCard>
          <p class="text-sm font-medium text-neutral-500">{{ t('admin.dashboard.statDailyExpense') }}</p>
          <p class="mt-1 text-3xl font-bold text-neutral-900">{{ dailyExpense }}</p>
        </BaseCard>
      </div>
    </template>
  </div>
</template>
