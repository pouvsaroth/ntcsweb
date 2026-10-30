<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import StarRating from '@/pages/admin/performance/StarRating.vue'
import { observationsService, type Observation } from '@/services/professionalDevelopment'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate, formatDateTime, formatTimeOfDay } from '@/utils/date'
import { observationStatusVariant } from '@/utils/professionalDevelopment'

/**
 * One classroom observation. The observer (or a manager) rates each
 * criterion and writes strengths, areas to improve and next steps, then
 * completes it; the teacher reads it and acknowledges it. A manager can
 * reschedule, cancel, delete, or start coaching from it.
 */
const props = defineProps<{ modelValue: boolean; observationId: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; changed: []; edit: [Observation]; coach: [Observation] }>()

const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

const obs = ref<Observation | null>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const savedNote = ref(false)

const ratings = ref<{ id: number; rating: number | null; comment: string }[]>([])
const notes = reactive({ strengths: '', improvements: '', nextSteps: '' })
const ackComment = ref('')

function fill(o: Observation) {
  obs.value = o
  ratings.value = (o.ratings ?? []).map((r) => ({ id: r.id, rating: r.rating, comment: r.comment ?? '' }))
  Object.assign(notes, { strengths: o.strengths ?? '', improvements: o.improvements ?? '', nextSteps: o.next_steps ?? '' })
  ackComment.value = ''
}

async function load() {
  if (props.observationId == null) return
  loading.value = true
  error.value = null
  try {
    fill(await observationsService.get(props.observationId))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    savedNote.value = false
    if (open) void load()
    else obs.value = null
  },
)

const canRecord = computed(() => obs.value?.can.record ?? false)

/** Criteria grouped under their section headings, in form order. */
const groups = computed(() => {
  const out: { section: string | null; items: { index: number; criterion: string }[] }[] = []
  ;(obs.value?.ratings ?? []).forEach((r, index) => {
    const last = out[out.length - 1]
    if (last && last.section === r.section) last.items.push({ index, criterion: r.criterion })
    else out.push({ section: r.section, items: [{ index, criterion: r.criterion }] })
  })
  return out
})

const liveAverage = computed(() => {
  const rated = ratings.value.filter((r) => r.rating != null)
  return rated.length ? Math.round((rated.reduce((s, r) => s + (r.rating ?? 0), 0) / rated.length) * 100) / 100 : null
})

const when = computed(() => {
  const o = obs.value
  if (!o) return ''
  const time = o.start_time ? ` · ${formatTimeOfDay(o.start_time)}${o.end_time ? ` – ${formatTimeOfDay(o.end_time)}` : ''}` : ''
  return formatDate(o.observed_on) + time
})

async function save(complete: boolean) {
  if (!obs.value) return
  if (complete && !(await confirmDialog.confirm({ message: t('proDev.observations.completeConfirm') }))) return
  saving.value = true
  error.value = null
  try {
    fill(
      await observationsService.record(obs.value.id, {
        ratings: ratings.value.map((r) => ({ id: r.id, rating: r.rating, comment: r.comment.trim() || null })),
        strengths: notes.strengths.trim() || null,
        improvements: notes.improvements.trim() || null,
        next_steps: notes.nextSteps.trim() || null,
        complete,
      }),
    )
    savedNote.value = !complete
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.saveFailed')
  } finally {
    saving.value = false
  }
}

async function acknowledge() {
  if (!obs.value) return
  saving.value = true
  error.value = null
  try {
    fill(await observationsService.acknowledge(obs.value.id, ackComment.value.trim() || null))
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.saveFailed')
  } finally {
    saving.value = false
  }
}

async function cancelIt() {
  if (!obs.value) return
  if (!(await confirmDialog.confirm({ message: t('proDev.observations.cancelConfirm'), danger: true }))) return
  try {
    fill(await observationsService.update(obs.value.id, { status: 'cancelled' }))
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.saveFailed')
  }
}

async function remove() {
  if (!obs.value) return
  if (!(await confirmDialog.confirm({ message: t('proDev.observations.deleteConfirm'), danger: true }))) return
  try {
    await observationsService.remove(obs.value.id)
    emit('changed')
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.deleteFailed')
  }
}

const fieldClass =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200'
</script>

<template>
  <BaseModal :model-value="modelValue" size="lg" :title="t('proDev.observations.title')" @update:model-value="emit('update:modelValue', $event)">
    <div v-if="loading && !obs" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-else-if="obs" class="space-y-5">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <div class="flex flex-wrap items-center gap-2">
        <BaseBadge :variant="observationStatusVariant(obs.status)">{{ t(`proDev.observationStatuses.${obs.status}`) }}</BaseBadge>
        <BaseBadge v-if="obs.acknowledged_at" variant="success">{{ t('proDev.observations.acknowledged') }}</BaseBadge>
        <BaseBadge v-else-if="obs.status === 'completed'" variant="warning">{{ t('proDev.observations.awaitingAck') }}</BaseBadge>
        <span v-if="(canRecord ? liveAverage : obs.average_rating) != null" class="ml-auto text-sm font-semibold text-amber-600">
          ★ {{ canRecord ? liveAverage : obs.average_rating }} / 5
        </span>
      </div>

      <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-neutral-500">{{ t('proDev.observations.teacher') }}</dt>
          <dd class="font-medium text-neutral-900">{{ obs.teacher?.name ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('proDev.observations.observer') }}</dt>
          <dd class="font-medium text-neutral-900">{{ obs.observer?.name ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('proDev.observations.class') }} · {{ t('proDev.observations.subject') }}</dt>
          <dd class="font-medium text-neutral-900">{{ obs.class_name ?? '—' }} · {{ obs.subject ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('proDev.trainings.when') }}</dt>
          <dd class="font-medium text-neutral-900">{{ when }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('proDev.observations.form') }}</dt>
          <dd class="font-medium text-neutral-900">{{ obs.form_name ?? '—' }}</dd>
        </div>
        <div v-if="obs.focus">
          <dt class="text-neutral-500">{{ t('proDev.observations.focus') }}</dt>
          <dd class="font-medium text-neutral-900">{{ obs.focus }}</dd>
        </div>
      </dl>

      <p v-if="!obs.ratings" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">
        {{ t('proDev.observations.notYet') }}
      </p>

      <template v-else>
        <div v-for="(group, gi) in groups" :key="gi">
          <h3 v-if="group.section" class="mb-2 text-sm font-semibold text-neutral-900">{{ group.section }}</h3>
          <ul class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
            <li v-for="item in group.items" :key="item.index" class="space-y-2 p-3">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-neutral-800">{{ item.criterion }}</p>
                <StarRating v-model="ratings[item.index]!.rating" :readonly="!canRecord" />
              </div>
              <input
                v-if="canRecord"
                v-model="ratings[item.index]!.comment"
                type="text"
                :placeholder="t('proDev.observations.commentPlaceholder')"
                :class="fieldClass"
              />
              <p v-else-if="ratings[item.index]!.comment" class="text-xs text-neutral-600">{{ ratings[item.index]!.comment }}</p>
            </li>
          </ul>
        </div>

        <div class="grid gap-4">
          <div v-for="field in (['strengths', 'improvements', 'nextSteps'] as const)" :key="field">
            <label :for="`obs-${field}`" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t(`proDev.observations.${field}`) }}</label>
            <textarea v-if="canRecord" :id="`obs-${field}`" v-model="notes[field]" rows="3" :class="fieldClass" />
            <p v-else class="whitespace-pre-line rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-700">{{ notes[field] || '—' }}</p>
          </div>
        </div>
      </template>

      <!-- The teacher's acknowledgement -->
      <div v-if="obs.acknowledged_at" class="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm">
        <p class="font-medium text-green-800">{{ t('proDev.observations.acknowledgedOn', { date: formatDateTime(obs.acknowledged_at) }) }}</p>
        <p v-if="obs.teacher_comment" class="mt-1 whitespace-pre-line text-green-900">{{ obs.teacher_comment }}</p>
      </div>
      <div v-else-if="obs.can.acknowledge" class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3">
        <p class="text-sm text-amber-900">{{ t('proDev.observations.ackPrompt') }}</p>
        <textarea v-model="ackComment" rows="2" :placeholder="t('proDev.observations.ackCommentPlaceholder')" :class="fieldClass" />
      </div>
    </div>

    <template #footer>
      <div v-if="obs" class="flex w-full flex-wrap justify-end gap-2">
        <template v-if="obs.can.manage">
          <BaseButton variant="outline" class="mr-auto text-danger-600" @click="remove">{{ t('proDev.delete') }}</BaseButton>
          <BaseButton v-if="obs.status === 'scheduled'" variant="outline" @click="cancelIt">{{ t('proDev.observations.cancel') }}</BaseButton>
          <BaseButton v-if="obs.status === 'scheduled'" variant="outline" @click="emit('edit', obs)">{{ t('proDev.edit') }}</BaseButton>
          <BaseButton v-if="obs.status === 'completed'" variant="outline" @click="emit('coach', obs)">{{ t('proDev.observations.startCoaching') }}</BaseButton>
        </template>
        <template v-if="canRecord && obs.ratings">
          <BaseButton variant="outline" :loading="saving" @click="save(false)">{{ savedNote ? t('proDev.saved') : t('proDev.observations.saveDraft') }}</BaseButton>
          <BaseButton v-if="obs.status === 'scheduled'" :loading="saving" @click="save(true)">{{ t('proDev.observations.complete') }}</BaseButton>
        </template>
        <BaseButton v-if="obs.can.acknowledge" :loading="saving" @click="acknowledge">{{ t('proDev.observations.acknowledge') }}</BaseButton>
        <BaseButton v-if="!obs.can.manage && !canRecord && !obs.can.acknowledge" variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
