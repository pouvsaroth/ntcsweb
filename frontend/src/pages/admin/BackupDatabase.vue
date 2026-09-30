<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BackupTabs from '@/components/admin/BackupTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useAuthStore } from '@/stores/auth'
import { databaseBackupsService, type DatabaseBackupOption } from '@/services/databaseBackups'
import { ApiRequestError } from '@/types/api'

/**
 * Settings > Backup. Pick a database, pick where to save it, back up.
 *
 * "Where to save" uses the browser's Save As window (File System Access
 * API) — Chrome/Edge on a computer only. A website is never told the full
 * folder path, only the file name chosen, so that's what's shown. Every
 * other browser (Firefox, Safari, phones) has no such window: there the
 * backup is a normal download to the browser's download folder instead.
 */
type SaveFileHandle = { name: string; createWritable: () => Promise<{ write: (data: Blob) => Promise<void>; close: () => Promise<void> }> }
type SaveFilePicker = (options: { suggestedName: string; types: { description: string; accept: Record<string, string[]> }[] }) => Promise<SaveFileHandle>

const showSaveFilePicker = (window as unknown as { showSaveFilePicker?: SaveFilePicker }).showSaveFilePicker
const canChooseLocation = typeof showSaveFilePicker === 'function'

const { t } = useI18n()
const auth = useAuthStore()

const options = ref<DatabaseBackupOption[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

const selectedKey = ref('')
const saveHandle = ref<SaveFileHandle | null>(null)
const backingUp = ref(false)
const error = ref<string | null>(null)
const success = ref<string | null>(null)

function keyFor(option: DatabaseBackupOption): string {
  return `${option.type}:${option.tenant_id ?? ''}`
}

const databaseOptions = computed(() => options.value.map((o) => ({ value: keyFor(o), label: `${o.label} (${o.database})` })))
const selected = computed(() => options.value.find((o) => keyFor(o) === selectedKey.value) ?? null)

function onDatabaseChange(value: string) {
  selectedKey.value = value
  // The chosen file was named after the previous database — pick again.
  saveHandle.value = null
  success.value = null
}

async function chooseLocation() {
  if (!selected.value || !showSaveFilePicker) return
  error.value = null

  try {
    saveHandle.value = await showSaveFilePicker({
      suggestedName: databaseBackupsService.fileName(selected.value),
      types: [{ description: 'SQL backup', accept: { 'application/sql': ['.sql'] } }],
    })
  } catch (e) {
    // Closing the Save As window without choosing isn't an error.
    if (!(e instanceof DOMException && e.name === 'AbortError')) error.value = t('admin.databaseBackups.chooseLocationFailed')
  }
}

async function backup() {
  const option = selected.value
  if (!option) return

  backingUp.value = true
  error.value = null
  success.value = null

  try {
    if (saveHandle.value) {
      const blob = await databaseBackupsService.fetch(option)
      const writable = await saveHandle.value.createWritable()
      await writable.write(blob)
      await writable.close()
      success.value = t('admin.databaseBackups.savedTo', { file: saveHandle.value.name })
    } else {
      await databaseBackupsService.download(option)
      success.value = t('admin.databaseBackups.downloaded')
    }
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.databaseBackups.downloadFailed')
  } finally {
    backingUp.value = false
  }
}

onMounted(async () => {
  try {
    options.value = await databaseBackupsService.list()
    // A school admin only ever gets their own school — nothing to choose.
    if (options.value.length === 1 && options.value[0]) selectedKey.value = keyFor(options.value[0])
  } catch (e) {
    loadError.value = e instanceof ApiRequestError ? e.message : t('admin.databaseBackups.loadFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="max-w-2xl">
    <BackupTabs />

    <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.databaseBackups.title') }}</h1>
    <p class="mt-1 text-sm text-neutral-500">
      {{ auth.isSuperAdmin ? t('admin.databaseBackups.pageSubtitle') : t('admin.databaseBackups.schoolSubtitle') }}
    </p>

    <BaseSpinner v-if="loading" class="mx-auto mt-8" />
    <BaseAlert v-else-if="loadError" variant="danger" class="mt-6">{{ loadError }}</BaseAlert>
    <p v-else-if="options.length === 0" class="mt-6 text-sm text-neutral-500">{{ t('admin.databaseBackups.emptyMessage') }}</p>

    <div v-else class="mt-6 space-y-5 rounded-[--radius-card] border border-neutral-200 bg-white p-5">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
      <BaseAlert v-if="success" variant="success">{{ success }}</BaseAlert>

      <BaseSelect
        :model-value="selectedKey"
        :options="databaseOptions"
        :placeholder="t('admin.databaseBackups.selectDatabase')"
        :label="t('admin.databaseBackups.database')"
        required
        @update:model-value="onDatabaseChange"
      />

      <div>
        <p class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.databaseBackups.saveLocation') }}</p>
        <div v-if="canChooseLocation" class="flex flex-wrap items-center gap-3">
          <BaseButton variant="outline" :disabled="!selected || backingUp" @click="chooseLocation">
            {{ t('admin.databaseBackups.chooseLocation') }}
          </BaseButton>
          <span class="min-w-0 truncate text-sm" :class="saveHandle ? 'font-medium text-neutral-800' : 'text-neutral-400'">
            {{ saveHandle ? saveHandle.name : t('admin.databaseBackups.noLocationChosen') }}
          </span>
        </div>
        <p v-else class="text-sm text-neutral-500">{{ t('admin.databaseBackups.locationUnsupported') }}</p>
        <p v-if="canChooseLocation" class="mt-1.5 text-xs text-neutral-500">{{ t('admin.databaseBackups.locationHint') }}</p>
      </div>

      <div class="border-t border-neutral-100 pt-4">
        <BaseButton :loading="backingUp" :disabled="!selected" @click="backup">{{ t('admin.databaseBackups.backupNow') }}</BaseButton>
      </div>
    </div>
  </div>
</template>
