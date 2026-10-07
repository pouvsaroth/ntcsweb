<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import ReviewForm from '@/pages/admin/performance/ReviewForm.vue'
import { reviewStatusVariant } from '@/pages/admin/performance/reviewStatus'
import { myPerformanceReviewsService, scoreLabel, type PerformanceReviewDetail, type PerformanceReviewRow } from '@/services/performance'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Performance Management > Employee self-assessment (`side` self) and
 * Manager assessment (`side` manager) — the signed-in staff member's own
 * reviews, or the ones they manage. Open one to rate and comment; save as
 * you go, then send it. Any staff account sees these (self-service).
 */
const props = defineProps<{ side: 'self' | 'manager' }>()

const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

const rows = ref<PerformanceReviewRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const service = myPerformanceReviewsService
const list = () => (props.side === 'self' ? service.mine() : service.team())
const get = (id: number) => (props.side === 'self' ? service.getMine(id) : service.getTeam(id))

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = await list()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.performance.loadFailed')
  } finally {
    loading.value = false
  }
}

/** What's waiting on this side first. */
const sorted = computed(() => {
  const mine = props.side === 'self' ? 'self_assessment' : 'manager_assessment'
  return [...rows.value].sort((a, b) => Number(b.status === mine) - Number(a.status === mine))
})

function due(row: PerformanceReviewRow): string | null {
  const date = props.side === 'self' ? row.cycle?.self_assessment_due : row.cycle?.manager_assessment_due
  return date ? formatDate(date) : null
}

// --- One review --------------------------------------------------------------------------

const open = ref<PerformanceReviewDetail | null>(null)
const form = ref<InstanceType<typeof ReviewForm> | null>(null)
const busy = ref(false)
const detailError = ref<string | null>(null)
const saved = ref(false)

const editable = computed(() => {
  if (!open.value) return false
  return props.side === 'self' ? open.value.status === 'self_assessment' : open.value.status !== 'completed'
})
const canSubmit = computed(() => open.value !== null && (props.side === 'self' ? open.value.status === 'self_assessment' : open.value.status === 'manager_assessment'))

async function openReview(row: PerformanceReviewRow) {
  detailError.value = null
  saved.value = false
  try {
    open.value = await get(row.id)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.performance.loadFailed')
  }
}

async function save(): Promise<boolean> {
  if (!open.value || !form.value) return false
  busy.value = true
  detailError.value = null
  saved.value = false
  try {
    const draft = form.value.draft()
    open.value = props.side === 'self' ? await service.saveMine(open.value.id, draft) : await service.saveTeam(open.value.id, draft)
    saved.value = true
    return true
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.performance.saveFailed')
    return false
  } finally {
    busy.value = false
  }
}

async function submit() {
  if (!open.value) return
  if (!(await confirmDialog.confirm({ message: t(props.side === 'self' ? 'admin.performance.review.submitSelfConfirm' : 'admin.performance.review.submitManagerConfirm') }))) return
  if (!(await save())) return
  busy.value = true
  try {
    open.value = props.side === 'self' ? await service.submitMine(open.value.id) : await service.submitTeam(open.value.id)
    await load()
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.performance.saveFailed')
  } finally {
    busy.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <div>
    <p class="mb-4 text-sm text-neutral-500">{{ t(side === 'self' ? 'admin.performance.review.selfHint' : 'admin.performance.review.managerHint') }}</p>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t(side === 'self' ? 'admin.performance.review.noneSelf' : 'admin.performance.review.noneManager') }}
    </p>
    <div v-else class="space-y-2">
      <button
        v-for="row in sorted"
        :key="row.id"
        type="button"
        class="flex w-full flex-wrap items-center justify-between gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-4 text-left shadow-[--shadow-card] hover:border-primary-300"
        @click="openReview(row)"
      >
        <div class="min-w-0">
          <p class="text-sm font-semibold text-neutral-800">
            {{ side === 'self' ? row.cycle?.name : row.staff?.name }}
            <span v-if="side === 'manager'" class="font-normal text-neutral-500">({{ row.staff?.employee_code }}) · {{ row.cycle?.name }}</span>
          </p>
          <p class="text-xs text-neutral-500">
            <template v-if="side === 'self'">{{ t('admin.performance.review.manager') }}: {{ row.reviewer?.name ?? '—' }}</template>
            <template v-else>{{ [row.staff?.position, row.staff?.department].filter(Boolean).join(' · ') || '—' }}</template>
            <template v-if="due(row) && row.status !== 'completed'"> · {{ t('admin.performance.review.dueOn', { date: due(row) }) }}</template>
          </p>
        </div>
        <div class="flex items-center gap-3">
          <span v-if="row.final_score !== null" class="text-sm font-semibold text-neutral-900">{{ scoreLabel(row.final_score) }}</span>
          <BaseBadge :variant="reviewStatusVariant[row.status]">{{ t(`admin.performance.review.status.${row.status}`) }}</BaseBadge>
        </div>
      </button>
    </div>

    <BaseModal :model-value="open !== null" size="lg" :title="open ? `${open.staff?.name ?? ''} — ${open.cycle?.name ?? ''}` : ''" @update:model-value="open = null">
      <template v-if="open">
        <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
          <BaseBadge :variant="reviewStatusVariant[open.status]">{{ t(`admin.performance.review.status.${open.status}`) }}</BaseBadge>
          <span class="text-neutral-500">{{ t('admin.performance.review.manager') }}: {{ open.reviewer?.name ?? '—' }}</span>
        </div>
        <BaseAlert v-if="side === 'manager' && open.status === 'self_assessment'" variant="info" class="mb-4">{{ t('admin.performance.review.waitingForSelf') }}</BaseAlert>
        <BaseAlert v-if="detailError" variant="danger" class="mb-4">{{ detailError }}</BaseAlert>
        <BaseAlert v-else-if="saved" variant="success" class="mb-4">{{ t('admin.performance.review.saved') }}</BaseAlert>
        <ReviewForm ref="form" :review="open" :side="side" :editable="editable" />
      </template>
      <template #footer>
        <BaseButton variant="outline" @click="open = null">{{ t('common.close') }}</BaseButton>
        <template v-if="editable">
          <BaseButton variant="outline" :loading="busy" @click="save">{{ t('admin.performance.review.saveDraft') }}</BaseButton>
          <BaseButton v-if="canSubmit" :loading="busy" @click="submit">{{ t(side === 'self' ? 'admin.performance.review.sendToManager' : 'admin.performance.review.complete') }}</BaseButton>
        </template>
      </template>
    </BaseModal>
  </div>
</template>
