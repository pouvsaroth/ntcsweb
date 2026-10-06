<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { departmentsService, type Department } from '@/services/departments'
import { organizationUnitsService, type OrganizationUnit } from '@/services/organizationUnits'
import { ApiRequestError } from '@/types/api'
import { optionalOptions } from '@/utils/organizationOptions'

const props = defineProps<{
  modelValue: boolean
  department?: Department | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.department != null)

const form = reactive({
  branch_id: null as number | null,
  code: '',
  name: '',
  description: '',
  is_active: true,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.department] as const,
  ([open]) => {
    if (!open) return

    form.branch_id = props.department?.branch_id ?? null
    form.code = props.department?.code ?? ''
    form.name = props.department?.name ?? ''
    form.description = props.department?.description ?? ''
    form.is_active = props.department?.is_active ?? true
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

// Branches are an HRM > Organization Management list — someone reaching
// Departments through the Assets menu may not be allowed to read them, and
// then the picker simply stays empty rather than breaking the form.
const branches = ref<OrganizationUnit[]>([])
const branchOptions = computed(() => optionalOptions(branches.value, t('admin.organization.none')))

onMounted(async () => {
  branches.value = await organizationUnitsService('branches').listAll().catch(() => [])
})

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    if (isEditing.value) {
      await departmentsService.update(props.department!.id, { ...form })
    } else {
      await departmentsService.create({ ...form })
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.departments.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.departments.editTitle') : t('admin.departments.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.code" required :label="t('admin.departments.code')" :error="errors.code?.[0]" />
      <BaseInput v-model="form.name" required :label="t('admin.departments.name')" :error="errors.name?.[0]" />
      <BaseSelect
        v-if="branches.length > 0 || form.branch_id"
        :model-value="form.branch_id ? String(form.branch_id) : ''"
        :options="branchOptions"
        :label="t('admin.organization.tabs.branch')"
        :error="errors.branch_id?.[0]"
        @update:model-value="(value: string) => (form.branch_id = value ? Number(value) : null)"
      />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.departments.description') }}</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.departments.statusActive') }}
      </label>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
