<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { ApiRequestError } from '@/types/api'

import { billingApi, type PlanModule, type Product } from '../api'
import { moduleLabel } from '../billing'

/** Add or edit a system (stage 9a): its names, logo and the modules it is built from. */
const props = defineProps<{ modelValue: boolean; product: Product | null; modules: PlanModule[] }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; saved: [product: Product] }>()

const { t, locale } = useI18n()

const blank = () => ({ code: '', name: '', name_km: '', description: '', description_km: '', modules: [] as string[], sort_order: '0' })
const form = ref(blank())
const logo = ref<File | null>(null)
const saving = ref(false)
const error = ref<ApiRequestError | null>(null)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    error.value = null
    logo.value = null
    const p = props.product
    form.value = p
      ? {
          code: p.code,
          name: p.name,
          name_km: p.name_km ?? '',
          description: p.description ?? '',
          description_km: p.description_km ?? '',
          modules: [...p.modules],
          sort_order: String(p.sort_order),
        }
      : blank()
  },
)

function toggle(code: string, on: boolean): void {
  form.value.modules = on ? [...form.value.modules, code] : form.value.modules.filter((m) => m !== code)
}

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  const body = {
    code: form.value.code.trim().toLowerCase(),
    name: form.value.name.trim(),
    name_km: form.value.name_km.trim() || null,
    description: form.value.description.trim() || null,
    description_km: form.value.description_km.trim() || null,
    modules: form.value.modules,
    sort_order: Number(form.value.sort_order) || 0,
  }
  try {
    let saved = props.product ? await billingApi.updateProduct(props.product.id, body) : await billingApi.createProduct(body)
    if (logo.value) saved = await billingApi.uploadProductLogo(saved.id, logo.value)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    saving.value = false
  }
}

const textarea =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none'
</script>

<template>
  <BaseModal :model-value="modelValue" :title="product ? t('bo.billing.systems.edit') : t('bo.billing.systems.add')" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <form id="bo-product" class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="error && !error.errors" variant="danger">{{ error.message }}</BaseAlert>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.name" :label="t('bo.billing.systems.name')" :error="error?.fieldError('name')" required />
        <BaseInput v-model="form.name_km" :label="t('bo.billing.systems.nameKm')" :error="error?.fieldError('name_km')" />
        <BaseInput
          v-model="form.code"
          :label="t('bo.billing.plans.code')"
          :hint="t('bo.billing.systems.codeHint')"
          :error="error?.fieldError('code')"
          :disabled="product?.is_school"
          required
        />
        <BaseInput v-model="form.sort_order" type="number" :label="t('bo.billing.systems.order')" :error="error?.fieldError('sort_order')" />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.billing.systems.description') }}</span>
          <textarea v-model="form.description" rows="3" :class="textarea" />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.billing.systems.descriptionKm') }}</span>
          <textarea v-model="form.description_km" rows="3" :class="textarea" />
        </label>
      </div>

      <div>
        <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bo.billing.systems.logo') }}</p>
        <div class="flex items-center gap-3">
          <img v-if="product?.logo_url && !logo" :src="product.logo_url" alt="" class="h-12 w-12 rounded border border-neutral-200 object-contain" />
          <input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="text-sm" @change="logo = ($event.target as HTMLInputElement).files?.[0] ?? null" />
        </div>
        <p v-if="error?.fieldError('logo')" class="mt-1 text-xs text-red-600">{{ error.fieldError('logo') }}</p>
      </div>

      <div>
        <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bo.billing.systems.modules') }}</p>
        <div class="grid gap-2 sm:grid-cols-3">
          <label
            v-for="module in modules"
            :key="module.code"
            class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm"
            :class="form.modules.includes(module.code) ? 'border-primary-500 bg-primary-50 text-neutral-900' : 'border-neutral-200 text-neutral-600'"
          >
            <input
              type="checkbox"
              class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              :checked="form.modules.includes(module.code)"
              @change="toggle(module.code, ($event.target as HTMLInputElement).checked)"
            />
            {{ moduleLabel(module, locale) }}
          </label>
        </div>
        <p class="mt-1.5 text-sm text-neutral-500">{{ t('bo.billing.systems.modulesHint') }}</p>
        <p v-if="error?.fieldError('modules')" class="mt-1 text-xs text-red-600">{{ error.fieldError('modules') }}</p>
      </div>
    </form>
    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="bo-product" :loading="saving">{{ t('bo.common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
