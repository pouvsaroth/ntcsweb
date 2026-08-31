<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { positionsService, type Position } from '@/services/positions'
import { staffService, type Staff } from '@/services/staff'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  /** Present when editing; absent when creating a new staff member. */
  staff?: Staff | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.staff != null)

const form = reactive({
  employee_code: '',
  name: '',
  phone: '',
  email: '',
  position_id: '',
  hire_date: '',
  status: 'active' as 'active' | 'inactive',
})

const positions = ref<Position[]>([])
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

/** Set only right after a successful create — see submit(). Never re-fetchable afterward. */
const temporaryPassword = ref<string | null>(null)

const statusOptions = computed(() => [
  { value: 'active', label: t('admin.staff.statusActive') },
  { value: 'inactive', label: t('admin.staff.statusInactive') },
])

const positionOptions = computed(() => positions.value.map((position) => ({ value: String(position.id), label: position.name })))

onMounted(async () => {
  positions.value = await positionsService.listAll()
})

watch(
  () => [props.modelValue, props.staff] as const,
  ([open]) => {
    if (!open) return

    form.employee_code = props.staff?.employee_code ?? ''
    form.name = props.staff?.name ?? ''
    form.phone = props.staff?.phone ?? ''
    form.email = props.staff?.email ?? ''
    form.position_id = props.staff?.position ? String(props.staff.position.id) : ''
    form.hire_date = props.staff?.hire_date ?? ''
    form.status = props.staff?.status ?? 'active'
    errors.value = {}
    generalError.value = null
    temporaryPassword.value = null
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    if (isEditing.value) {
      await staffService.update(props.staff!.id, { ...form, position_id: Number(form.position_id) })
      emit('saved')
      emit('update:modelValue', false)
    } else {
      const { temporaryPassword: password } = await staffService.create({
        ...form,
        position_id: Number(form.position_id),
      })

      emit('saved')
      // The one-time password takes over this modal instead of closing it —
      // there is no other channel (no SMS, email is optional) to relay it.
      temporaryPassword.value = password
    }
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.staff.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}

function close() {
  emit('update:modelValue', false)
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="temporaryPassword ? t('admin.staff.temporaryPasswordTitle') : isEditing ? t('admin.staff.editTitle') : t('admin.staff.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div v-if="temporaryPassword" class="space-y-4">
      <BaseAlert variant="success">{{ t('admin.staff.temporaryPasswordMessage') }}</BaseAlert>
      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.staff.temporaryPasswordLabel') }}</label>
        <code class="block rounded-lg border border-neutral-300 bg-neutral-50 px-3 py-2 text-sm font-mono text-neutral-900">
          {{ temporaryPassword }}
        </code>
      </div>
    </div>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.employee_code" required :label="t('admin.staff.employeeCode')" :error="errors.employee_code?.[0]" />
        <BaseInput v-model="form.name" required :label="t('admin.staff.name')" :error="errors.name?.[0]" />
        <BaseInput v-model="form.phone" required :label="t('admin.staff.phone')" :error="errors.phone?.[0]" />
        <BaseInput v-model="form.email" type="email" :label="t('admin.staff.email')" :error="errors.email?.[0]" />
        <BaseSelect
          v-model="form.position_id"
          required
          :options="positionOptions"
          :placeholder="t('admin.staff.selectPosition')"
          :label="t('admin.staff.position')"
          :error="errors.position_id?.[0]"
        />
        <BaseInput v-model="form.hire_date" type="date" :label="t('admin.staff.hireDate')" :error="errors.hire_date?.[0]" />
        <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.staff.status')" />
      </div>
    </form>

    <template #footer>
      <template v-if="temporaryPassword">
        <BaseButton @click="close">{{ t('admin.staff.close') }}</BaseButton>
      </template>
      <template v-else>
        <BaseButton variant="outline" @click="close">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
      </template>
    </template>
  </BaseModal>
</template>
