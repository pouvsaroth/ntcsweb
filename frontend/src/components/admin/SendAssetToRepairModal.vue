<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { repairShopsService, type RepairShop } from '@/services/repairShops'

const props = defineProps<{
  modelValue: boolean
  submitting?: boolean
  error?: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; confirm: [payload: { repair_shop_id: number | null; problem_description: string }] }>()

const { t } = useI18n()

const repairShopId = ref('')
const problemDescription = ref('')
const shops = ref<RepairShop[]>([])

const shopOptions = computed(() => [{ value: '', label: t('admin.assetRepairs.noRepairShop') }, ...shops.value.map((s) => ({ value: String(s.id), label: s.name }))])

onMounted(async () => {
  shops.value = await repairShopsService.listAll()
})

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    repairShopId.value = ''
    problemDescription.value = ''
  },
)

function submit() {
  emit('confirm', { repair_shop_id: repairShopId.value ? Number(repairShopId.value) : null, problem_description: problemDescription.value })
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.assetRepairs.sendTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <BaseSelect v-model="repairShopId" :options="shopOptions" :label="t('admin.assetRepairs.repairShop')" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.assetRepairs.problemDescription') }}</label>
        <textarea
          v-model="problemDescription"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('admin.assetRepairs.sendToRepair') }}</BaseButton>
    </template>
  </BaseModal>
</template>
