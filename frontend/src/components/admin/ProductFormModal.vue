<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import {
  productsService,
  productTypes,
  type Product,
  type ProductInput,
  type ProductType,
  type ProductVariant,
  type ProductVariantInput,
} from '@/services/products'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  /** Present when editing; absent when creating a new product. */
  product?: Product | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.product != null)
const productId = computed(() => props.product?.id ?? null)

const form = reactive<ProductInput>({
  code: '',
  name: '',
  description: '',
  type: 'OTHER',
  price: '',
  is_active: true,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const typeOptions = computed(() =>
  productTypes.map((type) => ({ value: type, label: t(`admin.products.type${typeKey(type)}`) })),
)

function typeKey(type: ProductType): string {
  return type
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

// --- Variants (only manageable once the product exists) ------------------

const variants = ref<ProductVariant[]>([])
const loadingVariants = ref(false)
const variantsError = ref<string | null>(null)

const variantForm = reactive<ProductVariantInput>({ name: '', price_override: '', is_active: true })
const editingVariantId = ref<number | null>(null)
const variantErrors = ref<Record<string, string[]>>({})
const variantGeneralError = ref<string | null>(null)
const variantSubmitting = ref(false)

function resetVariantForm() {
  variantForm.name = ''
  variantForm.price_override = ''
  variantForm.is_active = true
  editingVariantId.value = null
  variantErrors.value = {}
  variantGeneralError.value = null
}

function editVariant(variant: ProductVariant) {
  editingVariantId.value = variant.id
  variantForm.name = variant.name
  variantForm.price_override = variant.price_override !== null ? String(variant.price_override) : ''
  variantForm.is_active = variant.is_active
  variantErrors.value = {}
  variantGeneralError.value = null
}

async function submitVariant() {
  if (!productId.value) return

  variantSubmitting.value = true
  variantErrors.value = {}
  variantGeneralError.value = null

  try {
    if (editingVariantId.value !== null) {
      const updated = await productsService.updateVariant(editingVariantId.value, variantForm)
      const index = variants.value.findIndex((v) => v.id === updated.id)
      if (index !== -1) variants.value.splice(index, 1, updated)
    } else {
      variants.value.push(await productsService.createVariant(productId.value, variantForm))
    }
    resetVariantForm()
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      variantErrors.value = error.errors
    } else {
      variantGeneralError.value = error instanceof ApiRequestError ? error.message : t('admin.products.variantSaveFailed')
    }
  } finally {
    variantSubmitting.value = false
  }
}

async function removeVariant(variant: ProductVariant) {
  if (!window.confirm(t('admin.products.deleteVariantConfirm'))) return

  try {
    await productsService.removeVariant(variant.id)
    variants.value = variants.value.filter((v) => v.id !== variant.id)
    if (editingVariantId.value === variant.id) resetVariantForm()
  } catch (error) {
    variantsError.value = error instanceof ApiRequestError ? error.message : t('admin.products.variantSaveFailed')
  }
}

watch(
  () => [props.modelValue, props.product] as const,
  async ([open]) => {
    if (!open) return

    errors.value = {}
    generalError.value = null
    resetVariantForm()
    variantsError.value = null
    variants.value = []

    if (!props.product) {
      form.code = ''
      form.name = ''
      form.description = ''
      form.type = 'OTHER'
      form.price = ''
      form.is_active = true
      return
    }

    // The list endpoint doesn't eager-load variants (only show()/update()
    // do) — refetch the full product so the Variants section always starts
    // from an accurate list, not whatever the list row happened to carry.
    loadingVariants.value = true
    try {
      const fresh = await productsService.get(props.product.id)
      form.code = fresh.code
      form.name = fresh.name
      form.description = fresh.description ?? ''
      form.type = fresh.type
      form.price = String(fresh.price)
      form.is_active = fresh.is_active
      variants.value = fresh.variants ?? []
    } catch (error) {
      variantsError.value = error instanceof ApiRequestError ? error.message : t('admin.products.loadFailed')
    } finally {
      loadingVariants.value = false
    }
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    if (isEditing.value) {
      await productsService.update(props.product!.id, form)
    } else {
      await productsService.create(form)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.products.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.products.editTitle') : t('admin.products.createTitle')"
    size="lg"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.code" required :label="t('admin.products.code')" :error="errors.code?.[0]" />
        <BaseInput v-model="form.name" required :label="t('admin.products.name')" :error="errors.name?.[0]" />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.products.description') }}</label>
        <textarea
          v-model="form.description"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
        <p v-if="errors.description?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.description[0] }}</p>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <BaseSelect v-model="form.type" :options="typeOptions" :label="t('admin.products.type')" />
        <BaseInput v-model="form.price" type="number" required :label="t('admin.products.price')" :error="errors.price?.[0]" />
      </div>

      <label class="flex items-center gap-2 text-sm font-medium text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-200" />
        {{ t('admin.products.isActive') }}
      </label>
    </form>

    <section v-if="isEditing" class="mt-6 border-t border-neutral-100 pt-5">
      <h3 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.products.variantsSection') }}</h3>
      <p class="mb-3 text-sm text-neutral-500">{{ t('admin.products.variantsHint') }}</p>

      <BaseAlert v-if="variantsError" variant="danger" class="mb-3">{{ variantsError }}</BaseAlert>

      <BaseSpinner v-if="loadingVariants" class="mx-auto my-4" />

      <template v-else>
        <div v-if="variants.length > 0" class="mb-3 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <div v-for="variant in variants" :key="variant.id" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
            <div class="min-w-0">
              <p class="font-medium text-neutral-800">{{ variant.name }}</p>
              <p class="text-xs text-neutral-500">
                {{ variant.price_override !== null ? `$${variant.price_override.toFixed(2)}` : t('admin.products.variantUsesProductPrice') }}
              </p>
            </div>
            <BaseBadge :variant="variant.is_active ? 'success' : 'neutral'">
              {{ variant.is_active ? t('admin.products.statusActive') : t('admin.products.statusInactive') }}
            </BaseBadge>
            <div class="flex shrink-0 gap-2">
              <button type="button" class="text-sm font-medium text-primary-700 hover:text-primary-800" @click="editVariant(variant)">
                {{ t('common.edit') }}
              </button>
              <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeVariant(variant)">
                {{ t('admin.products.delete') }}
              </button>
            </div>
          </div>
        </div>
        <p v-else class="mb-3 text-sm text-neutral-400">{{ t('admin.products.noVariants') }}</p>

        <form class="rounded-lg border border-dashed border-neutral-300 p-3" @submit.prevent="submitVariant">
          <BaseAlert v-if="variantGeneralError" variant="danger" class="mb-3">{{ variantGeneralError }}</BaseAlert>

          <div class="grid grid-cols-[2fr_1fr_auto] items-end gap-3">
            <BaseInput
              v-model="variantForm.name"
              required
              :label="t('admin.products.variantName')"
              :error="variantErrors.name?.[0]"
            />
            <BaseInput
              v-model="variantForm.price_override"
              type="number"
              :label="t('admin.products.variantPriceOverride')"
              :hint="t('admin.products.variantPriceOverrideHint')"
              :error="variantErrors.price_override?.[0]"
            />
            <label class="mb-2 flex items-center gap-1.5 text-sm text-neutral-700">
              <input v-model="variantForm.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-200" />
              {{ t('admin.products.variantActive') }}
            </label>
          </div>

          <div class="mt-3 flex gap-2">
            <BaseButton type="submit" size="sm" :loading="variantSubmitting">
              {{ editingVariantId !== null ? t('common.save') : t('admin.products.addVariant') }}
            </BaseButton>
            <BaseButton v-if="editingVariantId !== null" type="button" variant="outline" size="sm" @click="resetVariantForm">
              {{ t('common.cancel') }}
            </BaseButton>
          </div>
        </form>
      </template>
    </section>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
