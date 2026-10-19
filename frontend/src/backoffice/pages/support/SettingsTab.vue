<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ApiRequestError } from '@/types/api'

import { supportApi } from '../../api'
import { useSession } from '../../session'

/** How long a support view lasts before it ends by itself. */
const { t } = useI18n()
const session = useSession()
const canEdit = computed(() => session.can('platform.support.view'))

const loaded = ref(false)
const minutes = ref('30')
const saving = ref(false)
const saved = ref(false)
const error = ref<ApiRequestError | null>(null)

onMounted(async () => {
  minutes.value = String((await supportApi.settings()).duration_minutes)
  loaded.value = true
})

async function save(): Promise<void> {
  saving.value = true
  saved.value = false
  error.value = null
  try {
    minutes.value = String((await supportApi.updateSettings({ duration_minutes: Number(minutes.value) })).duration_minutes)
    saved.value = true
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : null
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div v-if="!loaded" class="flex justify-center py-10"><BaseSpinner /></div>
  <form v-else class="max-w-md space-y-4 rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]" @submit.prevent="save">
    <BaseInput
      v-model="minutes"
      type="number"
      :label="t('bo.support.settings.minutes')"
      :hint="t('bo.support.settings.minutesHint')"
      :error="error?.fieldError('duration_minutes')"
      :disabled="!canEdit"
      required
    />
    <BaseAlert v-if="error && !error.fieldError('duration_minutes')" variant="danger">{{ error.message }}</BaseAlert>
    <BaseAlert v-if="saved" variant="success">{{ t('bo.common.saved') }}</BaseAlert>
    <BaseButton v-if="canEdit" type="submit" :loading="saving">{{ t('bo.common.save') }}</BaseButton>
  </form>
</template>
