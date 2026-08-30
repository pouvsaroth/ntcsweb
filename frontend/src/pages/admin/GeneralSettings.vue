<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { generalSettingsService } from '@/services/settings'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const studentIdPrefix = ref('')
const loading = ref(true)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const saved = ref(false)
const saving = ref(false)

/**
 * Display only — built from whatever's currently typed, purely so the admin
 * can see the shape of the next ID before saving. The backend is still the
 * only thing that ever actually generates one (see StudentIdGenerator);
 * this never reflects a real sequence position, always `000001`.
 */
const preview = computed(() => `${studentIdPrefix.value || '—'}-000001`)

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const settings = await generalSettingsService.get()
    studentIdPrefix.value = settings.student_id_prefix
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.settings.loadFailed')
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  saveError.value = null
  errors.value = {}
  saved.value = false

  try {
    const result = await generalSettingsService.update(studentIdPrefix.value)
    studentIdPrefix.value = result.student_id_prefix
    saved.value = true
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      saveError.value = error instanceof ApiRequestError ? error.message : t('admin.settings.saveFailed')
    }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-2xl">
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.settings.title') }}</h1>
    </div>

    <BaseAlert v-if="loadError" variant="danger" class="mb-4">{{ loadError }}</BaseAlert>

    <form v-else-if="!loading" class="space-y-6" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <BaseAlert v-if="saved" variant="success">{{ t('admin.settings.saveSuccess') }}</BaseAlert>

      <section class="rounded-lg border border-neutral-200 p-4">
        <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.settings.studentIdSection') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('admin.settings.studentIdHint') }}</p>

        <BaseInput
          v-model="studentIdPrefix"
          required
          :label="t('admin.settings.studentIdPrefix')"
          :hint="t('admin.settings.studentIdPrefixHint')"
          :error="errors.student_id_prefix?.[0]"
          class="max-w-xs"
        />

        <p class="mt-4 text-sm text-neutral-500">
          {{ t('admin.settings.previewLabel') }}
          <code class="ml-1 rounded bg-neutral-100 px-2 py-1 font-mono text-neutral-800">{{ preview }}</code>
        </p>
      </section>

      <BaseButton type="submit" :loading="saving">{{ t('common.save') }}</BaseButton>
    </form>
  </div>
</template>
