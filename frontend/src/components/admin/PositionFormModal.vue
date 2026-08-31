<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { positionsService, type Position } from '@/services/positions'
import { rolesService, type Role } from '@/services/roles'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  /** Present when editing; absent when creating a new position. */
  position?: Position | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.position != null)

const form = reactive({
  name: '',
  role_id: '',
  description: '',
  status: 'active' as 'active' | 'inactive',
})

const roles = ref<Role[]>([])
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const statusOptions = computed(() => [
  { value: 'active', label: t('admin.positions.statusActive') },
  { value: 'inactive', label: t('admin.positions.statusInactive') },
])

const roleOptions = computed(() => roles.value.map((role) => ({ value: String(role.id), label: role.name })))

onMounted(async () => {
  roles.value = await rolesService.listAll()
})

watch(
  () => [props.modelValue, props.position] as const,
  ([open]) => {
    if (!open) return

    form.name = props.position?.name ?? ''
    form.role_id = props.position?.role ? String(props.position.role.id) : ''
    form.description = props.position?.description ?? ''
    form.status = props.position?.status ?? 'active'
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const input = { ...form, role_id: Number(form.role_id) }

    if (isEditing.value) {
      await positionsService.update(props.position!.id, input)
    } else {
      await positionsService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.positions.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.positions.editTitle') : t('admin.positions.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.name" required :label="t('admin.positions.positionName')" :error="errors.name?.[0]" />

      <BaseSelect
        v-model="form.role_id"
        required
        :options="roleOptions"
        :placeholder="t('admin.positions.selectRole')"
        :label="t('admin.positions.role')"
        :error="errors.role_id?.[0]"
      />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.positions.description') }}</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.positions.status')" />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
