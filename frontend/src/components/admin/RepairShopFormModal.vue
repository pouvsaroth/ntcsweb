<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { repairShopsService, type RepairShop } from '@/services/repairShops'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  repairShop?: RepairShop | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.repairShop != null)

const form = reactive({
  name: '',
  contact_person: '',
  phone: '',
  email: '',
  address: '',
  specialization: '',
  notes: '',
  is_active: true,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.repairShop] as const,
  ([open]) => {
    if (!open) return

    form.name = props.repairShop?.name ?? ''
    form.contact_person = props.repairShop?.contact_person ?? ''
    form.phone = props.repairShop?.phone ?? ''
    form.email = props.repairShop?.email ?? ''
    form.address = props.repairShop?.address ?? ''
    form.specialization = props.repairShop?.specialization ?? ''
    form.notes = props.repairShop?.notes ?? ''
    form.is_active = props.repairShop?.is_active ?? true
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
    if (isEditing.value) {
      await repairShopsService.update(props.repairShop!.id, { ...form })
    } else {
      await repairShopsService.create({ ...form })
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.repairShops.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.repairShops.editTitle') : t('admin.repairShops.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.name" required :label="t('admin.repairShops.name')" :error="errors.name?.[0]" />
      <BaseInput v-model="form.contact_person" :label="t('admin.repairShops.contactPerson')" :error="errors.contact_person?.[0]" />
      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.phone" :label="t('admin.repairShops.phone')" :error="errors.phone?.[0]" />
        <BaseInput v-model="form.email" type="email" :label="t('admin.repairShops.email')" :error="errors.email?.[0]" />
      </div>
      <BaseInput v-model="form.specialization" :label="t('admin.repairShops.specialization')" :error="errors.specialization?.[0]" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.repairShops.address') }}</label>
        <textarea
          v-model="form.address"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.repairShops.notes') }}</label>
        <textarea
          v-model="form.notes"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.repairShops.statusActive') }}
      </label>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
