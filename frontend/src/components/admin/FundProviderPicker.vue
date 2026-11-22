<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import FundProviderFormModal from '@/components/admin/FundProviderFormModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { fundProvidersService, type FundProvider } from '@/services/fundProviders'
import { fundsService, type FundStaffOption } from '@/services/funds'
import { useAuthStore } from '@/stores/auth'

/**
 * Fund > Add fund / Money in: "Provided by" — one search box of the active
 * staff (HRM > Staff) and the active providers (Fund > Providers), with
 * "+ New provider" to add one without leaving the form (it is then picked).
 * v-model is 'staff:12' or 'provider:3' ('' = none); see providedByIds().
 */
defineProps<{ modelValue: string; error?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const { t } = useI18n()
const auth = useAuthStore()
const canAdd = computed(() => auth.can('funds.create'))

const staff = ref<FundStaffOption[]>([])
const providers = ref<FundProvider[]>([])
const options = computed(() => [
  ...staff.value.map((s) => ({
    value: `staff:${s.id}`,
    // The tag first, so a long name never cuts it off.
    label: `${t('admin.funds.staffTag')}: ${s.name}${s.employee_code ? ` · ${s.employee_code}` : ''}`,
  })),
  ...providers.value.map((p) => ({ value: `provider:${p.id}`, label: p.phone ? `${p.name} · ${p.phone}` : p.name })),
])
const addOpen = ref(false)

onMounted(async () => {
  const [staffList, providerList] = await Promise.all([fundsService.staffOptions().catch(() => []), fundProvidersService.listAll().catch(() => [])])
  staff.value = staffList
  providers.value = providerList
})

function onAdded(provider: FundProvider) {
  providers.value = [...providers.value, provider].sort((a, b) => a.name.localeCompare(b.name))
  emit('update:modelValue', `provider:${provider.id}`)
}
</script>

<template>
  <div>
    <SearchableSelect
      :model-value="modelValue"
      :options="options"
      :label="t('admin.funds.providedBy')"
      :placeholder="t('admin.funds.selectProvider')"
      :error="error"
      @update:model-value="emit('update:modelValue', String($event ?? ''))"
    />
    <button v-if="canAdd" type="button" class="mt-1 text-xs font-medium text-primary-600 hover:text-primary-700" @click="addOpen = true">
      {{ t('admin.funds.newProvider') }}
    </button>
    <FundProviderFormModal v-model="addOpen" @saved="onAdded" />
  </div>
</template>
