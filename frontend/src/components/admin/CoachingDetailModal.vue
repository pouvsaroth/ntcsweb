<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { coachingService, type Coaching, type CoachingSession } from '@/services/professionalDevelopment'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { coachingStatusVariant } from '@/utils/professionalDevelopment'

/**
 * One coaching / mentoring pairing: the goal, the sessions held (the coach
 * or a manager adds them), and closing it with an outcome.
 */
const props = defineProps<{ modelValue: boolean; coachingId: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; changed: []; edit: [Coaching] }>()

const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

const coaching = ref<Coaching | null>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

async function load() {
  if (props.coachingId == null) return
  loading.value = true
  error.value = null
  try {
    coaching.value = await coachingService.get(props.coachingId)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    sessionFormOpen.value = false
    closing.value = false
    if (open) void load()
    else coaching.value = null
  },
)

const totalMinutes = computed(() => (coaching.value?.sessions ?? []).reduce((s, x) => s + (x.duration_minutes ?? 0), 0))

// --- Session form (add or edit) -------------------------------------------------
function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const sessionFormOpen = ref(false)
const editingSession = ref<CoachingSession | null>(null)
const session = reactive({ session_date: '', duration_minutes: '', topic: '', notes: '', action_items: '' })
const sessionErrors = ref<Record<string, string[]>>({})

function openSession(s: CoachingSession | null) {
  editingSession.value = s
  sessionErrors.value = {}
  Object.assign(session, {
    session_date: s?.session_date ?? todayIso(),
    duration_minutes: s?.duration_minutes != null ? String(s.duration_minutes) : '',
    topic: s?.topic ?? '',
    notes: s?.notes ?? '',
    action_items: s?.action_items ?? '',
  })
  sessionFormOpen.value = true
}

async function saveSession() {
  if (!coaching.value) return
  saving.value = true
  sessionErrors.value = {}
  error.value = null
  const input = {
    session_date: session.session_date,
    duration_minutes: session.duration_minutes.trim() ? Number(session.duration_minutes) : null,
    topic: session.topic.trim(),
    notes: session.notes.trim() || null,
    action_items: session.action_items.trim() || null,
  }
  try {
    coaching.value = editingSession.value ? await coachingService.updateSession(editingSession.value.id, input) : await coachingService.addSession(coaching.value.id, input)
    sessionFormOpen.value = false
    emit('changed')
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) sessionErrors.value = e.errors
    else error.value = e instanceof ApiRequestError ? e.message : t('proDev.saveFailed')
  } finally {
    saving.value = false
  }
}

async function removeSession(s: CoachingSession) {
  if (!(await confirmDialog.confirm({ message: t('proDev.coaching.deleteSessionConfirm', { topic: s.topic }), danger: true }))) return
  try {
    coaching.value = await coachingService.removeSession(s.id)
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.deleteFailed')
  }
}

// --- Close with an outcome --------------------------------------------------------
const closing = ref(false)
const outcome = ref('')
function startClose() {
  outcome.value = coaching.value?.outcome ?? ''
  closing.value = true
}
async function setStatus(status: 'completed' | 'cancelled' | 'active') {
  if (!coaching.value) return
  saving.value = true
  error.value = null
  try {
    coaching.value = await coachingService.update(coaching.value.id, { status, ...(status === 'completed' ? { outcome: outcome.value.trim() || null } : {}) })
    closing.value = false
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('proDev.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!coaching.value) return
  if (!(await confirmDialog.confirm({ message: t('proDev.coaching.deleteConfirm'), danger: true }))) return
  try {
    await coachingService.remove(coaching.value.id)
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
  <BaseModal :model-value="modelValue" size="lg" :title="coaching?.goal ?? t('proDev.tabs.coaching')" @update:model-value="emit('update:modelValue', $event)">
    <div v-if="loading && !coaching" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-else-if="coaching" class="space-y-5">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <div class="flex flex-wrap items-center gap-2">
        <BaseBadge :variant="coachingStatusVariant(coaching.status)">{{ t(`proDev.coachingStatuses.${coaching.status}`) }}</BaseBadge>
        <BaseBadge>{{ t(`proDev.coachingTypes.${coaching.type}`) }}</BaseBadge>
      </div>

      <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-neutral-500">{{ t('proDev.coaching.coach') }}</dt>
          <dd class="font-medium text-neutral-900">{{ coaching.coach?.name ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('proDev.coaching.coachee') }}</dt>
          <dd class="font-medium text-neutral-900">{{ coaching.staff?.name ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('proDev.coaching.period') }}</dt>
          <dd class="font-medium text-neutral-900">{{ formatDate(coaching.start_date) }}<template v-if="coaching.end_date"> – {{ formatDate(coaching.end_date) }}</template></dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('proDev.coaching.sessions') }}</dt>
          <dd class="font-medium text-neutral-900">{{ coaching.sessions_count }}<template v-if="totalMinutes"> · {{ t('proDev.coaching.minutes', { n: totalMinutes }) }}</template></dd>
        </div>
        <div v-if="coaching.observation" class="sm:col-span-2">
          <dt class="text-neutral-500">{{ t('proDev.coaching.followsObservation') }}</dt>
          <dd class="font-medium text-neutral-900">{{ formatDate(coaching.observation.observed_on) }}<template v-if="coaching.observation.form_name"> · {{ coaching.observation.form_name }}</template></dd>
        </div>
      </dl>
      <p v-if="coaching.description" class="whitespace-pre-line rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-700">{{ coaching.description }}</p>
      <div v-if="coaching.outcome" class="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm">
        <p class="font-medium text-green-800">{{ t('proDev.coaching.outcome') }}</p>
        <p class="mt-1 whitespace-pre-line text-green-900">{{ coaching.outcome }}</p>
      </div>

      <!-- Sessions -->
      <div>
        <div class="mb-2 flex items-center gap-2">
          <h3 class="text-sm font-semibold text-neutral-900">{{ t('proDev.coaching.sessions') }}</h3>
          <button
            v-if="coaching.can.sessions && coaching.status === 'active' && !sessionFormOpen"
            type="button"
            class="ml-auto text-sm font-medium text-secondary-700 hover:underline"
            @click="openSession(null)"
          >
            + {{ t('proDev.coaching.addSession') }}
          </button>
        </div>

        <form v-if="sessionFormOpen" class="mb-3 space-y-3 rounded-lg border border-primary-200 bg-primary-50/40 p-3" novalidate @submit.prevent="saveSession">
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <BaseInput v-model="session.session_date" class="col-span-2 sm:col-span-1" type="date" required :label="t('proDev.coaching.sessionDate')" :error="sessionErrors.session_date?.[0]" />
            <BaseInput v-model="session.duration_minutes" type="number" :label="t('proDev.coaching.duration')" :error="sessionErrors.duration_minutes?.[0]" />
          </div>
          <BaseInput v-model="session.topic" required :label="t('proDev.coaching.topic')" :error="sessionErrors.topic?.[0]" />
          <div>
            <label for="session-notes" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('proDev.coaching.notes') }}</label>
            <textarea id="session-notes" v-model="session.notes" rows="3" :class="fieldClass" />
          </div>
          <div>
            <label for="session-actions" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('proDev.coaching.actionItems') }}</label>
            <textarea id="session-actions" v-model="session.action_items" rows="2" :placeholder="t('proDev.coaching.actionItemsPlaceholder')" :class="fieldClass" />
          </div>
          <div class="flex justify-end gap-2">
            <BaseButton variant="outline" size="sm" @click="sessionFormOpen = false">{{ t('common.cancel') }}</BaseButton>
            <BaseButton type="submit" size="sm" :loading="saving">{{ t('common.save') }}</BaseButton>
          </div>
        </form>

        <p v-if="(coaching.sessions ?? []).length === 0 && !sessionFormOpen" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">
          {{ t('proDev.coaching.noSessions') }}
        </p>
        <ol v-else class="space-y-2">
          <li v-for="s in coaching.sessions" :key="s.id" class="rounded-lg border border-neutral-200 p-3">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="font-medium text-neutral-900">{{ s.topic }}</p>
                <p class="text-xs text-neutral-500">
                  {{ formatDate(s.session_date) }}<template v-if="s.duration_minutes"> · {{ t('proDev.coaching.minutes', { n: s.duration_minutes }) }}</template>
                </p>
              </div>
              <div v-if="coaching.can.sessions" class="flex gap-3 text-sm">
                <button type="button" class="font-medium text-secondary-700 hover:underline" @click="openSession(s)">{{ t('proDev.edit') }}</button>
                <button type="button" class="font-medium text-danger-600 hover:text-red-700" @click="removeSession(s)">{{ t('proDev.delete') }}</button>
              </div>
            </div>
            <p v-if="s.notes" class="mt-2 whitespace-pre-line text-sm text-neutral-700">{{ s.notes }}</p>
            <p v-if="s.action_items" class="mt-2 whitespace-pre-line rounded bg-amber-50 px-2 py-1 text-sm text-amber-900">
              <span class="font-medium">{{ t('proDev.coaching.actionItems') }}:</span> {{ s.action_items }}
            </p>
          </li>
        </ol>
      </div>

      <div v-if="closing" class="space-y-2 rounded-lg border border-green-200 bg-green-50 p-3">
        <label for="coaching-outcome" class="block text-sm font-medium text-green-900">{{ t('proDev.coaching.outcome') }}</label>
        <textarea id="coaching-outcome" v-model="outcome" rows="3" :placeholder="t('proDev.coaching.outcomePlaceholder')" :class="fieldClass" />
        <div class="flex justify-end gap-2">
          <BaseButton variant="outline" size="sm" @click="closing = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton size="sm" :loading="saving" @click="setStatus('completed')">{{ t('proDev.coaching.markCompleted') }}</BaseButton>
        </div>
      </div>
    </div>

    <template #footer>
      <div v-if="coaching" class="flex w-full flex-wrap justify-end gap-2">
        <template v-if="coaching.can.manage">
          <BaseButton variant="outline" class="mr-auto text-danger-600" @click="remove">{{ t('proDev.delete') }}</BaseButton>
          <BaseButton variant="outline" @click="emit('edit', coaching)">{{ t('proDev.edit') }}</BaseButton>
        </template>
        <template v-if="coaching.can.close">
          <BaseButton v-if="coaching.status === 'active'" variant="outline" :loading="saving" @click="setStatus('cancelled')">{{ t('proDev.coaching.cancel') }}</BaseButton>
          <BaseButton v-if="coaching.status === 'active' && !closing" @click="startClose">{{ t('proDev.coaching.markCompleted') }}</BaseButton>
          <BaseButton v-if="coaching.status !== 'active'" variant="outline" :loading="saving" @click="setStatus('active')">{{ t('proDev.coaching.reopen') }}</BaseButton>
        </template>
        <BaseButton v-if="!coaching.can.manage && !coaching.can.close" variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
