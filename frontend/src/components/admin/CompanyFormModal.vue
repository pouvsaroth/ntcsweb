<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { companiesService, type Company } from '@/services/companies'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  company?: Company | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.company != null)

const form = reactive({
  name: '',
  contact_person: '',
  phone: '',
  email: '',
  address: '',
  tax_number: '',
  notes: '',
  is_active: true,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.company] as const,
  ([open]) => {
    if (!open) return

    form.name = props.company?.name ?? ''
    form.contact_person = props.company?.contact_person ?? ''
    form.phone = props.company?.phone ?? ''
    form.email = props.company?.email ?? ''
    form.address = props.company?.address ?? ''
    form.tax_number = props.company?.tax_number ?? ''
    form.notes = props.company?.notes ?? ''
    form.is_active = props.company?.is_active ?? true
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
      await companiesService.update(props.company!.id, { ...form })
    } else {
      await companiesService.create({ ...form })
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.companies.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.companies.editTitle') : t('admin.companies.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.name" required :label="t('admin.companies.name')" :error="errors.name?.[0]" />
      <BaseInput v-model="form.contact_person" :label="t('admin.companies.contactPerson')" :error="errors.contact_person?.[0]" />
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.phone" :label="t('admin.companies.phone')" :error="errors.phone?.[0]" />
        <BaseInput v-model="form.email" type="email" :label="t('admin.companies.email')" :error="errors.email?.[0]" />
      </div>
      <BaseInput v-model="form.tax_number" :label="t('admin.companies.taxNumber')" :error="errors.tax_number?.[0]" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.companies.address') }}</label>
        <textarea
          v-model="form.address"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.companies.notes') }}</label>
        <textarea
          v-model="form.notes"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.companies.statusActive') }}
      </label>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
