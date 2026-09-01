<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { assetCategoriesService, type AssetCategory } from '@/services/assetCategories'
import { assetLocationsService, type AssetLocation } from '@/services/assetLocations'
import { assetsService, type AssetInput } from '@/services/assets'
import { departmentsService, type Department } from '@/services/departments'
import { suppliersService, type Supplier } from '@/services/suppliers'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const assetId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEditing = computed(() => assetId.value !== null)

const form = reactive<AssetInput>({
  category_id: null,
  name: '',
  description: '',
  brand: '',
  model: '',
  serial_number: '',
  asset_tag: '',
  purchase_date: '',
  purchase_price: '',
  current_value: '',
  supplier_id: null,
  warranty_start_date: '',
  warranty_end_date: '',
  warranty_provider: '',
  warranty_number: '',
  location_id: null,
  department_id: null,
  hostname: '',
  mac_address: '',
  ip_address: '',
  specs: {},
  notes: '',
})

const categories = ref<AssetCategory[]>([])
const locations = ref<AssetLocation[]>([])
const departments = ref<Department[]>([])
const suppliers = ref<Supplier[]>([])

const loading = ref(true)
const loadError = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const categoryOptions = computed(() => categories.value.map((c) => ({ value: String(c.id), label: `${c.code} — ${c.name}` })))
const locationOptions = computed(() => [{ value: '', label: t('admin.assets.noLocation') }, ...locations.value.map((l) => ({ value: String(l.id), label: `${l.code} — ${l.name}` }))])
const departmentOptions = computed(() => [{ value: '', label: t('admin.assets.noDepartment') }, ...departments.value.map((d) => ({ value: String(d.id), label: d.name }))])
const supplierOptions = computed(() => [{ value: '', label: t('admin.assets.noSupplier') }, ...suppliers.value.map((s) => ({ value: String(s.id), label: s.name }))])

onMounted(async () => {
  loading.value = true
  loadError.value = null

  try {
    ;[categories.value, locations.value, departments.value, suppliers.value] = await Promise.all([
      assetCategoriesService.listAll(),
      assetLocationsService.listAll(),
      departmentsService.listAll(),
      suppliersService.listAll(),
    ])

    if (assetId.value) {
      const asset = await assetsService.get(assetId.value)
      form.category_id = asset.category_id
      form.name = asset.name
      form.description = asset.description ?? ''
      form.brand = asset.brand ?? ''
      form.model = asset.model ?? ''
      form.serial_number = asset.serial_number ?? ''
      form.asset_tag = asset.asset_tag ?? ''
      form.purchase_date = asset.purchase_date ?? ''
      form.purchase_price = String(asset.purchase_price ?? '')
      form.current_value = asset.current_value !== null ? String(asset.current_value) : ''
      form.supplier_id = asset.supplier_id
      form.warranty_start_date = asset.warranty_start_date ?? ''
      form.warranty_end_date = asset.warranty_end_date ?? ''
      form.warranty_provider = asset.warranty_provider ?? ''
      form.warranty_number = asset.warranty_number ?? ''
      form.location_id = asset.location_id
      form.department_id = asset.department_id
      form.hostname = asset.hostname ?? ''
      form.mac_address = asset.mac_address ?? ''
      form.ip_address = asset.ip_address ?? ''
      form.specs = asset.specs ?? {}
      form.notes = asset.notes ?? ''
    }
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.loadFailed')
  } finally {
    loading.value = false
  }
})

async function submit() {
  if (!form.category_id) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const asset = isEditing.value ? await assetsService.update(assetId.value!, form) : await assetsService.create(form)
    await router.push(`/admin/assets/${asset.id}`)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="max-w-3xl">
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ isEditing ? t('admin.assets.editTitle') : t('admin.assets.createTitle') }}</h1>
    </div>

    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <form v-else class="space-y-8" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <section class="space-y-4">
        <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.assets.sectionBasics') }}</h2>
        <BaseInput v-model="form.name" required :label="t('admin.assets.name')" :error="errors.name?.[0]" />
        <BaseSelect
          :model-value="form.category_id !== null ? String(form.category_id) : ''"
          :options="categoryOptions"
          required
          :placeholder="t('admin.assets.selectCategory')"
          :label="t('admin.assets.category')"
          :error="errors.category_id?.[0]"
          @update:model-value="form.category_id = $event ? Number($event) : null"
        />
        <div class="grid grid-cols-2 gap-4">
          <BaseInput v-model="form.brand" :label="t('admin.assets.brand')" :error="errors.brand?.[0]" />
          <BaseInput v-model="form.model" :label="t('admin.assets.model')" :error="errors.model?.[0]" />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <BaseInput v-model="form.serial_number" :label="t('admin.assets.serialNumber')" :error="errors.serial_number?.[0]" />
          <BaseInput v-model="form.asset_tag" :label="t('admin.assets.assetTag')" :error="errors.asset_tag?.[0]" />
        </div>
        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.assets.description') }}</label>
          <textarea
            v-model="form.description"
            rows="2"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          />
        </div>
      </section>

      <section class="space-y-4">
        <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.assets.sectionPurchase') }}</h2>
        <div class="grid grid-cols-2 gap-4">
          <BaseInput v-model="form.purchase_date" type="date" :label="t('admin.assets.purchaseDate')" :error="errors.purchase_date?.[0]" />
          <BaseInput v-model="form.purchase_price" type="number" :label="t('admin.assets.purchasePrice')" :error="errors.purchase_price?.[0]" />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <BaseInput v-model="form.current_value" type="number" :label="t('admin.assets.currentValue')" :error="errors.current_value?.[0]" />
          <BaseSelect
            :model-value="form.supplier_id !== null ? String(form.supplier_id) : ''"
            :options="supplierOptions"
            :label="t('admin.assets.supplier')"
            @update:model-value="form.supplier_id = $event ? Number($event) : null"
          />
        </div>
      </section>

      <section class="space-y-4">
        <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.assets.sectionWarranty') }}</h2>
        <div class="grid grid-cols-2 gap-4">
          <BaseInput v-model="form.warranty_start_date" type="date" :label="t('admin.assets.warrantyStartDate')" />
          <BaseInput v-model="form.warranty_end_date" type="date" :label="t('admin.assets.warrantyEndDate')" />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <BaseInput v-model="form.warranty_provider" :label="t('admin.assets.warrantyProvider')" />
          <BaseInput v-model="form.warranty_number" :label="t('admin.assets.warrantyNumber')" />
        </div>
      </section>

      <section class="space-y-4">
        <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.assets.sectionLocation') }}</h2>
        <div class="grid grid-cols-2 gap-4">
          <BaseSelect
            :model-value="form.location_id !== null ? String(form.location_id) : ''"
            :options="locationOptions"
            :label="t('admin.assets.location')"
            @update:model-value="form.location_id = $event ? Number($event) : null"
          />
          <BaseSelect
            :model-value="form.department_id !== null ? String(form.department_id) : ''"
            :options="departmentOptions"
            :label="t('admin.assets.department')"
            @update:model-value="form.department_id = $event ? Number($event) : null"
          />
        </div>
      </section>

      <section class="space-y-4">
        <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.assets.sectionTechnical') }}</h2>
        <p class="text-sm text-neutral-500">{{ t('admin.assets.sectionTechnicalHint') }}</p>
        <div class="grid grid-cols-3 gap-4">
          <BaseInput v-model="form.hostname" :label="t('admin.assets.hostname')" />
          <BaseInput v-model="form.mac_address" :label="t('admin.assets.macAddress')" />
          <BaseInput v-model="form.ip_address" :label="t('admin.assets.ipAddress')" />
        </div>
      </section>

      <section class="space-y-4">
        <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.assets.sectionNotes') }}</h2>
        <textarea
          v-model="form.notes"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </section>

      <div class="flex gap-3">
        <BaseButton type="submit" :loading="submitting" :disabled="!form.category_id">{{ t('common.save') }}</BaseButton>
        <BaseButton type="button" variant="outline" @click="router.push('/admin/assets')">{{ t('common.cancel') }}</BaseButton>
      </div>
    </form>
  </div>
</template>
