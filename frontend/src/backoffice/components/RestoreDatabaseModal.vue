<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

import { dataApi, formatBytes, type BackupRow } from '../api'

/**
 * Restore into a NEW database ("Other"): a finished backup from the list, or
 * a .dump file uploaded from this computer. Nothing live is touched; using
 * the result for a school is a separate step.
 */
const props = defineProps<{ modelValue: boolean; backup?: BackupRow | null }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; restored: [name: string] }>()

const { t } = useI18n()

/** Same as the server's upload limit. */
const MAX_BYTES = 50 * 1024 * 1024

type Source = 'backup' | 'upload'
const form = reactive({ name: '', source: 'backup' as Source, backup_id: '', note: '' })
const file = ref<File | null>(null)
const backups = ref<BackupRow[]>([])
const errors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const busy = ref(false)
const progress = ref<number | null>(null)

const backupOptions = computed(() =>
  backups.value.map((b) => ({
    value: String(b.id),
    label: `#${b.id} · ${b.database} · ${b.created_at ? formatDateTime(b.created_at) : ''} · ${formatBytes(b.size_bytes)}`,
  })),
)

/** A free-looking name: the database plus the backup date, e.g. tn_newtech_r20261112. */
function suggestName(database: string, at: string | null): string {
  const day = (at ?? new Date().toISOString()).slice(0, 10).replace(/-/g, '')
  return `${database}_r${day}`.toLowerCase().replace(/[^a-z0-9_]/g, '_').slice(0, 63)
}

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    errors.value = {}
    error.value = null
    file.value = null
    progress.value = null
    form.note = ''
    form.source = 'backup'
    form.backup_id = props.backup ? String(props.backup.id) : ''
    form.name = props.backup ? suggestName(props.backup.database, props.backup.created_at) : ''
    if (!props.backup) {
      try {
        // Finished database backups only; a school export (.zip) cannot become a database.
        const result = await dataApi.backups({ page: 1, per_page: 100, filter: { status: 'ok' } })
        backups.value = result.data.filter((b) => b.status === 'ok' && b.format !== 'school' && b.file)
      } catch {
        backups.value = []
      }
    } else {
      backups.value = [props.backup]
    }
  },
)

watch(
  () => form.backup_id,
  (id) => {
    const picked = backups.value.find((b) => String(b.id) === id)
    if (picked && !form.name) form.name = suggestName(picked.database, picked.created_at)
  },
)

function onFile(event: Event): void {
  const picked = (event.target as HTMLInputElement).files?.[0] ?? null
  errors.value = { ...errors.value, file: [] }
  if (picked && picked.size > MAX_BYTES) {
    errors.value = { ...errors.value, file: [t('bo.data.restoreDb.tooBig')] }
    file.value = null
    return
  }
  file.value = picked
  if (picked && !form.name) form.name = picked.name.replace(/\.[^.]+$/, '').toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/^[^a-z]+/, '').slice(0, 63)
}

async function save(): Promise<void> {
  busy.value = true
  errors.value = {}
  error.value = null
  try {
    const name = form.name.trim().toLowerCase()
    if (form.source === 'upload') {
      if (!file.value) {
        errors.value = { file: [t('bo.data.restoreDb.pickFile')] }
        return
      }
      progress.value = 0
      await dataApi.restoreUpload(name, file.value, form.note.trim() || null, (f) => (progress.value = f))
    } else {
      await dataApi.restoreBackup(name, Number(form.backup_id), form.note.trim() || null)
    }
    emit('restored', name)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    busy.value = false
    progress.value = null
  }
}

const SOURCES: Source[] = ['backup', 'upload']
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('bo.data.restoreDb.title')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
      <BaseAlert variant="info">{{ t('bo.data.restoreDb.safe') }}</BaseAlert>

      <div v-if="!backup">
        <p class="mb-1 text-sm font-medium text-neutral-700">{{ t('bo.data.restoreDb.from') }}</p>
        <div class="grid gap-2 sm:grid-cols-2">
          <label
            v-for="source in SOURCES"
            :key="source"
            class="flex cursor-pointer items-start gap-2 rounded-lg border p-3"
            :class="form.source === source ? 'border-primary-500 bg-primary-50' : 'border-neutral-200'"
          >
            <input v-model="form.source" type="radio" :value="source" class="mt-0.5 border-neutral-300 text-primary-600 focus:ring-primary-500" />
            <span>
              <span class="block text-sm font-medium text-neutral-800">{{ t(`bo.data.restoreDb.sources.${source}`) }}</span>
              <span class="block text-xs text-neutral-500">{{ t(`bo.data.restoreDb.sourceHints.${source}`) }}</span>
            </span>
          </label>
        </div>
      </div>

      <template v-if="form.source === 'backup'">
        <BaseSelect
          v-model="form.backup_id"
          required
          :disabled="!!backup"
          :options="backupOptions"
          :label="t('bo.data.restoreDb.backup')"
          :placeholder="backupOptions.length ? t('bo.data.restoreDb.pickBackup') : t('bo.data.restoreDb.noBackups')"
          :error="errors.backup_id?.[0]"
        />
      </template>
      <div v-else>
        <p class="mb-1 text-sm font-medium text-neutral-700">{{ t('bo.data.restoreDb.file') }}</p>
        <input
          type="file"
          accept=".dump,.backup,application/octet-stream"
          class="block w-full text-sm text-neutral-700 file:mr-3 file:rounded-md file:border-0 file:bg-neutral-100 file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-neutral-200"
          @change="onFile"
        />
        <p class="mt-1 text-xs text-neutral-500">{{ t('bo.data.restoreDb.fileHint') }}</p>
        <p v-if="file" class="mt-1 text-xs text-neutral-600">{{ file.name }} · {{ formatBytes(file.size) }}</p>
        <p v-if="errors.file?.[0]" class="mt-1 text-xs text-danger-600">{{ errors.file[0] }}</p>
        <div v-if="progress !== null" class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100">
          <div class="h-full bg-primary-500 transition-all" :style="{ width: `${Math.round(progress * 100)}%` }" />
        </div>
      </div>

      <BaseInput v-model="form.name" required :label="t('bo.data.restoreDb.name')" :hint="t('bo.data.newDb.nameHint')" placeholder="tn_newtech_r20261112" :error="errors.name?.[0]" />
      <BaseInput v-model="form.note" :label="t('bo.data.newDb.note')" :placeholder="t('bo.data.newDb.notePlaceholder')" :error="errors.note?.[0]" />
      <p class="text-xs text-neutral-500">{{ t('bo.data.restoreDb.background') }}</p>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton :loading="busy" @click="save">{{ t('bo.data.restoreDb.restore') }}</BaseButton>
    </template>
  </BaseModal>
</template>
