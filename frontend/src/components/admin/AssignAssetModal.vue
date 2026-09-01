<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { assignableTypes, type AssignableType } from '@/services/assets'
import { staffService, type Staff } from '@/services/staff'
import { studentsService, type Student } from '@/services/students'

const props = defineProps<{
  modelValue: boolean
  submitting?: boolean
  error?: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  confirm: [payload: { assignable_type: AssignableType; assignable_id: number; expected_return_date?: string; notes?: string }]
}>()

const { t } = useI18n()

const assignableType = ref<AssignableType>('staff')
const assignableId = ref('')
const expectedReturnDate = ref('')
const notes = ref('')

const staffOptions = ref<Staff[]>([])
const studentOptions = ref<Student[]>([])
const loadingOptions = ref(false)

const typeOptions = computed(() => assignableTypes.map((type) => ({ value: type, label: t(`admin.assets.assignableType${type.charAt(0).toUpperCase()}${type.slice(1)}`) })))

const entityOptions = computed(() => {
  if (assignableType.value === 'staff') return staffOptions.value.map((s) => ({ value: String(s.id), label: `${s.first_name} ${s.last_name}` }))
  if (assignableType.value === 'student') return studentOptions.value.map((s) => ({ value: String(s.id), label: `${s.first_name} ${s.last_name}` }))
  return []
})

const needsManualId = computed(() => assignableType.value === 'user' || assignableType.value === 'department' || assignableType.value === 'classroom')

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    assignableType.value = 'staff'
    assignableId.value = ''
    expectedReturnDate.value = ''
    notes.value = ''

    loadingOptions.value = true
    try {
      const [staffResult, studentResult] = await Promise.all([
        staffService.list({ page: 1, per_page: 200 }),
        studentsService.list({ page: 1, per_page: 200 }),
      ])
      staffOptions.value = staffResult.data
      studentOptions.value = studentResult.data
    } finally {
      loadingOptions.value = false
    }
  },
)

function submit() {
  if (!assignableId.value) return
  emit('confirm', {
    assignable_type: assignableType.value,
    assignable_id: Number(assignableId.value),
    expected_return_date: expectedReturnDate.value || undefined,
    notes: notes.value || undefined,
  })
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.assets.assignTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <BaseSelect v-model="assignableType" :options="typeOptions" :label="t('admin.assets.assignToType')" @update:model-value="assignableId = ''" />

      <BaseSelect
        v-if="!needsManualId"
        v-model="assignableId"
        :options="entityOptions"
        :disabled="loadingOptions"
        :placeholder="t('admin.assets.selectAssignee')"
        :label="t('admin.assets.assignee')"
      />
      <BaseInput v-else v-model="assignableId" type="number" :label="t('admin.assets.assigneeId')" :hint="t('admin.assets.assigneeIdHint')" />

      <BaseInput v-model="expectedReturnDate" type="date" :label="t('admin.assets.expectedReturnDate')" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.assets.notes') }}</label>
        <textarea
          v-model="notes"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="!assignableId" @click="submit">{{ t('admin.assets.assign') }}</BaseButton>
    </template>
  </BaseModal>
</template>
