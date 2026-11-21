<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import InvoicesTabs from '@/components/admin/InvoicesTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DateInput from '@/components/ui/DateInput.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { discountFollowUpsService, type DiscountFollowUpCode, type DiscountFollowUpRow } from '@/services/discountCodes'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * Invoices > Discount codes > Students to add discount: students whose
 * course just turned Completed. Like entering scores: pick a code (and an
 * optional expiry) on each row, then Save once. Rows left without a code
 * stay on the list; Skip takes the ticked ones off with no discount.
 */
const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

interface Row extends DiscountFollowUpRow {
  codeId: string
  expiresOn: string
  ticked: boolean
}

const rows = ref<Row[]>([])
const codes = ref<DiscountFollowUpCode[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const success = ref<string | null>(null)

const codeOptions = computed(() => codes.value.map((c) => ({ value: String(c.id), label: codeLabel(c) })))

/** "DONE10 — Graduation (10%)" */
function codeLabel(c: DiscountFollowUpCode): string {
  const amount = c.discount_type === 'percent' ? `${Number(c.value)}%` : `${Number(c.value)} ${c.currency ?? ''}`.trim()
  return `${c.code}${c.name ? ` — ${c.name}` : ''} (${amount})`
}

function codeFor(row: Row): DiscountFollowUpCode | undefined {
  return codes.value.find((c) => String(c.id) === row.codeId)
}

/** A newly picked code fills in its own end date, which the row can still change. */
function pickCode(row: Row, value: string) {
  row.codeId = value
  row.expiresOn = codeFor(row)?.expires_on ?? ''
}

// Fill every row at once, like the gradebook's fill-down.
const sameCode = ref('')
const sameExpiry = ref('')
function applyToAll() {
  for (const row of rows.value) {
    if (sameCode.value) pickCode(row, sameCode.value)
    if (sameExpiry.value) row.expiresOn = sameExpiry.value
  }
}

const picked = computed(() => rows.value.filter((r) => r.codeId))
const ticked = computed(() => rows.value.filter((r) => r.ticked))
const allTicked = computed({
  get: () => rows.value.length > 0 && rows.value.every((r) => r.ticked),
  set: (value: boolean) => rows.value.forEach((r) => (r.ticked = value)),
})

async function load() {
  loading.value = true
  error.value = null
  try {
    const result = await discountFollowUpsService.list()
    codes.value = result.codes
    rows.value = result.rows.map((r) => ({ ...r, codeId: '', expiresOn: '', ticked: false }))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.discountFollowUps.loadFailed')
  } finally {
    loading.value = false
  }
}

async function save() {
  if (picked.value.length === 0) return
  saving.value = true
  error.value = null
  success.value = null
  try {
    const result = await discountFollowUpsService.save(
      picked.value.map((r) => ({ enrollment_id: r.enrollment_id, discount_code_id: Number(r.codeId), expires_on: r.expiresOn || null })),
    )
    success.value = t('admin.discountFollowUps.saved', { count: result.given })
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.discountFollowUps.saveFailed')
  } finally {
    saving.value = false
  }
}

async function skip() {
  if (ticked.value.length === 0) return
  if (!(await confirmDialog.confirm({ message: t('admin.discountFollowUps.skipConfirm', { count: ticked.value.length }) }))) return
  saving.value = true
  error.value = null
  success.value = null
  try {
    const result = await discountFollowUpsService.skip(ticked.value.map((r) => r.enrollment_id))
    success.value = t('admin.discountFollowUps.skipped', { count: result.skipped })
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.discountFollowUps.saveFailed')
  } finally {
    saving.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <div>
    <InvoicesTabs />

    <div class="mb-4">
      <RouterLink to="/admin/invoices/discount-codes" class="text-sm font-medium text-primary-700 hover:underline">← {{ t('admin.discountFollowUps.back') }}</RouterLink>
      <h1 class="mt-2 text-xl font-semibold text-neutral-900">{{ t('admin.discountFollowUps.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.discountFollowUps.subtitle') }}</p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="success" variant="success" class="mb-4">{{ success }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <EmptyState v-else-if="rows.length === 0" :title="t('admin.discountFollowUps.emptyTitle')" :message="t('admin.discountFollowUps.emptyMessage')" />

    <template v-else>
      <BaseAlert v-if="codes.length === 0" variant="warning" class="mb-4">{{ t('admin.discountFollowUps.noCodes') }}</BaseAlert>

      <!-- Same code / expiry for every row. -->
      <div class="mb-4 flex flex-col gap-2 rounded-lg bg-neutral-50 p-3 sm:flex-row sm:items-end">
        <div class="min-w-0 flex-1">
          <BaseSelect v-model="sameCode" :options="codeOptions" :placeholder="t('admin.discountFollowUps.chooseCode')" :label="t('admin.discountFollowUps.sameCode')" />
        </div>
        <div class="sm:w-48">
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.discountFollowUps.sameExpiry') }}</label>
          <DateInput v-model="sameExpiry" />
        </div>
        <BaseButton variant="outline" :disabled="!sameCode && !sameExpiry" @click="applyToAll">{{ t('admin.discountFollowUps.applyToAll') }}</BaseButton>
      </div>

      <!-- Phone: one card per student. -->
      <div class="space-y-3 sm:hidden">
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="allTicked" type="checkbox" class="h-4 w-4 rounded border-neutral-300" />
          {{ t('admin.discountFollowUps.selectAll') }}
        </label>
        <div v-for="row in rows" :key="row.enrollment_id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <div class="flex items-start gap-3">
            <input v-model="row.ticked" type="checkbox" class="mt-1 h-4 w-4 rounded border-neutral-300" :aria-label="row.student?.name ?? ''" />
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-neutral-900">{{ row.student?.name ?? '—' }}</p>
              <p class="text-xs text-neutral-500">{{ row.student?.student_code }}</p>
            </div>
          </div>
          <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-sm">
            <dt class="text-neutral-500">{{ t('admin.discountFollowUps.lastCourse') }}</dt>
            <dd class="text-neutral-800">{{ row.course ?? '—' }}<template v-if="row.class"> · {{ row.class }}</template></dd>
            <dt class="text-neutral-500">{{ t('admin.discountFollowUps.startStudy') }}</dt>
            <dd class="text-neutral-800">{{ formatDate(row.start_study_on) }}</dd>
            <dt class="text-neutral-500">{{ t('admin.discountFollowUps.finished') }}</dt>
            <dd class="text-neutral-800">{{ formatDate(row.completed_on) }}</dd>
          </dl>
          <div class="mt-3 space-y-2 border-t border-neutral-100 pt-3">
            <BaseSelect :model-value="row.codeId" :options="codeOptions" :placeholder="t('admin.discountFollowUps.chooseCode')" :label="t('admin.discountFollowUps.discountCode')" @update:model-value="pickCode(row, $event)" />
            <div>
              <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.discountFollowUps.expiry') }}</label>
              <DateInput v-model="row.expiresOn" :min="codeFor(row)?.starts_on ?? undefined" :disabled="!row.codeId" />
            </div>
          </div>
        </div>
      </div>

      <!-- Wider screens: the grid. -->
      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card] sm:block">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
          <thead class="bg-neutral-50 text-left text-xs font-semibold uppercase tracking-wide text-neutral-500">
            <tr>
              <th class="w-10 px-3 py-3"><input v-model="allTicked" type="checkbox" class="h-4 w-4 rounded border-neutral-300" :aria-label="t('admin.discountFollowUps.selectAll')" /></th>
              <th class="px-3 py-3">{{ t('admin.discountFollowUps.student') }}</th>
              <th class="px-3 py-3">{{ t('admin.discountFollowUps.lastCourse') }}</th>
              <th class="px-3 py-3">{{ t('admin.discountFollowUps.startStudy') }}</th>
              <th class="px-3 py-3">{{ t('admin.discountFollowUps.finished') }}</th>
              <th class="min-w-56 px-3 py-3">{{ t('admin.discountFollowUps.discountCode') }}</th>
              <th class="w-44 px-3 py-3">{{ t('admin.discountFollowUps.expiry') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in rows" :key="row.enrollment_id" :class="row.codeId ? 'bg-primary-50/40' : ''">
              <td class="px-3 py-2"><input v-model="row.ticked" type="checkbox" class="h-4 w-4 rounded border-neutral-300" :aria-label="row.student?.name ?? ''" /></td>
              <td class="px-3 py-2">
                <p class="font-medium text-neutral-900">{{ row.student?.name ?? '—' }}</p>
                <p class="text-xs text-neutral-500">{{ row.student?.student_code }}</p>
              </td>
              <td class="px-3 py-2 text-neutral-800">
                {{ row.course ?? '—' }}
                <p v-if="row.class" class="text-xs text-neutral-500">{{ row.class }}</p>
              </td>
              <td class="whitespace-nowrap px-3 py-2 text-neutral-800">{{ formatDate(row.start_study_on) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-neutral-800">{{ formatDate(row.completed_on) }}</td>
              <td class="px-3 py-2">
                <BaseSelect :model-value="row.codeId" :options="codeOptions" :placeholder="t('admin.discountFollowUps.chooseCode')" @update:model-value="pickCode(row, $event)" />
              </td>
              <td class="px-3 py-2">
                <DateInput v-model="row.expiresOn" :min="codeFor(row)?.starts_on ?? undefined" :disabled="!row.codeId" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Sticky only on wider screens: on a phone it would sit under the bottom tab bar. -->
      <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-neutral-200 bg-white/95 py-3 sm:sticky sm:bottom-0">
        <p class="w-full text-xs text-neutral-500 sm:mr-auto sm:w-auto">{{ t('admin.discountFollowUps.footerHint') }}</p>
        <BaseButton variant="outline" :disabled="ticked.length === 0 || saving" @click="skip">{{ t('admin.discountFollowUps.skip', { count: ticked.length }) }}</BaseButton>
        <BaseButton :loading="saving" :disabled="picked.length === 0" @click="save">{{ t('admin.discountFollowUps.save', { count: picked.length }) }}</BaseButton>
      </div>
    </template>
  </div>
</template>
