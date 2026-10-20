<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { ApiRequestError } from '@/types/api'

import { schoolsApi, type School } from '../api'

/**
 * Delete a customer for good, with all its data (owner-level permission).
 * Staff type the customer's code to confirm; its backups go too unless kept.
 */
const props = defineProps<{ school: School }>()

const { t } = useI18n()
const router = useRouter()

const open = ref(false)
const typed = ref('')
const keepBackups = ref(false)
const busy = ref(false)
const error = ref<ApiRequestError | null>(null)

function start(): void {
  typed.value = ''
  keepBackups.value = false
  error.value = null
  open.value = true
}

async function remove(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    await schoolsApi.destroy(props.school.id, typed.value.trim(), keepBackups.value)
    open.value = false
    await router.replace('/schools')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="rounded-[--radius-card] border border-red-200 bg-white p-5 shadow-[--shadow-card]">
    <h2 class="font-semibold text-red-700">{{ t('bo.schools.delete.title') }}</h2>
    <p class="mt-1 text-sm text-neutral-600">{{ t('bo.schools.delete.hint') }}</p>
    <BaseButton variant="danger" size="sm" class="mt-3" @click="start">{{ t('bo.schools.delete.button') }}</BaseButton>

    <BaseModal v-model="open" :title="t('bo.schools.delete.title')" size="sm">
      <form id="bo-delete-school" class="space-y-4" @submit.prevent="remove">
        <BaseAlert variant="danger">{{ t('bo.schools.delete.warning', { name: school.name }) }}</BaseAlert>
        <ul class="list-disc space-y-1 pl-5 text-sm text-neutral-700">
          <li>{{ t('bo.schools.delete.what1') }}</li>
          <li>{{ t('bo.schools.delete.what2') }}</li>
          <li>{{ t('bo.schools.delete.what3') }}</li>
        </ul>
        <label class="flex cursor-pointer items-center gap-2 text-sm text-neutral-800">
          <input v-model="keepBackups" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('bo.schools.delete.keepBackups') }}
        </label>
        <BaseInput
          v-model="typed"
          :label="t('bo.schools.delete.typeCode', { code: school.slug })"
          :error="error?.fieldError('confirm')"
          autocomplete="off"
          required
        />
        <BaseAlert v-if="error && !error.errors" variant="danger">{{ error.message }}</BaseAlert>
      </form>
      <template #footer>
        <BaseButton variant="ghost" @click="open = false">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton type="submit" form="bo-delete-school" variant="danger" :loading="busy" :disabled="typed.trim() !== school.slug">
          {{ t('bo.schools.delete.confirm') }}
        </BaseButton>
      </template>
    </BaseModal>
  </section>
</template>
