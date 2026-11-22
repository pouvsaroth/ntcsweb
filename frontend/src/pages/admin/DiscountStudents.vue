<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import InvoicesTabs from '@/components/admin/InvoicesTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { studentsWithDiscountService, type StudentWithDiscount } from '@/services/discountCodes'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Invoices > Students with discount: every student holding a discount code
 * they can use today (on, started, not expired) — what it gives, when it
 * ends and how often they used it. Codes are given on the Discount codes tab.
 */
const { t } = useI18n()
const auth = useAuthStore()

const rows = ref<StudentWithDiscount[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const search = ref('')
const mainCode = ref('')

/** Ends within this many days: shown as "Ends soon". */
const SOON_DAYS = 7

const mainOptions = computed(() => {
  const seen = new Map<string, string>()
  for (const row of rows.value) seen.set(String(row.code.main_id), `${row.code.main_code}${row.code.name ? ` — ${row.code.name}` : ''}`)
  return [...seen.entries()].sort((a, b) => a[1].localeCompare(b[1])).map(([value, label]) => ({ value, label }))
})

const shown = computed(() => {
  const q = search.value.trim().toLowerCase()
  return rows.value.filter((row) => {
    if (mainCode.value && String(row.code.main_id) !== mainCode.value) return false
    if (!q) return true
    return [row.student.name, row.student.student_code, row.student.phone, row.code.code, row.code.name].some((v) => (v ?? '').toLowerCase().includes(q))
  })
})
const studentCount = computed(() => new Set(shown.value.map((r) => r.student.id)).size)

// The code's own currency; one without falls back to the school's default.
const currencyOf = (row: StudentWithDiscount): 'USD' | 'KHR' => {
  const currency = row.code.currency ?? auth.tenantDefaultCurrency
  return currency === 'KHR' ? 'KHR' : 'USD'
}

/** "15% (max $20.00)" or "$10.00", in the code's own currency. */
function discountText(row: StudentWithDiscount): string {
  const c = row.code
  if (c.discount_type === 'percent') {
    const cap = c.max_discount !== null ? ` ${t('admin.discountStudents.max', { amount: formatMoney(c.max_discount, currencyOf(row)) })}` : ''
    return `${Number(c.value)}%${cap}`
  }
  return formatMoney(c.value, currencyOf(row))
}

function endsSoon(row: StudentWithDiscount): boolean {
  if (!row.code.expires_on) return false
  const days = (new Date(`${row.code.expires_on}T00:00:00`).getTime() - new Date().setHours(0, 0, 0, 0)) / 86_400_000
  return days <= SOON_DAYS
}

const coursesText = (row: StudentWithDiscount) => row.courses.map((c) => [c.course, c.class].filter(Boolean).join(' · ')).join(', ') || '—'

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = (await studentsWithDiscountService.list()).rows
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.discountStudents.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <InvoicesTabs />

    <div class="mb-4">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.discountStudents.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.discountStudents.subtitle') }}</p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end">
      <div class="min-w-0 flex-1">
        <BaseInput v-model="search" type="search" :label="t('admin.discountStudents.search')" :placeholder="t('admin.discountStudents.searchPlaceholder')" />
      </div>
      <div class="sm:w-72">
        <BaseSelect v-model="mainCode" :options="mainOptions" :placeholder="t('admin.discountStudents.allCodes')" :label="t('admin.discountStudents.code')" />
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <EmptyState v-else-if="rows.length === 0" :title="t('admin.discountStudents.emptyTitle')" :message="t('admin.discountStudents.emptyMessage')" />

    <template v-else>
      <p class="mb-3 text-sm text-neutral-600">{{ t('admin.discountStudents.count', { students: studentCount, codes: shown.length }) }}</p>
      <p v-if="shown.length === 0" class="rounded-lg bg-neutral-50 px-3 py-6 text-center text-sm text-neutral-500">{{ t('admin.discountStudents.noMatch') }}</p>

      <!-- Phone: one card per student and code. -->
      <div class="space-y-3 sm:hidden">
        <div v-for="row in shown" :key="row.key" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-neutral-900">{{ row.student.name }}</p>
              <p class="text-xs text-neutral-500">{{ row.student.student_code }}<template v-if="row.student.phone"> · {{ row.student.phone }}</template></p>
            </div>
            <p class="shrink-0 text-right text-sm font-semibold text-green-700">{{ discountText(row) }}</p>
          </div>
          <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-sm">
            <dt class="text-neutral-500">{{ t('admin.discountStudents.code') }}</dt>
            <dd class="break-all text-neutral-800">{{ row.code.code }}<span v-if="row.code.name" class="text-neutral-500"> · {{ row.code.name }}</span></dd>
            <dt class="text-neutral-500">{{ t('admin.discountStudents.studying') }}</dt>
            <dd class="text-neutral-800">{{ coursesText(row) }}</dd>
            <dt class="text-neutral-500">{{ t('admin.discountStudents.ends') }}</dt>
            <dd class="text-neutral-800">
              {{ row.code.expires_on ? formatDate(row.code.expires_on) : t('admin.discountStudents.noEnd') }}
              <BaseBadge v-if="endsSoon(row)" variant="warning" class="ml-1">{{ t('admin.discountStudents.endsSoon') }}</BaseBadge>
            </dd>
            <dt class="text-neutral-500">{{ t('admin.discountStudents.used') }}</dt>
            <dd class="text-neutral-800">{{ t('admin.discountStudents.times', { n: row.uses }) }}<template v-if="row.last_used_at"> · {{ formatDate(row.last_used_at) }}</template></dd>
          </dl>
        </div>
      </div>

      <!-- Wider screens: table. -->
      <div v-if="shown.length" class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card] sm:block">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
          <thead class="bg-neutral-50 text-left text-xs font-semibold uppercase tracking-wide text-neutral-500">
            <tr>
              <th class="px-3 py-3">{{ t('admin.discountStudents.student') }}</th>
              <th class="px-3 py-3">{{ t('admin.discountStudents.studying') }}</th>
              <th class="px-3 py-3">{{ t('admin.discountStudents.code') }}</th>
              <th class="px-3 py-3 text-right">{{ t('admin.discountStudents.discount') }}</th>
              <th class="px-3 py-3">{{ t('admin.discountStudents.ends') }}</th>
              <th class="px-3 py-3">{{ t('admin.discountStudents.used') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in shown" :key="row.key">
              <td class="px-3 py-2">
                <p class="font-medium text-neutral-900">{{ row.student.name }}</p>
                <p class="text-xs text-neutral-500">{{ row.student.student_code }}<template v-if="row.student.phone"> · {{ row.student.phone }}</template></p>
              </td>
              <td class="px-3 py-2 text-neutral-800">{{ coursesText(row) }}</td>
              <td class="px-3 py-2">
                <p class="font-mono text-xs text-neutral-800">{{ row.code.code }}</p>
                <p v-if="row.code.name" class="text-xs text-neutral-500">{{ row.code.name }}</p>
              </td>
              <td class="whitespace-nowrap px-3 py-2 text-right font-semibold text-green-700">{{ discountText(row) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-neutral-800">
                {{ row.code.expires_on ? formatDate(row.code.expires_on) : t('admin.discountStudents.noEnd') }}
                <BaseBadge v-if="endsSoon(row)" variant="warning" class="ml-1">{{ t('admin.discountStudents.endsSoon') }}</BaseBadge>
              </td>
              <td class="whitespace-nowrap px-3 py-2 text-neutral-800">
                {{ t('admin.discountStudents.times', { n: row.uses }) }}
                <p v-if="row.last_used_at" class="text-xs text-neutral-500">{{ formatDate(row.last_used_at) }}</p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
