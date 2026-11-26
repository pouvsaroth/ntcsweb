<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { communeMembersService, type MemberImportResult } from '@/services/communeMembers'
import { ApiRequestError } from '@/types/api'

/**
 * Political Party > Commune members: load a year's full and reserve members
 * from Excel (staff by Employee code). Nothing is saved when a row has a
 * problem; the problems are listed by row.
 */
const props = defineProps<{ modelValue: boolean; year: number }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; imported: [year: number] }>()

const { t } = useI18n()

const year = ref('')
const file = ref<File | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const result = ref<MemberImportResult | null>(null)
const error = ref<string | null>(null)
const busy = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    year.value = String(props.year)
    file.value = null
    if (fileInput.value) fileInput.value.value = ''
    result.value = null
    error.value = null
  },
)

async function template() {
  error.value = null
  try {
    await communeMembersService.template()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.communeMembers.importFailed')
  }
}

async function run() {
  if (!file.value) return
  busy.value = true
  error.value = null
  result.value = null
  try {
    result.value = await communeMembersService.importFile(Number(year.value), file.value)
    if (result.value.problems.length === 0) emit('imported', Number(year.value))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.communeMembers.importFailed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.communeMembers.import')" @update:model-value="emit('update:modelValue', $event)">
    <div class="space-y-4">
      <p class="text-sm text-neutral-600">{{ t('admin.communeMembers.importHelp') }}</p>
      <button type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="template">{{ t('admin.communeMembers.template') }}</button>
      <BaseInput v-model="year" type="number" required :label="t('admin.communeMembers.year')" />
      <input
        ref="fileInput"
        type="file"
        accept=".xlsx"
        class="block w-full text-sm text-neutral-700 file:mr-3 file:rounded-lg file:border-0 file:bg-neutral-100 file:px-3 file:py-2 file:text-sm file:font-medium"
        @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null"
      />
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
      <BaseAlert v-if="result && result.problems.length === 0" variant="success">{{ t('admin.communeMembers.importDone', { added: result.added, moved: result.moved }) }}</BaseAlert>
      <div v-if="result && result.problems.length" class="space-y-2">
        <BaseAlert variant="danger">{{ t('admin.communeMembers.importProblems') }}</BaseAlert>
        <ul class="max-h-60 space-y-1 overflow-y-auto text-sm text-neutral-700">
          <li v-for="(p, i) in result.problems" :key="i">
            <span v-if="p.row" class="font-medium">{{ p.sheet }} · {{ t('admin.communeMembers.row', { n: p.row }) }}:</span> {{ p.message }}
          </li>
        </ul>
      </div>
    </div>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="busy" :disabled="!file || !year" @click="run">{{ t('admin.communeMembers.importRun') }}</BaseButton>
    </template>
  </BaseModal>
</template>
