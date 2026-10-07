<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { payAmountLabel } from '@/services/payroll'
import {
  performanceCyclesService,
  promotionsService,
  type PerformanceCycle,
  type PromotionCurrent,
  type PromotionOptions,
  type PromotionRecommendation,
  type PromotionStatus,
  type PromotionSuggestion,
} from '@/services/performance'
import { staffService, type Staff } from '@/services/staff'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Performance Management > Promotion recommendation — a cycle's top
 * scorers to consider, and every recommendation: a new position / job
 * grade / job level and an optional new salary from a date, approved
 * (step by step with an Approval Flow) then applied to the staff record
 * and Payroll when its date comes. Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('performance.manage'))

const statusFilter = ref<PromotionStatus | ''>('')
const { items, meta, loading, error, setPage, setFilter, fetch } = usePaginatedResource<PromotionRecommendation>((query) => promotionsService.list(query))
watch(statusFilter, (status) => setFilter('status', status || undefined))

const statusVariant: Record<PromotionStatus, 'warning' | 'primary' | 'success' | 'danger' | 'neutral'> = {
  pending: 'warning',
  approved: 'primary',
  applied: 'success',
  rejected: 'danger',
  cancelled: 'neutral',
}
const statusOptions = computed(() => [
  { value: '', label: t('admin.performance.goals.allStatuses') },
  ...(['pending', 'approved', 'applied', 'rejected', 'cancelled'] as PromotionStatus[]).map((s) => ({ value: s, label: t(`admin.performance.promotions.status.${s}`) })),
])

/** "Teacher → Senior teacher · G4 → G5" — what changes. */
function changes(r: PromotionRecommendation): string {
  const parts = [
    r.to_position ? `${r.from_position?.name ?? '—'} → ${r.to_position.name}` : null,
    r.to_job_grade ? `${r.from_job_grade?.name ?? '—'} → ${r.to_job_grade.name}` : null,
    r.to_job_level ? `${r.from_job_level?.name ?? '—'} → ${r.to_job_level.name}` : null,
  ].filter(Boolean)
  return parts.join(' · ') || t('admin.performance.promotions.salaryOnly')
}

function salaryChange(r: PromotionRecommendation): string | null {
  if (r.new_basic_salary === null) return null
  const from = r.from_basic_salary !== null ? payAmountLabel(r.from_basic_salary, 'fixed', r.salary_currency) : '—'
  return `${from} → ${payAmountLabel(r.new_basic_salary, 'fixed', r.salary_currency)}`
}

// --- Suggestions ---------------------------------------------------------------------------

const cycles = ref<PerformanceCycle[]>([])
const cycleId = ref('')
const suggestions = ref<PromotionSuggestion[]>([])
const cycleOptions = computed(() => cycles.value.map((c) => ({ value: String(c.id), label: c.name })))

watch(cycleId, async (id) => {
  suggestions.value = id ? await promotionsService.suggestions(Number(id)).catch(() => []) : []
})

// --- Recommend -----------------------------------------------------------------------------

const staff = ref<Staff[]>([])
const options = ref<PromotionOptions | null>(null)
const current = ref<PromotionCurrent | null>(null)
const staffOptions = computed(() => staff.value.map((s) => ({ value: String(s.id), label: s.full_name, hint: s.employee_code })))
const pick = (rows: { id: number; name: string }[] | undefined, none: string) => [{ value: '', label: none }, ...(rows ?? []).map((r) => ({ value: String(r.id), label: r.name }))]

const formOpen = ref(false)
const form = reactive({ staff_id: '', performance_review_id: '', to_position_id: '', to_job_grade_id: '', to_job_level_id: '', new_basic_salary: '', effective_date: '', reason: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

function firstOfNextMonth(): string {
  const now = new Date()
  const next = new Date(now.getFullYear(), now.getMonth() + 1, 1)
  return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-01`
}

async function openForm(suggestion: PromotionSuggestion | null) {
  Object.assign(form, { staff_id: suggestion ? String(suggestion.staff.id) : '', performance_review_id: suggestion ? String(suggestion.review_id) : '', to_position_id: '', to_job_grade_id: '', to_job_level_id: '', new_basic_salary: '', effective_date: firstOfNextMonth(), reason: '' })
  errors.value = {}
  saveError.value = null
  current.value = null
  formOpen.value = true
  if (staff.value.length === 0) staff.value = await staffService.listAll().catch(() => [])
  if (!options.value) options.value = await promotionsService.options().catch(() => ({ positions: [], job_grades: [], job_levels: [] }))
  if (form.staff_id) await loadCurrent()
}

async function loadCurrent() {
  current.value = form.staff_id ? await promotionsService.current(Number(form.staff_id)).catch(() => null) : null
  if (current.value?.latest_review && !form.performance_review_id) form.performance_review_id = String(current.value.latest_review.id)
}

watch(() => form.staff_id, (value, old) => {
  if (formOpen.value && value !== old) {
    form.performance_review_id = ''
    void loadCurrent()
  }
})

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    await promotionsService.create({
      staff_id: Number(form.staff_id),
      performance_review_id: form.performance_review_id ? Number(form.performance_review_id) : null,
      to_position_id: form.to_position_id ? Number(form.to_position_id) : null,
      to_job_grade_id: form.to_job_grade_id ? Number(form.to_job_grade_id) : null,
      to_job_level_id: form.to_job_level_id ? Number(form.to_job_level_id) : null,
      new_basic_salary: form.new_basic_salary === '' ? null : Number(form.new_basic_salary),
      effective_date: form.effective_date,
      reason: form.reason.trim(),
    })
    formOpen.value = false
    await fetch()
    if (cycleId.value) suggestions.value = await promotionsService.suggestions(Number(cycleId.value)).catch(() => [])
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    saveError.value = e instanceof ApiRequestError ? e.message : t('admin.performance.saveFailed')
  } finally {
    saving.value = false
  }
}

// --- One recommendation --------------------------------------------------------------------

const open = ref<PromotionRecommendation | null>(null)
const busy = ref(false)
const detailError = ref<string | null>(null)
const rejectReason = ref('')

async function act(action: () => Promise<PromotionRecommendation>, confirm?: string) {
  if (confirm && !(await confirmDialog.confirm({ message: confirm }))) return
  busy.value = true
  detailError.value = null
  try {
    open.value = await action()
    await fetch()
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? (e.errors?.reason?.[0] ?? e.message) : t('admin.performance.saveFailed')
  } finally {
    busy.value = false
  }
}

const approve = () => act(() => promotionsService.approve(open.value!.id), t('admin.performance.promotions.approveConfirm', { name: open.value!.staff?.name ?? '' }))
const reject = () => act(() => promotionsService.reject(open.value!.id, rejectReason.value.trim()))
const cancel = () => act(() => promotionsService.cancel(open.value!.id), t('admin.performance.promotions.cancelConfirm'))

onMounted(async () => {
  void fetch()
  cycles.value = await performanceCyclesService.listAll().catch(() => [])
  const latest = cycles.value.find((c) => c.status === 'closed') ?? cycles.value.find((c) => c.status === 'active') ?? cycles.value[0]
  cycleId.value = latest ? String(latest.id) : ''
})
</script>

<template>
  <div class="space-y-6">
    <!-- Suggestions -->
    <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
      <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.performance.promotions.suggestions') }}</h2>
          <p class="text-sm text-neutral-500">{{ t('admin.performance.promotions.suggestionsHint') }}</p>
        </div>
        <BaseSelect v-model="cycleId" class="w-full sm:w-56" :options="cycleOptions" :placeholder="t('admin.performance.review.pickCycle')" />
      </div>
      <p v-if="suggestions.length === 0" class="text-sm text-neutral-500">{{ t('admin.performance.promotions.noSuggestions') }}</p>
      <ul v-else class="divide-y divide-neutral-100">
        <li v-for="s in suggestions" :key="s.review_id" class="flex flex-wrap items-center justify-between gap-2 py-2">
          <div>
            <p class="text-sm font-medium text-neutral-800">{{ s.staff.name }} <span class="font-normal text-neutral-500">({{ s.staff.employee_code }})</span></p>
            <p class="text-xs text-neutral-500">{{ s.staff.position ?? '—' }} · {{ t('admin.performance.score.final') }} {{ s.final_score.toFixed(2) }} / 5</p>
          </div>
          <BaseButton v-if="canManage" size="sm" variant="outline" @click="openForm(s)">{{ t('admin.performance.promotions.recommend') }}</BaseButton>
        </li>
      </ul>
    </section>

    <!-- Recommendations -->
    <section>
      <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <BaseSelect v-model="statusFilter" class="w-full sm:w-52" :options="statusOptions" />
        <BaseButton v-if="canManage" @click="openForm(null)">{{ t('admin.performance.promotions.add') }}</BaseButton>
      </div>
      <BaseAlert v-if="error" variant="danger" class="mb-3">{{ error }}</BaseAlert>
      <div v-if="loading" class="flex justify-center py-8"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.performance.promotions.empty') }}</p>
      <template v-else>
        <div class="space-y-2 sm:hidden">
          <button v-for="r in items" :key="r.id" type="button" class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]" @click="open = r; rejectReason = ''; detailError = null">
            <div class="flex items-start justify-between gap-2">
              <p class="text-sm font-semibold text-neutral-800">{{ r.staff?.name }}</p>
              <BaseBadge :variant="statusVariant[r.status]">{{ t(`admin.performance.promotions.status.${r.status}`) }}</BaseBadge>
            </div>
            <p class="text-xs text-neutral-700">{{ changes(r) }}</p>
            <p v-if="salaryChange(r)" class="text-xs text-neutral-700">{{ salaryChange(r) }}</p>
            <p class="text-xs text-neutral-500">{{ t('admin.performance.promotions.fromDate', { date: formatDate(r.effective_date) }) }}</p>
          </button>
        </div>
        <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-4 py-3 font-medium">{{ t('admin.performance.staff') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.performance.promotions.change') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.performance.promotions.salary') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.performance.promotions.effective') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.organization.status') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in items" :key="r.id" class="cursor-pointer hover:bg-neutral-50" @click="open = r; rejectReason = ''; detailError = null">
                <td class="px-4 py-3">
                  <p class="font-medium text-primary-700">{{ r.staff?.name }}</p>
                  <p class="text-xs text-neutral-500">{{ r.staff?.employee_code }}<template v-if="r.review?.final_score"> · {{ r.review.final_score.toFixed(2) }} / 5</template></p>
                </td>
                <td class="px-4 py-3 text-neutral-700">{{ changes(r) }}</td>
                <td class="px-4 py-3 tabular-nums text-neutral-700">{{ salaryChange(r) ?? '—' }}</td>
                <td class="px-4 py-3 text-neutral-700">{{ formatDate(r.effective_date) }}</td>
                <td class="px-4 py-3">
                  <BaseBadge :variant="statusVariant[r.status]">{{ t(`admin.performance.promotions.status.${r.status}`) }}</BaseBadge>
                  <p v-if="r.status === 'pending' && r.approval_flow" class="text-xs text-neutral-500">{{ t('admin.approvals.flowStep', { step: r.approval_flow.step, total: r.approval_flow.total, group: r.approval_flow.group ?? '—' }) }}</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
      <BasePagination v-if="meta" :meta="meta" class="mt-3" @update:page="setPage" />
    </section>

    <!-- Recommend -->
    <BaseModal v-model="formOpen" size="lg" :title="t('admin.performance.promotions.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <SearchableSelect v-model="form.staff_id" required :options="staffOptions" :label="t('admin.performance.staff')" :error="errors.staff_id?.[0]" />
        <div v-if="current" class="rounded-lg bg-neutral-50 p-3 text-sm">
          <p class="mb-1 font-medium text-neutral-700">{{ t('admin.performance.promotions.now') }}</p>
          <p class="text-neutral-600">
            {{ current.position?.name ?? '—' }} · {{ t('admin.performance.promotions.grade') }} {{ current.job_grade?.name ?? '—' }} · {{ t('admin.performance.promotions.level') }} {{ current.job_level?.name ?? '—' }}
            · {{ current.basic_salary !== null ? payAmountLabel(current.basic_salary, 'fixed', current.currency) : t('admin.performance.promotions.noSalary') }}
          </p>
          <p v-if="current.latest_review" class="text-xs text-neutral-500">{{ t('admin.performance.promotions.latestReview', { cycle: current.latest_review.cycle ?? '', score: current.latest_review.final_score?.toFixed(2) ?? '—' }) }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
          <BaseSelect v-model="form.to_position_id" :options="pick(options?.positions, t('admin.performance.promotions.noChange'))" :label="t('admin.performance.promotions.newPosition')" :error="errors.to_position_id?.[0]" />
          <BaseSelect v-model="form.to_job_grade_id" :options="pick(options?.job_grades, t('admin.performance.promotions.noChange'))" :label="t('admin.performance.promotions.newGrade')" :error="errors.to_job_grade_id?.[0]" />
          <BaseSelect v-model="form.to_job_level_id" :options="pick(options?.job_levels, t('admin.performance.promotions.noChange'))" :label="t('admin.performance.promotions.newLevel')" :error="errors.to_job_level_id?.[0]" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput
            v-model="form.new_basic_salary"
            type="number"
            :label="t('admin.performance.promotions.newSalary', { currency: current?.currency ?? '' })"
            :hint="t('admin.performance.promotions.newSalaryHint')"
            :error="errors.new_basic_salary?.[0]"
          />
          <BaseInput v-model="form.effective_date" type="date" required :label="t('admin.performance.promotions.effective')" :error="errors.effective_date?.[0]" />
        </div>
        <BaseInput v-model="form.reason" required :label="t('admin.performance.promotions.reason')" :error="errors.reason?.[0]" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" :disabled="!form.staff_id || !form.reason.trim()" @click="save">{{ t('admin.performance.promotions.send') }}</BaseButton>
      </template>
    </BaseModal>

    <!-- One recommendation -->
    <BaseModal :model-value="open !== null" :title="open ? `${open.staff?.name ?? ''} — ${t('admin.performance.tabs.promotion')}` : ''" @update:model-value="open = null">
      <template v-if="open">
        <BaseAlert v-if="detailError" variant="danger" class="mb-3">{{ detailError }}</BaseAlert>
        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
          <dt class="text-neutral-500">{{ t('admin.organization.status') }}</dt>
          <dd><BaseBadge :variant="statusVariant[open.status]">{{ t(`admin.performance.promotions.status.${open.status}`) }}</BaseBadge></dd>
          <dt class="text-neutral-500">{{ t('admin.performance.promotions.change') }}</dt><dd>{{ changes(open) }}</dd>
          <template v-if="salaryChange(open)"><dt class="text-neutral-500">{{ t('admin.performance.promotions.salary') }}</dt><dd>{{ salaryChange(open) }}</dd></template>
          <dt class="text-neutral-500">{{ t('admin.performance.promotions.effective') }}</dt><dd>{{ formatDate(open.effective_date) }}</dd>
          <template v-if="open.review"><dt class="text-neutral-500">{{ t('admin.performance.tabs.review') }}</dt><dd>{{ open.review.cycle }} · {{ open.review.final_score?.toFixed(2) ?? '—' }} / 5</dd></template>
          <dt class="text-neutral-500">{{ t('admin.performance.promotions.reason') }}</dt><dd class="whitespace-pre-line">{{ open.reason }}</dd>
          <dt class="text-neutral-500">{{ t('admin.performance.promotions.by') }}</dt><dd>{{ open.requested_by ?? '—' }}</dd>
          <template v-if="open.decided_by"><dt class="text-neutral-500">{{ t('admin.performance.promotions.decidedBy') }}</dt><dd>{{ open.decided_by }}</dd></template>
          <template v-if="open.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="text-red-700">{{ open.decision_reason }}</dd></template>
        </dl>
        <p v-if="open.status === 'pending' && open.approval_flow" class="mt-2 text-xs text-neutral-500">{{ t('admin.approvals.flowStep', { step: open.approval_flow.step, total: open.approval_flow.total, group: open.approval_flow.group ?? '—' }) }}</p>
        <p v-if="open.status === 'approved'" class="mt-3 rounded-lg bg-primary-50 px-3 py-2 text-sm text-primary-800">{{ t('admin.performance.promotions.waitsForDate', { date: formatDate(open.effective_date) }) }}</p>
        <BaseInput v-if="open.can_decide" v-model="rejectReason" class="mt-4" :label="t('admin.performance.promotions.rejectReason')" />
      </template>
      <template #footer>
        <template v-if="open">
          <BaseButton v-if="canManage && ['pending', 'approved'].includes(open.status)" variant="ghost" :disabled="busy" @click="cancel">{{ t('admin.performance.promotions.cancel') }}</BaseButton>
          <template v-if="open.can_decide">
            <BaseButton variant="danger" :disabled="busy || !rejectReason.trim()" @click="reject">{{ t('admin.payroll.runs.reject') }}</BaseButton>
            <BaseButton :loading="busy" @click="approve">{{ t('admin.payroll.runs.approve') }}</BaseButton>
          </template>
        </template>
        <BaseButton variant="outline" @click="open = null">{{ t('common.close') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
