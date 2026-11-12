<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { referralsService, type ReferralSettings, type RewardCodeOption } from '@/services/referrals'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'

/** Marketing > Referrals settings: on / off, the reward discount code, and how many days each copy lasts. */
const props = defineProps<{ modelValue: boolean; settings: ReferralSettings | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [settings: ReferralSettings] }>()

const { t } = useI18n()

const form = reactive({ enabled: false, reward_discount_code_id: '', valid_days: '' })
const codes = ref<RewardCodeOption[]>([])
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

function discount(c: RewardCodeOption): string {
  return c.discount_type === 'amount' ? formatMoney(c.value, c.currency) : `${Number(c.value)}%`
}
const codeOptions = computed(() => [
  { value: '', label: t('marketing.referrals.pickCode') },
  ...codes.value.map((c) => ({ value: String(c.id), label: `${c.code} — ${c.name}`, hint: c.status === 'active' ? discount(c) : t('marketing.referrals.inactive') })),
])

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    const s = props.settings
    form.enabled = s?.enabled ?? false
    form.reward_discount_code_id = s?.reward_discount_code_id ? String(s.reward_discount_code_id) : ''
    form.valid_days = s?.valid_days ? String(s.valid_days) : ''
    errors.value = {}
    saveError.value = null
    codes.value = await referralsService.rewardOptions().catch(() => [])
  },
)

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const saved = await referralsService.saveSettings({
      enabled: form.enabled,
      reward_discount_code_id: form.reward_discount_code_id ? Number(form.reward_discount_code_id) : null,
      valid_days: form.valid_days.trim() ? Number(form.valid_days) : null,
    })
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('marketing.referrals.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('marketing.referrals.settingsTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" novalidate @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <label class="flex items-start gap-2 text-sm text-neutral-700">
        <input v-model="form.enabled" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        <span>
          {{ t('marketing.referrals.enabled') }}
          <span class="block text-xs text-neutral-500">{{ t('marketing.referrals.enabledHint') }}</span>
        </span>
      </label>
      <SearchableSelect
        v-model="form.reward_discount_code_id"
        :options="codeOptions"
        :label="t('marketing.referrals.rewardCode')"
        :hint="t('marketing.referrals.rewardCodeHint')"
        :required="form.enabled"
        :error="errors.reward_discount_code_id?.[0]"
      />
      <BaseInput v-model="form.valid_days" type="number" :label="t('marketing.referrals.validDays')" :hint="t('marketing.referrals.validDaysHint')" :error="errors.valid_days?.[0]" />
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="form.enabled && !form.reward_discount_code_id" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
