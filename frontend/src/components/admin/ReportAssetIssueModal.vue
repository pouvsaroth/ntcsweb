<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { issuePriorities, type IssuePriority } from '@/services/assetIssues'

const props = defineProps<{
  modelValue: boolean
  submitting?: boolean
  error?: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; confirm: [payload: { title: string; priority: IssuePriority; description: string }] }>()

const { t } = useI18n()

const title = ref('')
const priority = ref<IssuePriority>('MEDIUM')
const description = ref('')

const priorityOptions = computed(() => issuePriorities.map((p) => ({ value: p, label: t(`admin.assetIssues.priority${p.charAt(0)}${p.slice(1).toLowerCase()}`) })))

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    title.value = ''
    priority.value = 'MEDIUM'
    description.value = ''
  },
)

function submit() {
  if (!title.value.trim()) return
  emit('confirm', { title: title.value.trim(), priority: priority.value, description: description.value })
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.assetIssues.reportTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <BaseInput v-model="title" required :label="t('admin.assetIssues.issueTitle')" />
      <BaseSelect v-model="priority" :options="priorityOptions" :label="t('admin.assetIssues.priority')" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.assetIssues.description') }}</label>
        <textarea
          v-model="description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="!title.trim()" @click="submit">{{ t('admin.assetIssues.report') }}</BaseButton>
    </template>
  </BaseModal>
</template>
