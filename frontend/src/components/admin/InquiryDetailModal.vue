<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { FOLLOW_UP_METHODS, inquiriesService, type FollowUpInput, type Inquiry } from '@/services/inquiries'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * One inquiry: who, what they want, and every follow-up so far, newest
 * first — with a form to add the next one, and Register student once they
 * decide to study.
 */
const props = defineProps<{ modelValue: boolean; inquiryId: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; changed: []; edit: [Inquiry] }>()

const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()

const inquiry = ref<Inquiry | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)

const canUpdate = computed(() => auth.can('inquiries.update'))
const canRegister = computed(() => auth.can('students.create') && canUpdate.value)
const isOpen = computed(() => inquiry.value !== null && ['new', 'following_up'].includes(inquiry.value.status))

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const followUp = reactive({ followed_up_on: '', method: 'call', note: '', next_follow_up_on: '', status: '' })
const followErrors = ref<Record<string, string[]>>({})
const followError = ref<string | null>(null)
const saving = ref(false)

function resetFollowUp() {
  Object.assign(followUp, { followed_up_on: todayIso(), method: 'call', note: '', next_follow_up_on: '', status: '' })
  followErrors.value = {}
  followError.value = null
}

async function load() {
  if (!props.inquiryId) return
  loading.value = true
  loadError.value = null
  try {
    inquiry.value = await inquiriesService.get(props.inquiryId)
  } catch (e) {
    loadError.value = e instanceof ApiRequestError ? e.message : t('frontOffice.inquiries.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    inquiry.value = null
    resetFollowUp()
    void load()
  },
)

const methodOptions = computed(() => FOLLOW_UP_METHODS.map((m) => ({ value: m, label: t(`frontOffice.inquiries.methods.${m}`) })))
const outcomeOptions = computed(() => [
  { value: '', label: t('frontOffice.inquiries.keepStatus') },
  { value: 'following_up', label: t('frontOffice.inquiries.statuses.following_up') },
  { value: 'not_interested', label: t('frontOffice.inquiries.statuses.not_interested') },
])

async function addFollowUp() {
  if (!inquiry.value) return
  saving.value = true
  followErrors.value = {}
  followError.value = null
  const input: FollowUpInput = {
    followed_up_on: followUp.followed_up_on,
    method: followUp.method as FollowUpInput['method'],
    note: followUp.note.trim(),
    next_follow_up_on: followUp.status === 'not_interested' ? null : followUp.next_follow_up_on || null,
    status: (followUp.status || null) as FollowUpInput['status'],
  }
  try {
    inquiry.value = await inquiriesService.addFollowUp(inquiry.value.id, input)
    resetFollowUp()
    emit('changed')
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) followErrors.value = e.errors
    else followError.value = e instanceof ApiRequestError ? e.message : t('frontOffice.inquiries.saveFailed')
  } finally {
    saving.value = false
  }
}

function registerStudent() {
  if (!inquiry.value) return
  emit('update:modelValue', false)
  void router.push({ path: '/admin/students/new', query: { inquiry: String(inquiry.value.id) } })
}

const statusVariant = (s: string) => (({ new: 'primary', following_up: 'warning', enrolled: 'success', not_interested: 'neutral' }) as const)[s as 'new'] ?? 'neutral'
const isDue = (date: string | null) => date !== null && date <= todayIso()
</script>

<template>
  <BaseModal :model-value="modelValue" size="lg" :title="inquiry?.name ?? t('frontOffice.inquiries.detailTitle')" @update:model-value="emit('update:modelValue', $event)">
    <BaseAlert v-if="loadError" variant="danger">{{ loadError }}</BaseAlert>
    <p v-else-if="loading || !inquiry" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>

    <div v-else class="space-y-5">
      <!-- Who and what -->
      <div class="flex flex-wrap items-center gap-2">
        <BaseBadge :variant="statusVariant(inquiry.status)">{{ t(`frontOffice.inquiries.statuses.${inquiry.status}`) }}</BaseBadge>
        <span class="text-sm text-neutral-500">{{ t(`frontOffice.inquiries.sources.${inquiry.source}`) }} · {{ formatDate(inquiry.inquiry_date) }}</span>
      </div>
      <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-neutral-500">{{ t('common.phone') }}</dt>
          <dd class="font-medium text-neutral-900"><a :href="`tel:${inquiry.phone}`" class="hover:underline">{{ inquiry.phone }}</a></dd>
        </div>
        <div v-if="inquiry.email">
          <dt class="text-neutral-500">{{ t('frontOffice.email') }}</dt>
          <dd class="font-medium text-neutral-900">{{ inquiry.email }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('frontOffice.inquiries.interestedIn') }}</dt>
          <dd class="font-medium text-neutral-900">{{ inquiry.interested_in || '—' }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('frontOffice.inquiries.assignedTo') }}</dt>
          <dd class="font-medium text-neutral-900">{{ inquiry.assigned_staff?.name ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('frontOffice.inquiries.nextFollowUp') }}</dt>
          <dd class="font-medium" :class="isDue(inquiry.next_follow_up_on) && isOpen ? 'text-danger-600' : 'text-neutral-900'">
            {{ inquiry.next_follow_up_on ? formatDate(inquiry.next_follow_up_on) : '—' }}
          </dd>
        </div>
        <div v-if="inquiry.student">
          <dt class="text-neutral-500">{{ t('frontOffice.inquiries.student') }}</dt>
          <dd class="font-medium text-neutral-900">
            <RouterLink :to="`/admin/students/${inquiry.student.id}`" class="text-secondary-700 hover:underline" @click="emit('update:modelValue', false)">
              {{ inquiry.student.name }}<span v-if="inquiry.student.student_code" class="text-neutral-500"> ({{ inquiry.student.student_code }})</span>
            </RouterLink>
          </dd>
        </div>
        <div v-if="inquiry.note" class="sm:col-span-2">
          <dt class="text-neutral-500">{{ t('frontOffice.inquiries.note') }}</dt>
          <dd class="whitespace-pre-line text-neutral-900">{{ inquiry.note }}</dd>
        </div>
      </dl>

      <!-- Add a follow-up -->
      <form v-if="canUpdate && isOpen" class="space-y-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4" novalidate @submit.prevent="addFollowUp">
        <h3 class="text-sm font-semibold text-neutral-900">{{ t('frontOffice.inquiries.addFollowUp') }}</h3>
        <BaseAlert v-if="followError" variant="danger">{{ followError }}</BaseAlert>
        <div class="grid gap-3 sm:grid-cols-2">
          <BaseInput v-model="followUp.followed_up_on" type="date" required :label="t('frontOffice.inquiries.followedUpOn')" :error="followErrors.followed_up_on?.[0]" />
          <BaseSelect v-model="followUp.method" :options="methodOptions" required :label="t('frontOffice.inquiries.method')" :error="followErrors.method?.[0]" />
        </div>
        <div>
          <label for="follow-note" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('frontOffice.inquiries.whatWasSaid') }} <span class="text-danger-600">*</span></label>
          <textarea
            id="follow-note"
            v-model="followUp.note"
            rows="2"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          />
          <p v-if="followErrors.note?.[0]" class="mt-1.5 text-sm text-danger-600">{{ followErrors.note[0] }}</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <BaseSelect v-model="followUp.status" :options="outcomeOptions" :label="t('frontOffice.inquiries.outcome')" />
          <BaseInput
            v-if="followUp.status !== 'not_interested'"
            v-model="followUp.next_follow_up_on"
            type="date"
            :label="t('frontOffice.inquiries.nextFollowUp')"
            :error="followErrors.next_follow_up_on?.[0]"
          />
        </div>
        <div class="flex justify-end">
          <BaseButton type="submit" size="sm" :loading="saving" :disabled="!followUp.note.trim()">{{ t('frontOffice.inquiries.saveFollowUp') }}</BaseButton>
        </div>
      </form>

      <!-- History -->
      <div>
        <h3 class="mb-2 text-sm font-semibold text-neutral-900">{{ t('frontOffice.inquiries.history') }} ({{ inquiry.follow_ups?.length ?? 0 }})</h3>
        <p v-if="!inquiry.follow_ups?.length" class="text-sm text-neutral-500">{{ t('frontOffice.inquiries.noFollowUps') }}</p>
        <ol v-else class="space-y-3 border-l-2 border-neutral-200 pl-4">
          <li v-for="f in inquiry.follow_ups" :key="f.id" class="relative">
            <span class="absolute -left-[1.3rem] top-1.5 h-2.5 w-2.5 rounded-full bg-primary-500" aria-hidden="true" />
            <p class="text-xs text-neutral-500">
              {{ formatDate(f.followed_up_on) }} · {{ t(`frontOffice.inquiries.methods.${f.method}`) }}<template v-if="f.created_by_name"> · {{ f.created_by_name }}</template>
            </p>
            <p class="whitespace-pre-line text-sm text-neutral-900">{{ f.note }}</p>
            <p v-if="f.next_follow_up_on" class="text-xs text-neutral-500">{{ t('frontOffice.inquiries.nextFollowUp') }}: {{ formatDate(f.next_follow_up_on) }}</p>
          </li>
        </ol>
      </div>
    </div>

    <template #footer>
      <BaseButton v-if="inquiry && canUpdate" variant="outline" @click="emit('edit', inquiry)">{{ t('common.edit') }}</BaseButton>
      <BaseButton v-if="inquiry && canRegister && !inquiry.student_id && inquiry.status !== 'not_interested'" @click="registerStudent">
        {{ t('frontOffice.inquiries.registerStudent') }}
      </BaseButton>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
    </template>
  </BaseModal>
</template>
