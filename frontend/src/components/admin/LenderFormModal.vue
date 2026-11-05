<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { lendersService, type Lender, type LenderInput, type LenderType } from '@/services/borrowings'
import { ApiRequestError } from '@/types/api'

/** Accounting > Borrowings: add or edit a lender (also opened from the loan form). */
const props = defineProps<{ modelValue: boolean; lender?: Lender | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [lender: Lender] }>()

const { t } = useI18n()

const form = reactive<LenderInput>({ name: '', type: 'company', contact_person: '', phone: '', email: '', address: '', notes: '', is_active: true })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const typeOptions = computed(() => [
  { value: 'company', label: t('admin.borrowings.lenderType.company') },
  { value: 'person', label: t('admin.borrowings.lenderType.person') },
])

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    const l = props.lender
    form.name = l?.name ?? ''
    form.type = l?.type ?? 'company'
    form.contact_person = l?.contact_person ?? ''
    form.phone = l?.phone ?? ''
    form.email = l?.email ?? ''
    form.address = l?.address ?? ''
    form.notes = l?.notes ?? ''
    form.is_active = l?.is_active ?? true
    errors.value = {}
    saveError.value = null
  },
  { immediate: true },
)

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const saved = props.lender ? await lendersService.update(props.lender.id, form) : await lendersService.create(form)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.borrowings.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="lender ? t('admin.borrowings.editLender') : t('admin.borrowings.addLender')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_10rem]">
        <BaseInput v-model="form.name" required :label="t('admin.borrowings.lenderName')" :error="errors.name?.[0]" />
        <BaseSelect :model-value="form.type" :options="typeOptions" :label="t('admin.borrowings.lenderTypeLabel')" @update:model-value="form.type = $event as LenderType" />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-if="form.type === 'company'" v-model="form.contact_person" :label="t('admin.borrowings.contactPerson')" :error="errors.contact_person?.[0]" />
        <BaseInput v-model="form.phone" :label="t('admin.borrowings.phone')" :error="errors.phone?.[0]" />
        <BaseInput v-model="form.email" type="email" :label="t('admin.borrowings.email')" :error="errors.email?.[0]" />
      </div>
      <BaseInput v-model="form.address" :label="t('admin.borrowings.address')" :error="errors.address?.[0]" />
      <BaseInput v-model="form.notes" :label="t('admin.borrowings.notes')" :error="errors.notes?.[0]" />
      <label v-if="lender" class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300" />
        {{ t('admin.borrowings.active') }}
      </label>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
