<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { departmentsService, type Department } from '@/services/departments'
import { organizationUnitsService, type OrganizationUnit, type OrganizationUnitKind } from '@/services/organizationUnits'
import { ApiRequestError } from '@/types/api'
import { optionalOptions } from '@/utils/organizationOptions'

const props = defineProps<{
  modelValue: boolean
  kind: OrganizationUnitKind
  unit?: OrganizationUnit | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.unit != null)
const isBranch = computed(() => props.kind === 'branches')
const isTeam = computed(() => props.kind === 'teams')
const service = computed(() => organizationUnitsService(props.kind))

const form = reactive({
  code: '',
  name: '',
  description: '',
  is_active: true,
  phone: '',
  address: '',
  department_id: null as number | null,
})

const departments = ref<Department[]>([])
const departmentOptions = computed(() => optionalOptions(departments.value, t('admin.organization.none')))

onMounted(async () => {
  if (isTeam.value) departments.value = await departmentsService.listAll().catch(() => [])
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.unit] as const,
  ([open]) => {
    if (!open) return

    form.code = props.unit?.code ?? ''
    form.name = props.unit?.name ?? ''
    form.description = props.unit?.description ?? ''
    form.is_active = props.unit?.is_active ?? true
    form.phone = props.unit?.phone ?? ''
    form.address = props.unit?.address ?? ''
    form.department_id = props.unit?.department_id ?? null
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  const { phone, address, department_id, ...common } = form
  const input = isBranch.value ? { ...common, phone, address } : isTeam.value ? { ...common, department_id } : common

  try {
    if (isEditing.value) {
      await service.value.update(props.unit!.id, input)
    } else {
      await service.value.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.organization.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t(`admin.organization.kinds.${kind}.edit`) : t(`admin.organization.kinds.${kind}.add`)"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.code" required :label="t('admin.organization.code')" :error="errors.code?.[0]" />
      <BaseInput v-model="form.name" required :label="t('admin.organization.name')" :error="errors.name?.[0]" />

      <BaseSelect
        v-if="isTeam"
        :model-value="form.department_id ? String(form.department_id) : ''"
        :options="departmentOptions"
        :label="t('admin.organization.tabs.department')"
        :error="errors.department_id?.[0]"
        @update:model-value="(value: string) => (form.department_id = value ? Number(value) : null)"
      />

      <template v-if="isBranch">
        <BaseInput v-model="form.phone" :label="t('admin.organization.phone')" :error="errors.phone?.[0]" />
        <BaseInput v-model="form.address" :label="t('admin.organization.address')" :error="errors.address?.[0]" />
      </template>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.organization.description') }}</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.organization.statusActive') }}
      </label>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
