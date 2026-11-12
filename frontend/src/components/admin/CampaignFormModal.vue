<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import LookupSelect from '@/components/ui/LookupSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { academicProgramsService, type AcademicProgram } from '@/services/academicPrograms'
import {
  CAMPAIGN_STATUSES,
  marketingCampaignsService,
  type CampaignCurrency,
  type CampaignStatus,
  type MarketingCampaign,
  type MarketingCampaignInput,
} from '@/services/marketingCampaigns'
import { ApiRequestError } from '@/types/api'

/** Marketing > Campaigns: add or edit a campaign (channel from Base Data MARKETING_CHANNEL). */
const props = defineProps<{ modelValue: boolean; campaign: MarketingCampaign | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [campaign: MarketingCampaign] }>()

const { t } = useI18n()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const form = reactive({
  name: '',
  channel: '',
  start_on: '',
  end_on: '',
  currency: 'USD' as CampaignCurrency,
  budget: '',
  spent: '',
  academic_program_id: '',
  target: '',
  status: 'planned' as CampaignStatus,
  description: '',
})
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const programs = ref<AcademicProgram[]>([])
const programOptions = computed(() => [
  { value: '', label: t('marketing.campaigns.anyProgram') },
  ...programs.value.map((p) => ({ value: String(p.id), label: p.name })),
])
const currencyOptions = [
  { value: 'USD', label: 'USD ($)' },
  { value: 'KHR', label: 'KHR (៛)' },
]
const statusOptions = computed(() => CAMPAIGN_STATUSES.map((s) => ({ value: s, label: t(`marketing.campaigns.statuses.${s}`) })))

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    const c = props.campaign
    Object.assign(form, {
      name: c?.name ?? '',
      channel: c?.channel ?? '',
      start_on: c?.start_on ?? today(),
      end_on: c?.end_on ?? '',
      currency: c?.currency ?? 'USD',
      budget: c?.budget != null ? String(c.budget) : '',
      spent: c?.spent != null ? String(c.spent) : '',
      academic_program_id: c?.academic_program_id ? String(c.academic_program_id) : '',
      target: c?.target ?? '',
      status: c?.status ?? 'planned',
      description: c?.description ?? '',
    })
    errors.value = {}
    saveError.value = null
    if (programs.value.length === 0) programs.value = await academicProgramsService.listAll().catch(() => [])
  },
  { immediate: true },
)

const numberOrNull = (value: string) => (String(value).trim() === '' ? null : Number(value))

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input: MarketingCampaignInput = {
    name: form.name.trim(),
    channel: form.channel,
    start_on: form.start_on,
    end_on: form.end_on || null,
    currency: form.currency,
    budget: numberOrNull(form.budget),
    spent: numberOrNull(form.spent),
    academic_program_id: form.academic_program_id ? Number(form.academic_program_id) : null,
    target: form.target.trim() || null,
    status: form.status,
    description: form.description.trim() || null,
  }
  try {
    const saved = props.campaign ? await marketingCampaignsService.update(props.campaign.id, input) : await marketingCampaignsService.create(input)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('marketing.campaigns.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    size="lg"
    :title="campaign ? t('marketing.campaigns.edit') : t('marketing.campaigns.add')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" novalidate @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <BaseInput v-model="form.name" required :label="t('marketing.campaigns.name')" :placeholder="t('marketing.campaigns.namePlaceholder')" :error="errors.name?.[0]" />
      <div class="grid gap-4 sm:grid-cols-2">
        <LookupSelect v-model="form.channel" category="MARKETING_CHANNEL" required :label="t('marketing.campaigns.channel')" :error="errors.channel?.[0]" />
        <BaseSelect
          :model-value="form.status"
          :options="statusOptions"
          :label="t('marketing.campaigns.status')"
          :error="errors.status?.[0]"
          @update:model-value="form.status = $event as CampaignStatus"
        />
        <BaseInput v-model="form.start_on" type="date" required :label="t('marketing.campaigns.startOn')" :error="errors.start_on?.[0]" />
        <BaseInput v-model="form.end_on" type="date" :label="t('marketing.campaigns.endOn')" :error="errors.end_on?.[0]" />
        <BaseSelect
          :model-value="form.currency"
          :options="currencyOptions"
          :label="t('marketing.campaigns.currency')"
          :error="errors.currency?.[0]"
          @update:model-value="form.currency = $event as CampaignCurrency"
        />
        <div class="hidden sm:block" />
        <BaseInput v-model="form.budget" type="number" :label="t('marketing.campaigns.budget')" :error="errors.budget?.[0]" />
        <BaseInput v-model="form.spent" type="number" :label="t('marketing.campaigns.spent')" :hint="t('marketing.campaigns.spentHint')" :error="errors.spent?.[0]" />
        <SearchableSelect v-model="form.academic_program_id" :options="programOptions" :label="t('marketing.campaigns.program')" :error="errors.academic_program_id?.[0]" />
        <BaseInput v-model="form.target" :label="t('marketing.campaigns.target')" :placeholder="t('marketing.campaigns.targetPlaceholder')" :error="errors.target?.[0]" />
      </div>
      <BaseInput v-model="form.description" :label="t('marketing.campaigns.description')" :error="errors.description?.[0]" />
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!form.name.trim() || !form.channel || !form.start_on" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
