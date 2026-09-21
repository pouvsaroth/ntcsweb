<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { projectLabelsService, type ProjectLabel } from '@/services/projects'
import { ApiRequestError } from '@/types/api'

/**
 * The tenant-wide label catalog as a toggleable chip picker, with an inline
 * "add a new label" affordance — see ProjectLabelController's docblock for
 * why the catalog itself has no per-project scope.
 */
const props = defineProps<{ modelValue: number[] }>()
const emit = defineEmits<{ 'update:modelValue': [value: number[]] }>()

const { t } = useI18n()

const labels = ref<ProjectLabel[]>([])
const loading = ref(false)
const newLabelName = ref('')
const creating = ref(false)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    labels.value = await projectLabelsService.list()
  } finally {
    loading.value = false
  }
}

onMounted(() => load())

function isSelected(id: number): boolean {
  return props.modelValue.includes(id)
}

function toggle(id: number) {
  emit('update:modelValue', isSelected(id) ? props.modelValue.filter((v) => v !== id) : [...props.modelValue, id])
}

async function addLabel() {
  const name = newLabelName.value.trim()
  if (!name) return

  creating.value = true
  error.value = null

  try {
    const label = await projectLabelsService.create(name)
    labels.value = [...labels.value, label].sort((a, b) => a.name.localeCompare(b.name))
    emit('update:modelValue', [...props.modelValue, label.id])
    newLabelName.value = ''
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.projects.labelSaveFailed')
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <div>
    <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.projects.labelsTitle') }}</label>

    <div v-if="loading" class="flex py-1"><BaseSpinner size="sm" /></div>
    <div v-else class="flex flex-wrap gap-1.5">
      <button
        v-for="option in labels"
        :key="option.id"
        type="button"
        class="rounded-full px-2.5 py-1 text-xs font-medium transition-colors"
        :class="isSelected(option.id) ? 'bg-primary-600 text-white' : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200'"
        @click="toggle(option.id)"
      >
        {{ option.name }}
      </button>
      <p v-if="labels.length === 0" class="text-sm text-neutral-400">{{ t('admin.projects.noLabelsYet') }}</p>
    </div>

    <div class="mt-2 flex gap-2">
      <input
        v-model="newLabelName"
        type="text"
        :placeholder="t('admin.projects.newLabelPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-1.5 text-sm text-neutral-900 shadow-sm placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @keydown.enter.prevent="addLabel"
      />
      <BaseButton type="button" size="sm" variant="outline" :loading="creating" :disabled="!newLabelName.trim()" @click="addLabel">
        {{ t('admin.projects.addLabel') }}
      </BaseButton>
    </div>
    <p v-if="error" class="mt-1.5 text-sm text-danger-600">{{ error }}</p>
  </div>
</template>
