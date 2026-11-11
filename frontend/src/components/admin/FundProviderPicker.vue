<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import FundProviderFormModal from '@/components/admin/FundProviderFormModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { fundProvidersService, type FundProvider } from '@/services/fundProviders'
import { useAuthStore } from '@/stores/auth'

/**
 * Fund > Money in: "Provided by" — a search box of the active providers,
 * with "+ New provider" to add one without leaving the form (it is then picked).
 * v-model is the provider id as a string ('' = none).
 */
defineProps<{ modelValue: string; error?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const { t } = useI18n()
const auth = useAuthStore()
const canAdd = computed(() => auth.can('funds.create'))

const providers = ref<FundProvider[]>([])
const options = computed(() => providers.value.map((p) => ({ value: String(p.id), label: p.phone ? `${p.name} · ${p.phone}` : p.name })))
const addOpen = ref(false)

onMounted(async () => {
  providers.value = await fundProvidersService.listAll().catch(() => [])
})

function onAdded(provider: FundProvider) {
  providers.value = [...providers.value, provider].sort((a, b) => a.name.localeCompare(b.name))
  emit('update:modelValue', String(provider.id))
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
