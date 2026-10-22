<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import GradebookPicker from '@/components/admin/GradebookPicker.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { gradebookService, type GradebookPick, type ValidationIssue, type ValidationRow } from '@/services/gradebook'
import { ApiRequestError } from '@/types/api'

/**
 * Examination > Grade Validation (docs/academics/gradebook.md, 7b): for a
 * term, every subject of each class — scores still missing, absent without a
 * remark, scores above a lowered maximum, a setup whose weights are not 100 %.
 * Each row opens Grade Entry on that class and subject.
 */
const { t } = useI18n()

const pick = ref<GradebookPick | null>(null)
const rows = ref<ValidationRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const onlyProblems = ref(false)

const ERROR_ISSUES: ValidationIssue[] = ['no_scheme', 'weights_not_100', 'setup_incomplete', 'over_max']
const variant = (i: ValidationIssue) => (ERROR_ISSUES.includes(i) ? 'danger' : i === 'missing' || i === 'not_started' ? 'warning' : 'neutral')

async function onPick(p: GradebookPick) {
  pick.value = p
  rows.value = []
  if (!p.termId) return
  loading.value = true
  error.value = null
  try {
    rows.value = await gradebookService.validation(p.termId, p.classId)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.loadFailed')
  } finally {
    loading.value = false
  }
}

const shown = computed(() => (onlyProblems.value ? rows.value.filter((r) => !r.ready) : rows.value).map((r) => ({ ...r, key: `${r.class.id}-${r.subject.id}` })))
const readyCount = computed(() => rows.value.filter((r) => r.ready).length)

const entryLink = (r: ValidationRow) => ({
  path: '/admin/examination/entry',
  query: { year: pick.value?.yearId ?? undefined, term: pick.value?.termId ?? undefined, class: r.class.id, subject: r.subject.id },
})
const progress = (r: ValidationRow) => (r.expected ? `${r.entered} / ${r.expected}` : '—')

const columns = computed(() => [
  { key: 'class', label: t('gradebook.class') },
  { key: 'subject', label: t('gradebook.subject') },
  { key: 'status', label: t('gradebook.validation.status') },
  { key: 'progress', label: t('gradebook.validation.entered') },
  { key: 'issues', label: t('gradebook.validation.issues') },
  { key: 'open', label: '', align: 'text-right' },
])
</script>

<template>
  <div>
    <GradebookPicker class-optional class="mb-4" @change="onPick" />

    <div class="mb-3 flex flex-wrap items-center gap-3 text-sm">
      <span v-if="rows.length" class="text-neutral-700">{{ t('gradebook.validation.summary', { ready: readyCount, total: rows.length }) }}</span>
      <label class="ml-auto flex items-center gap-2 text-neutral-700"><input v-model="onlyProblems" type="checkbox" /> {{ t('gradebook.validation.onlyProblems') }}</label>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="shown.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('gradebook.validation.empty') }}
      </p>
      <div v-else class="space-y-2">
        <RouterLink v-for="r in shown" :key="r.key" :to="entryLink(r)" class="block rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="font-semibold text-neutral-900">{{ r.class.name }} · {{ r.subject.title }}</p>
            <BaseBadge :variant="r.ready ? 'success' : 'warning'">{{ r.ready ? t('gradebook.validation.ready') : t(`gradebook.status.${r.status ?? 'none'}`) }}</BaseBadge>
          </div>
          <p class="mt-1 text-xs text-neutral-500">{{ t('gradebook.validation.entered') }}: {{ progress(r) }}</p>
          <div v-if="r.issues.length" class="mt-2 flex flex-wrap gap-1">
            <BaseBadge v-for="i in r.issues" :key="i" :variant="variant(i)">{{ t(`gradebook.issues.${i}`, { n: i === 'missing' ? r.missing : i === 'absent_without_remark' ? r.absent_without_remark : r.over_max }) }}</BaseBadge>
          </div>
        </RouterLink>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="shown" row-key="key" :loading="loading" :empty-message="t('gradebook.validation.empty')">
        <template #cell-class="{ row }"><span class="font-medium text-neutral-900">{{ row.class.name }}</span></template>
        <template #cell-subject="{ row }">{{ row.subject.title }}</template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="row.status === 'approved' ? 'success' : row.status === 'pending' ? 'primary' : 'neutral'">{{ t(`gradebook.status.${row.status ?? 'none'}`) }}</BaseBadge>
        </template>
        <template #cell-progress="{ row }"><span class="whitespace-nowrap">{{ progress(row) }}</span></template>
        <template #cell-issues="{ row }">
          <span v-if="row.ready" class="text-sm text-green-700">✓ {{ t('gradebook.validation.ready') }}</span>
          <div v-else class="flex flex-wrap gap-1">
            <BaseBadge v-for="i in row.issues" :key="i" :variant="variant(i)">{{ t(`gradebook.issues.${i}`, { n: i === 'missing' ? row.missing : i === 'absent_without_remark' ? row.absent_without_remark : row.over_max }) }}</BaseBadge>
          </div>
        </template>
        <template #cell-open="{ row }">
          <RouterLink :to="entryLink(row)" class="text-sm font-medium text-secondary-700 hover:underline">{{ t('gradebook.validation.open') }}</RouterLink>
        </template>
      </DataTable>
    </div>
  </div>
</template>
