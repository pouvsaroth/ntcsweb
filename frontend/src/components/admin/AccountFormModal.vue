<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { accountsService, accountTypes, type Account, type AccountInput, type AccountType } from '@/services/accounting'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  /** Present when editing; absent when creating a new account. */
  account?: Account | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.account != null)

const form = reactive<AccountInput>({
  code: '',
  name: '',
  type: 'EXPENSE',
  parent_id: null,
  description: '',
  is_bank_or_cash: false,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const parentCandidates = ref<Account[]>([])
const loadingParents = ref(false)

function typeKey(type: AccountType): string {
  return type.charAt(0) + type.slice(1).toLowerCase()
}

const typeOptions = computed(() => accountTypes.map((type) => ({ value: type, label: t(`admin.accounts.type${typeKey(type)}`) })))

const parentOptions = computed(() => [
  { value: '', label: t('admin.accounts.noParent') },
  ...parentCandidates.value
    .filter((account) => account.id !== props.account?.id)
    .map((account) => ({ value: String(account.id), label: `${account.code} — ${account.name}` })),
])

function resetForm() {
  form.code = props.account?.code ?? ''
  form.name = props.account?.name ?? ''
  form.type = props.account?.type ?? 'EXPENSE'
  form.parent_id = props.account?.parent_id ?? null
  form.description = props.account?.description ?? ''
  form.is_bank_or_cash = props.account?.is_bank_or_cash ?? false
  errors.value = {}
  generalError.value = null
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) resetForm()
  },
)

onMounted(async () => {
  loadingParents.value = true
  try {
    parentCandidates.value = await accountsService.listAll()
  } finally {
    loadingParents.value = false
  }
})

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    if (isEditing.value && props.account) {
      await accountsService.update(props.account.id, form)
    } else {
      await accountsService.create(form)
    }
    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.accounts.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.accounts.editTitle') : t('admin.accounts.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.code" required :label="t('admin.accounts.code')" :error="errors.code?.[0]" />
        <BaseSelect
          v-model="form.type"
          :options="typeOptions"
          required
          :label="t('admin.accounts.type')"
          :error="errors.type?.[0]"
        />
      </div>

      <BaseInput v-model="form.name" required :label="t('admin.accounts.name')" :error="errors.name?.[0]" />

      <BaseSelect
        :model-value="form.parent_id !== null ? String(form.parent_id) : ''"
        :options="parentOptions"
        :disabled="loadingParents"
        :label="t('admin.accounts.parent')"
        @update:model-value="form.parent_id = $event ? Number($event) : null"
      />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.accounts.description') }}</label>
        <textarea
          v-model="form.description"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_bank_or_cash" type="checkbox" class="rounded border-neutral-300" />
        {{ t('admin.accounts.isBankOrCash') }}
      </label>
      <p class="text-xs text-neutral-500">{{ t('admin.accounts.isBankOrCashHint') }}</p>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
