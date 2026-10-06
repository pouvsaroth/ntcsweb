<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { JOB_POSTING_CHANNELS, jobPostingsService, type JobPosition, type JobPosting, type JobPostingChannel } from '@/services/jobPositions'
import { ApiRequestError } from '@/types/api'

/** Record (or edit) where a job is advertised. */
const props = defineProps<{
  modelValue: boolean
  posting?: JobPosting | null
  /** The jobs to choose from. */
  jobs: JobPosition[]
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.posting != null)

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const form = reactive({
  job_position_id: '',
  channel: 'website' as JobPostingChannel,
  url: '',
  posted_on: today(),
  expires_on: '',
  is_active: true,
  note: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const jobOptions = computed(() => props.jobs.map((job) => ({ value: String(job.id), label: `${job.reference} · ${job.title}` })))
const channelOptions = computed(() => JOB_POSTING_CHANNELS.map((channel) => ({ value: channel, label: t(`admin.recruitment.channels.${channel}`) })))

watch(
  () => [props.modelValue, props.posting] as const,
  ([open]) => {
    if (!open) return

    form.job_position_id = props.posting ? String(props.posting.job_position_id) : (jobOptions.value[0]?.value ?? '')
    form.channel = props.posting?.channel ?? 'website'
    form.url = props.posting?.url ?? ''
    form.posted_on = props.posting?.posted_on ?? today()
    form.expires_on = props.posting?.expires_on ?? ''
    form.is_active = props.posting?.is_active ?? true
    form.note = props.posting?.note ?? ''
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  const input = {
    ...form,
    job_position_id: Number(form.job_position_id),
    url: form.url.trim() || null,
    expires_on: form.expires_on || null,
  }

  try {
    if (isEditing.value) {
      await jobPostingsService.update(props.posting!.id, input)
    } else {
      await jobPostingsService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.recruitment.postings.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.recruitment.postings.edit') : t('admin.recruitment.postings.add')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseSelect v-model="form.job_position_id" required :options="jobOptions" :label="t('admin.recruitment.postings.job')" :error="errors.job_position_id?.[0]" />
      <BaseSelect v-model="form.channel" :options="channelOptions" :label="t('admin.recruitment.postings.channel')" :error="errors.channel?.[0]" />
      <p v-if="form.channel === 'website'" class="-mt-2 text-xs text-neutral-500">{{ t('admin.recruitment.postings.websiteHint') }}</p>
      <BaseInput v-model="form.url" type="url" :label="t('admin.recruitment.postings.url')" placeholder="https://" :error="errors.url?.[0]" />

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.posted_on" type="date" required :label="t('admin.recruitment.postings.postedOn')" :error="errors.posted_on?.[0]" />
        <BaseInput v-model="form.expires_on" type="date" :label="t('admin.recruitment.postings.expiresOn')" :error="errors.expires_on?.[0]" />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.recruitment.postings.note') }}</label>
        <textarea
          v-model="form.note"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.recruitment.postings.active') }}
      </label>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
