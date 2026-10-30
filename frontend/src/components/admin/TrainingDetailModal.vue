<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import TrainingCertificateModal from '@/components/admin/TrainingCertificateModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import {
  ATTENDANCES,
  RESULTS,
  trainingRecordsService,
  trainingsService,
  type Attendance,
  type Training,
  type TrainingParticipant,
  type TrainingResult,
} from '@/services/professionalDevelopment'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate, formatTimeOfDay } from '@/utils/date'
import { trainingStatusVariant } from '@/utils/professionalDevelopment'

/**
 * A training: its details, and everyone on it — take attendance and
 * results, and issue certificates once it is completed.
 */
const props = defineProps<{ modelValue: boolean; trainingId: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; changed: []; edit: [Training] }>()

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('professional-development.manage'))

const training = ref<Training | null>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const saved = ref(false)

interface Row {
  id: number
  attendance: Attendance
  result: TrainingResult | ''
  score: string
  note: string
}
const rows = ref<Row[]>([])
const original = ref('')

function fill(tr: Training) {
  training.value = tr
  rows.value = (tr.participants ?? []).map((p) => ({
    id: p.id,
    attendance: p.attendance,
    result: p.result ?? '',
    score: p.score != null ? String(p.score) : '',
    note: p.note ?? '',
  }))
  original.value = JSON.stringify(rows.value)
}

const dirty = computed(() => JSON.stringify(rows.value) !== original.value)

async function load() {
  if (props.trainingId == null) return
  loading.value = true
  error.value = null
  try {
    fill(await trainingsService.get(props.trainingId))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    saved.value = false
    if (open) void load()
    else training.value = null
  },
)

const participant = (id: number) => training.value?.participants?.find((p) => p.id === id) ?? null

const when = computed(() => {
  const tr = training.value
  if (!tr) return ''
  const days = tr.start_date === tr.end_date ? formatDate(tr.start_date) : `${formatDate(tr.start_date)} – ${formatDate(tr.end_date)}`
  const time = tr.start_time ? ` · ${formatTimeOfDay(tr.start_time)}${tr.end_time ? ` – ${formatTimeOfDay(tr.end_time)}` : ''}` : ''
  return days + time
})

const attendanceOptions = computed(() => ATTENDANCES.map((v) => ({ value: v, label: t(`proDev.attendance.${v}`) })))
const resultOptions = computed(() => [{ value: '', label: '—' }, ...RESULTS.map((v) => ({ value: v, label: t(`proDev.results.${v}`) }))])

function markAll(value: Attendance) {
  for (const row of rows.value) row.attendance = value
}

async function saveAttendance() {
  if (!training.value) return
  saving.value = true
  error.value = null
  try {
    fill(
      await trainingsService.saveAttendance(
        training.value.id,
        rows.value.map((r) => ({ id: r.id, attendance: r.attendance, result: r.result || null, score: r.score.trim() === '' ? null : Number(r.score), note: r.note.trim() || null })),
      ),
    )
    saved.value = true
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.saveFailed')
  } finally {
    saving.value = false
  }
}

async function setStatus(status: 'completed' | 'cancelled') {
  if (!training.value) return
  if (status === 'cancelled' && !(await confirmDialog.confirm({ message: t('proDev.trainings.cancelConfirm', { title: training.value.title }), danger: true }))) return
  error.value = null
  try {
    fill(await trainingsService.update(training.value.id, { status }))
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.saveFailed')
  }
}

async function remove() {
  if (!training.value) return
  if (!(await confirmDialog.confirm({ message: t('proDev.trainings.deleteConfirm', { title: training.value.title }), danger: true }))) return
  try {
    await trainingsService.remove(training.value.id)
    emit('changed')
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.deleteFailed')
  }
}

// --- Certificates -------------------------------------------------------------
const certOpen = ref(false)
const certFor = ref<TrainingParticipant | null>(null)

function openCertificate(p: TrainingParticipant) {
  certFor.value = p
  certOpen.value = true
}

async function onCertificateSaved() {
  await load()
  emit('changed')
}

async function download(p: TrainingParticipant) {
  try {
    await trainingRecordsService.downloadCertificate(p)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.loadFailed')
  }
}

const selectClass =
  'block w-full rounded-lg border border-neutral-300 px-2 py-1.5 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 disabled:bg-neutral-50'
</script>

<template>
  <BaseModal :model-value="modelValue" size="lg" :title="training?.title ?? t('proDev.trainings.training')" @update:model-value="emit('update:modelValue', $event)">
    <div v-if="loading && !training" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-else-if="training" class="space-y-5">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <div class="flex flex-wrap items-center gap-2">
        <BaseBadge :variant="trainingStatusVariant(training.status)">{{ t(`proDev.statuses.${training.status}`) }}</BaseBadge>
        <BaseBadge>{{ t(`proDev.providers.${training.provider_type}`) }}</BaseBadge>
        <BaseBadge v-if="training.category" variant="primary">{{ training.category }}</BaseBadge>
      </div>

      <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-neutral-500">{{ t('proDev.trainings.when') }}</dt>
          <dd class="font-medium text-neutral-900">{{ when }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('proDev.trainings.location') }}</dt>
          <dd class="font-medium text-neutral-900">{{ t(`proDev.modes.${training.mode}`) }}<template v-if="training.location"> · {{ training.location }}</template></dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('proDev.trainings.trainer') }}</dt>
          <dd class="font-medium text-neutral-900">{{ training.trainer || '—' }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('proDev.trainings.hours') }} · {{ t('proDev.trainings.cost') }}</dt>
          <dd class="font-medium text-neutral-900">{{ training.hours ?? '—' }} · {{ training.cost ?? '—' }}</dd>
        </div>
      </dl>
      <p v-if="training.description" class="whitespace-pre-line rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-700">{{ training.description }}</p>

      <div>
        <div class="mb-2 flex flex-wrap items-center gap-2">
          <h3 class="text-sm font-semibold text-neutral-900">
            {{ t('proDev.trainings.participants') }} ({{ training.participants?.length ?? 0 }})
          </h3>
          <template v-if="canManage && rows.length > 0 && training.status !== 'cancelled'">
            <button type="button" class="text-sm font-medium text-secondary-700 hover:underline sm:ml-auto" @click="markAll('attended')">{{ t('proDev.trainings.markAllAttended') }}</button>
          </template>
        </div>

        <p v-if="rows.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('proDev.trainings.noParticipants') }}</p>

        <ul v-else class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li v-for="row in rows" :key="row.id" class="space-y-2 p-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <p class="font-medium text-neutral-900">
                {{ participant(row.id)?.staff?.name }}
                <span v-if="participant(row.id)?.staff?.employee_code" class="text-xs font-normal text-neutral-500">· {{ participant(row.id)?.staff?.employee_code }}</span>
              </p>
              <div class="flex items-center gap-2 text-sm">
                <template v-if="participant(row.id)?.certificate">
                  <BaseBadge variant="success">{{ participant(row.id)?.certificate?.no }}</BaseBadge>
                  <button v-if="participant(row.id)?.certificate?.has_file" type="button" class="font-medium text-secondary-700 hover:underline" @click="download(participant(row.id)!)">
                    {{ t('proDev.certificates.download') }}
                  </button>
                </template>
                <button
                  v-if="canManage && training.status === 'completed' && row.attendance === 'attended'"
                  type="button"
                  class="font-medium text-secondary-700 hover:underline"
                  @click="openCertificate(participant(row.id)!)"
                >
                  {{ participant(row.id)?.certificate ? t('proDev.certificates.edit') : t('proDev.certificates.issue') }}
                </button>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
              <select v-model="row.attendance" :class="selectClass" :disabled="!canManage" :aria-label="t('proDev.records.attendance')">
                <option v-for="o in attendanceOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
              <select v-model="row.result" :class="selectClass" :disabled="!canManage" :aria-label="t('proDev.records.result')">
                <option v-for="o in resultOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
              <input v-model="row.score" type="number" min="0" :class="selectClass" :disabled="!canManage" :placeholder="t('proDev.records.score')" :aria-label="t('proDev.records.score')" />
              <input v-model="row.note" type="text" :class="selectClass" :disabled="!canManage" :placeholder="t('proDev.records.note')" :aria-label="t('proDev.records.note')" />
            </div>
          </li>
        </ul>
        <p v-if="canManage && training.status === 'planned' && rows.length > 0" class="mt-2 text-xs text-neutral-500">{{ t('proDev.trainings.certificateAfterComplete') }}</p>
      </div>
    </div>

    <template #footer>
      <div v-if="training && canManage" class="flex w-full flex-wrap justify-end gap-2">
        <BaseButton variant="outline" class="mr-auto text-danger-600" @click="remove">{{ t('proDev.delete') }}</BaseButton>
        <BaseButton v-if="training.status === 'planned'" variant="outline" @click="setStatus('cancelled')">{{ t('proDev.trainings.cancelTraining') }}</BaseButton>
        <BaseButton variant="outline" @click="emit('edit', training)">{{ t('proDev.edit') }}</BaseButton>
        <BaseButton v-if="training.status === 'planned'" variant="outline" @click="setStatus('completed')">{{ t('proDev.trainings.markCompleted') }}</BaseButton>
        <BaseButton v-if="rows.length > 0" :loading="saving" :disabled="!dirty" @click="saveAttendance">
          {{ saved && !dirty ? t('proDev.saved') : t('proDev.trainings.saveAttendance') }}
        </BaseButton>
      </div>
      <BaseButton v-else variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
    </template>

    <TrainingCertificateModal v-model="certOpen" :participant="certFor" :training-title="training?.title" @saved="onCertificateSaved" />
  </BaseModal>
</template>
