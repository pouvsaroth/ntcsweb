<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import type { ExpenseEntity } from '@/services/expenseEntities'
import { expenseSupportsService, supportRelationships, type ExpenseSupport, type ExpenseSupportInput, type SupportKind } from '@/services/expenseSupports'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/** Add / edit one Support record: money (amount required) or food (what they brought; an estimate optional). */
const props = defineProps<{
  modelValue: boolean
  support?: ExpenseSupport | null
  entities: ExpenseEntity[]
  /** New records start on the Expense for the list is filtered to. */
  defaultEntityId?: number | null
}>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [support: ExpenseSupport] }>()

const { t } = useI18n()
const auth = useAuthStore()
const isEditing = computed(() => props.support != null)

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const form = reactive<ExpenseSupportInput>({
  name: '',
  phone: '',
  relationship: '',
  support_date: today(),
  kind: 'money',
  amount: '',
  currency: 'USD',
  items: '',
  expense_entity_id: null,
  note: '',
})
/** Kept between adds, so several visitors on one day are quick to enter. */
let lastDate = today()

const entityOptions = computed(() => props.entities.map((e) => ({ value: String(e.id), label: e.name })))
const relationshipOptions = computed(() => [
  { value: '', label: t('admin.expenseSupports.relationshipNone') },
  ...supportRelationships.map((r) => ({ value: r, label: t(`admin.expenseSupports.relationships.${r}`) })),
])
const currencyOptions = computed(() => [
  { value: 'USD', label: t('admin.coursePackages.currencyUsd') },
  { value: 'KHR', label: t('admin.coursePackages.currencyKhr') },
])
const KINDS: SupportKind[] = ['money', 'food']

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const keepOpen = ref(false)
const savedNote = ref<string | null>(null)

function reset(): void {
  const s = props.support
  form.name = s?.name ?? ''
  form.phone = s?.phone ?? ''
  form.relationship = s?.relationship ?? ''
  form.support_date = s?.support_date ?? lastDate
  form.kind = s?.kind ?? 'money'
  form.amount = s?.amount != null ? String(s.amount) : ''
  form.currency = s?.currency ?? ((auth.tenantDefaultCurrency ?? 'USD') as 'USD' | 'KHR')
  form.items = s?.items ?? ''
  form.expense_entity_id = s?.expense_entity_id ?? props.defaultEntityId ?? (props.entities.length === 1 ? props.entities[0]!.id : null)
  form.note = s?.note ?? ''
  errors.value = {}
  generalError.value = null
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    savedNote.value = null
    reset()
  },
  { immediate: true },
)

async function submit(another = false) {
  submitting.value = true
  keepOpen.value = another
  errors.value = {}
  generalError.value = null
  try {
    const saved = isEditing.value ? await expenseSupportsService.update(props.support!.id, form) : await expenseSupportsService.create(form)
    lastDate = form.support_date
    emit('saved', saved)
    if (another && !isEditing.value) {
      // Same day, same Expense for and currency; the person's details start empty.
      const keep = { support_date: form.support_date, expense_entity_id: form.expense_entity_id, currency: form.currency, kind: form.kind }
      reset()
      Object.assign(form, keep)
      savedNote.value = t('admin.expenseSupports.savedAnother', { name: saved.name })
    } else {
      emit('update:modelValue', false)
    }
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) errors.value = error.errors
    else generalError.value = error instanceof ApiRequestError ? error.message : t('admin.expenseSupports.saveFailed')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.expenseSupports.editTitle') : t('admin.expenseSupports.addTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit(false)">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <BaseAlert v-if="savedNote" variant="success">{{ savedNote }}</BaseAlert>

      <div class="grid grid-cols-2 gap-2">
        <label
          v-for="kind in KINDS"
          :key="kind"
          class="flex cursor-pointer items-center justify-center gap-2 rounded-lg border p-2.5 text-sm font-medium"
          :class="form.kind === kind ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-neutral-200 text-neutral-700'"
        >
          <input v-model="form.kind" type="radio" :value="kind" class="sr-only" />
          <span aria-hidden="true">{{ kind === 'money' ? '💵' : '🍲' }}</span>
          {{ t(`admin.expenseSupports.kinds.${kind}`) }}
        </label>
      </div>

      <BaseInput v-model="form.name" required :label="t('admin.expenseSupports.name')" :error="errors.name?.[0]" />
      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.phone" type="tel" :label="t('admin.expenseSupports.phone')" :error="errors.phone?.[0]" />
        <BaseSelect v-model="form.relationship" :options="relationshipOptions" :label="t('admin.expenseSupports.relationship')" :error="errors.relationship?.[0]" />
      </div>
      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.support_date" type="date" required :label="t('admin.expenseSupports.supportDate')" :error="errors.support_date?.[0]" />
        <SearchableSelect
          :model-value="form.expense_entity_id !== null ? String(form.expense_entity_id) : ''"
          :options="entityOptions"
          :placeholder="t('admin.expenses.selectExpenseFor')"
          :label="t('admin.expenses.expenseFor')"
          required
          :error="errors.expense_entity_id?.[0]"
          @update:model-value="form.expense_entity_id = $event ? Number($event) : null"
        />
      </div>

      <BaseInput
        v-if="form.kind === 'food'"
        v-model="form.items"
        required
        :label="t('admin.expenseSupports.items')"
        :placeholder="t('admin.expenseSupports.itemsPlaceholder')"
        :error="errors.items?.[0]"
      />
      <div class="grid grid-cols-2 gap-4">
        <BaseInput
          v-model="form.amount"
          type="number"
          :required="form.kind === 'money'"
          :label="form.kind === 'money' ? t('admin.expenseSupports.amount') : t('admin.expenseSupports.estimate')"
          :error="errors.amount?.[0]"
        />
        <BaseSelect v-model="form.currency" :options="currencyOptions" :label="t('admin.expenses.currency')" :error="errors.currency?.[0]" />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.expenseSupports.note') }}</label>
        <textarea
          v-model="form.note"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
        <p v-if="errors.note?.[0]" class="mt-1 text-xs text-danger-600">{{ errors.note[0] }}</p>
      </div>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton v-if="!isEditing" variant="outline" :loading="submitting && keepOpen" @click="submit(true)">{{ t('admin.expenseSupports.saveAndAdd') }}</BaseButton>
      <BaseButton :loading="submitting && !keepOpen" @click="submit(false)">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
