<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { databaseBackupsService, type DatabaseBackupOption } from '@/services/databaseBackups'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const options = ref<DatabaseBackupOption[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const downloadError = ref<string | null>(null)
const downloadingKey = ref<string | null>(null)

function keyFor(option: DatabaseBackupOption): string {
  return `${option.type}:${option.tenant_id ?? ''}`
}

async function load() {
  loading.value = true
  loadError.value = null

  try {
    options.value = await databaseBackupsService.list()
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.databaseBackups.loadFailed')
  } finally {
    loading.value = false
  }
}

async function download(option: DatabaseBackupOption) {
  downloadError.value = null
  downloadingKey.value = keyFor(option)

  try {
    await databaseBackupsService.download(option)
  } catch (error) {
    downloadError.value = error instanceof ApiRequestError ? error.message : t('admin.databaseBackups.downloadFailed')
  } finally {
    downloadingKey.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.databaseBackups.title') }}</h1>
    <p class="mt-1 text-sm text-neutral-500">{{ t('admin.databaseBackups.pageSubtitle') }}</p>

    <BaseSpinner v-if="loading" class="mx-auto mt-8" />
    <BaseAlert v-else-if="loadError" variant="danger" class="mt-6">{{ loadError }}</BaseAlert>

    <template v-else>
      <BaseAlert v-if="downloadError" variant="danger" class="mt-6">{{ downloadError }}</BaseAlert>

      <div class="mt-6 divide-y divide-neutral-100 rounded-[--radius-card] border border-neutral-200 bg-white">
        <p v-if="options.length === 0" class="p-4 text-sm text-neutral-500">{{ t('admin.databaseBackups.emptyMessage') }}</p>

        <div v-for="option in options" :key="keyFor(option)" class="flex items-center justify-between gap-4 p-4">
          <div class="min-w-0">
            <p class="truncate font-medium text-neutral-800">{{ option.label }}</p>
            <p class="truncate text-xs text-neutral-500">{{ option.database }}</p>
          </div>
          <BaseButton
            variant="outline"
            size="sm"
            :loading="downloadingKey === keyFor(option)"
            :disabled="downloadingKey !== null && downloadingKey !== keyFor(option)"
            @click="download(option)"
          >
            {{ t('admin.databaseBackups.download') }}
          </BaseButton>
        </div>
      </div>
    </template>
  </div>
</template>
