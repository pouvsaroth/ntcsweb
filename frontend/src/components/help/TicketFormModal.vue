<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { CHAT_ACCEPT, CHAT_MAX_BYTES, CHAT_MAX_FILES } from '@/services/supportChat'
import { supportTicketService, TICKET_PRIORITIES, type TicketOptions, type TicketPriority, type TicketSummary } from '@/services/supportTickets'
import { ApiRequestError } from '@/types/api'
import { pickText } from '@/utils/helpText'

/**
 * Help & Support → New ticket (docs/backoffice/stage-13-help-support.md, 13b):
 * subject, module, priority, what happened, and screenshots or files
 * (paste a screenshot with Ctrl+V).
 */
const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; created: [ticket: TicketSummary] }>()

const { t, locale } = useI18n()

const options = ref<TicketOptions | null>(null)
const form = ref({ subject: '', module: '', priority: 'normal' as TicketPriority, body: '' })
const files = ref<File[]>([])
const previews = ref<string[]>([])
const saving = ref(false)
const error = ref<ApiRequestError | null>(null)
const fileError = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

function clearFiles(): void {
  previews.value.forEach((p) => p && URL.revokeObjectURL(p))
  files.value = []
  previews.value = []
}

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    form.value = { subject: '', module: '', priority: 'normal', body: '' }
    clearFiles()
    error.value = null
    fileError.value = null
    if (!options.value) {
      try {
        options.value = await supportTicketService.options()
      } catch {
        /* the module list stays empty; General still works */
      }
    }
  },
)

const moduleOptions = computed(() => [
  { value: '', label: t('help.general') },
  ...(options.value?.modules ?? []).map((m) => ({ value: m.code, label: pickText(locale.value, m.label, m.label_km) || m.code })),
])
const priorityOptions = computed(() => TICKET_PRIORITIES.map((p) => ({ value: p, label: t(`help.tickets.priority.${p}`) })))

function addFiles(list: FileList | File[]): void {
  fileError.value = null
  for (const f of Array.from(list)) {
    if (files.value.length >= CHAT_MAX_FILES) {
      fileError.value = t('supportChat.tooMany', { n: CHAT_MAX_FILES })
      break
    }
    if (f.size > CHAT_MAX_BYTES) {
      fileError.value = t('supportChat.tooBig', { name: f.name })
      continue
    }
    files.value.push(f)
    previews.value.push(f.type.startsWith('image/') ? URL.createObjectURL(f) : '')
  }
}
function removeFile(i: number): void {
  if (previews.value[i]) URL.revokeObjectURL(previews.value[i])
  files.value.splice(i, 1)
  previews.value.splice(i, 1)
}
function onPaste(event: ClipboardEvent): void {
  const pasted = Array.from(event.clipboardData?.files ?? [])
  if (pasted.length) {
    event.preventDefault()
    addFiles(pasted.map((f, i) => (f.name && f.name !== 'image.png' ? f : new File([f], `screenshot-${Date.now()}-${i}.png`, { type: f.type }))))
  }
}

const fieldErr = (name: string) => error.value?.errors?.[name]?.[0] ?? (name === 'files' ? Object.entries(error.value?.errors ?? {}).find(([k]) => k.startsWith('files'))?.[1]?.[0] : undefined)

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    const ticket = await supportTicketService.create({ ...form.value, module: form.value.module || null, files: files.value })
    clearFiles()
    emit('created', ticket)
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('help.loadFailed'), 0)
  } finally {
    saving.value = false
  }
}

const textareaClass =
  'block w-full rounded-lg border px-3 py-2 text-sm text-neutral-900 shadow-sm focus:ring-2 focus:outline-none border-neutral-300 focus:border-primary-500 focus:ring-primary-200'
</script>

<template>
  <BaseModal :model-value="modelValue" size="lg" :title="t('help.tickets.new')" @update:model-value="emit('update:modelValue', $event)">
    <form id="ticket-form" class="space-y-4" novalidate @submit.prevent="save">
      <BaseAlert v-if="error && !error.errors" variant="danger">{{ error.message }}</BaseAlert>

      <BaseInput v-model="form.subject" :label="t('help.tickets.subject')" :placeholder="t('help.tickets.subjectHint')" :error="fieldErr('subject')" required />
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseSelect v-model="form.module" :options="moduleOptions" :label="t('help.tickets.module')" :error="fieldErr('module')" />
        <BaseSelect v-model="form.priority" :options="priorityOptions" :label="t('help.tickets.priorityLabel')" :error="fieldErr('priority')" />
      </div>
      <div>
        <label for="ticket-body" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('help.tickets.body') }} <span class="text-red-600">*</span></label>
        <textarea id="ticket-body" v-model="form.body" rows="6" :class="textareaClass" :placeholder="t('help.tickets.bodyHint')" @paste="onPaste" />
        <p v-if="fieldErr('body')" class="mt-1 text-xs text-red-600">{{ fieldErr('body') }}</p>
      </div>

      <div>
        <div class="flex flex-wrap items-center gap-2">
          <BaseButton type="button" size="sm" variant="outline" @click="fileInput?.click()">{{ t('help.tickets.attach') }}</BaseButton>
          <span class="text-xs text-neutral-500">{{ t('help.tickets.attachHint', { n: CHAT_MAX_FILES }) }}</span>
        </div>
        <input
          ref="fileInput"
          type="file"
          multiple
          class="hidden"
          :accept="CHAT_ACCEPT"
          @change="addFiles(($event.target as HTMLInputElement).files ?? []), (($event.target as HTMLInputElement).value = '')"
        />
        <p v-if="fileError || fieldErr('files')" class="mt-1 text-xs text-red-600">{{ fileError || fieldErr('files') }}</p>
        <div v-if="files.length" class="mt-2 flex flex-wrap gap-2">
          <div v-for="(f, i) in files" :key="i" class="relative flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-neutral-50 p-1 pr-6 text-xs">
            <img v-if="previews[i]" :src="previews[i]" alt="" class="h-10 w-10 rounded object-cover" />
            <span v-else class="px-1" aria-hidden="true">📄</span>
            <span class="max-w-[160px] truncate">{{ f.name }}</span>
            <button type="button" class="absolute top-0.5 right-0.5 rounded p-0.5 text-neutral-400 hover:text-red-600" :aria-label="t('supportChat.remove')" @click="removeFile(i)">✕</button>
          </div>
        </div>
      </div>
    </form>

    <template #footer>
      <div class="flex w-full flex-wrap justify-end gap-2">
        <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
        <BaseButton type="submit" form="ticket-form" :loading="saving">{{ t('help.tickets.send') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
