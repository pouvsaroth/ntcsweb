<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'

import { billingApi, type Plan, type PlanModule } from '../api'
import { moduleLabel } from '../billing'

/** Create or edit a plan: price per period, student limit, modules and the menus inside them. */
const props = defineProps<{ modelValue: boolean; plan: Plan | null; modules: PlanModule[] }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; saved: [plan: Plan] }>()

const { t, locale } = useI18n()

const blank = () => ({
  code: '',
  name: '',
  description: '',
  price: '0',
  currency: 'USD' as 'USD' | 'KHR',
  period_months: '1',
  max_students: '',
  modules: [] as string[],
  /** Every ticked menu, across modules. */
  menus: [] as string[],
  show_on_website: false,
  name_km: '',
  tagline_km: '',
  tagline_en: '',
  features_km: '',
  features_en: '',
  recommended: false,
  website_order: '0',
})
const form = ref(blank())
const saving = ref(false)
const error = ref<ApiRequestError | null>(null)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    error.value = null
    const p = props.plan
    form.value = p
      ? {
          code: p.code,
          name: p.name,
          description: p.description ?? '',
          price: String(p.price),
          currency: p.currency,
          period_months: p.period_months === null ? '' : String(p.period_months),
          max_students: p.max_students === null ? '' : String(p.max_students),
          modules: [...p.modules],
          // null = every menu of its modules
          menus: p.menus ?? props.modules.filter((m) => p.modules.includes(m.code)).flatMap((m) => m.groups),
          show_on_website: p.show_on_website,
          name_km: p.name_km ?? '',
          tagline_km: p.tagline_km ?? '',
          tagline_en: p.tagline_en ?? '',
          features_km: p.features_km ?? '',
          features_en: p.features_en ?? '',
          recommended: p.recommended,
          website_order: String(p.website_order ?? 0),
        }
      : blank()
  },
)

const currencyOptions = [
  { value: 'USD', label: 'USD ($)' },
  { value: 'KHR', label: 'KHR (៛)' },
]

const textarea =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none'

const allOn = computed(() => form.value.modules.length === props.modules.length)

const groupsOf = (code: string) => props.modules.find((m) => m.code === code)?.groups ?? []

/** A module on: all its menus on. Off: its menus go too. */
function toggle(code: string, on: boolean): void {
  const groups = groupsOf(code)
  form.value.modules = on ? [...form.value.modules, code] : form.value.modules.filter((m) => m !== code)
  form.value.menus = on ? [...new Set([...form.value.menus, ...groups])] : form.value.menus.filter((g) => !groups.includes(g))
}

function toggleAll(): void {
  const on = !allOn.value
  form.value.modules = on ? props.modules.map((m) => m.code) : []
  form.value.menus = on ? props.modules.flatMap((m) => m.groups) : []
}

/** One menu on or off; its module's last menu off turns the module off. */
function toggleMenu(module: string, group: string, on: boolean): void {
  form.value.menus = on ? [...form.value.menus, group] : form.value.menus.filter((g) => g !== group)
  if (!on && !groupsOf(module).some((g) => form.value.menus.includes(g))) toggle(module, false)
}

const menuCount = (m: PlanModule) => m.groups.filter((g) => form.value.menus.includes(g)).length

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  const body = {
    code: form.value.code.trim(),
    name: form.value.name.trim(),
    description: form.value.description.trim() || null,
    price: form.value.price,
    currency: form.value.currency,
    period_months: form.value.period_months === '' ? null : Number(form.value.period_months),
    max_students: form.value.max_students === '' ? null : Number(form.value.max_students),
    modules: form.value.modules,
    // The server keeps only menus of the ticked modules, and stores "every menu" as null.
    menus: form.value.menus,
    // The Legacy plan never goes on the website, so its form sends none of this.
    ...(props.plan?.is_legacy
      ? {}
      : {
          show_on_website: form.value.show_on_website,
          name_km: form.value.name_km.trim() || null,
          tagline_km: form.value.tagline_km.trim() || null,
          tagline_en: form.value.tagline_en.trim() || null,
          features_km: form.value.features_km.trim() || null,
          features_en: form.value.features_en.trim() || null,
          recommended: form.value.recommended,
          website_order: Number(form.value.website_order) || 0,
        }),
  }
  try {
    const saved = props.plan ? await billingApi.updatePlan(props.plan.id, body) : await billingApi.createPlan(body)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="plan ? t('bo.billing.plans.edit') : t('bo.billing.plans.add')" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <form id="bo-plan" class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="error && !error.errors" variant="danger">{{ error.message }}</BaseAlert>
      <p v-if="plan?.is_legacy" class="text-sm text-neutral-500">{{ t('bo.billing.plans.legacyHint') }}</p>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.name" :label="t('bo.billing.plans.name')" :error="error?.fieldError('name')" required />
        <BaseInput
          v-model="form.code"
          :label="t('bo.billing.plans.code')"
          :hint="t('bo.billing.plans.codeHint')"
          :error="error?.fieldError('code')"
          :disabled="plan?.is_legacy"
          required
        />
      </div>
      <BaseInput v-model="form.description" :label="t('bo.billing.plans.description')" :error="error?.fieldError('description')" />

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseInput v-model="form.price" type="number" :label="t('bo.billing.plans.price')" :error="error?.fieldError('price')" required />
        <BaseSelect v-model="form.currency" :options="currencyOptions" :label="t('bo.billing.plans.currency')" :error="error?.fieldError('currency')" />
        <BaseInput
          v-model="form.period_months"
          type="number"
          :label="t('bo.billing.plans.periodMonths')"
          :hint="t('bo.billing.plans.periodHint')"
          :error="error?.fieldError('period_months')"
        />
      </div>
      <BaseInput
        v-model="form.max_students"
        type="number"
        :label="t('bo.billing.plans.maxStudents')"
        :hint="t('bo.billing.plans.maxStudentsHint')"
        :error="error?.fieldError('max_students')"
      />

      <div>
        <div class="mb-2 flex items-center justify-between gap-2">
          <p class="text-sm font-medium text-neutral-700">{{ t('bo.billing.plans.modules') }}</p>
          <button type="button" class="text-sm text-secondary-700 hover:underline" @click="toggleAll">
            {{ allOn ? t('bo.billing.plans.noModules') : t('bo.billing.plans.allModules') }}
          </button>
        </div>
        <!-- Each module, and under a ticked one its menus (when it has more than one). -->
        <div class="space-y-2">
          <div
            v-for="module in modules"
            :key="module.code"
            class="rounded-lg border text-sm"
            :class="form.modules.includes(module.code) ? 'border-primary-500 bg-primary-50/40' : 'border-neutral-200'"
          >
            <label class="flex cursor-pointer items-center gap-2 px-3 py-2" :class="form.modules.includes(module.code) ? 'text-neutral-900' : 'text-neutral-600'">
              <input
                type="checkbox"
                class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                :checked="form.modules.includes(module.code)"
                @change="toggle(module.code, ($event.target as HTMLInputElement).checked)"
              />
              <span class="font-medium">{{ moduleLabel(module, locale) }}</span>
              <span v-if="form.modules.includes(module.code) && module.menus.length > 1" class="ml-auto text-xs text-neutral-500">
                {{ t('bo.billing.plans.menusOf', { n: menuCount(module), total: module.menus.length }) }}
              </span>
            </label>
            <div v-if="form.modules.includes(module.code) && module.menus.length > 1" class="flex flex-wrap gap-x-4 gap-y-1.5 border-t border-primary-100 px-3 py-2 pl-9">
              <label v-for="menu in module.menus" :key="menu.code" class="flex cursor-pointer items-center gap-1.5 text-neutral-700">
                <input
                  type="checkbox"
                  class="h-3.5 w-3.5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                  :checked="form.menus.includes(menu.code)"
                  @change="toggleMenu(module.code, menu.code, ($event.target as HTMLInputElement).checked)"
                />
                {{ locale === 'km' ? menu.label_km : menu.label }}
              </label>
            </div>
          </div>
        </div>
        <p class="mt-1.5 text-sm text-neutral-500">{{ t('bo.billing.plans.modulesHint') }}</p>
        <p v-if="error?.fieldError('modules')" class="mt-1 text-xs text-red-600">{{ error.fieldError('modules') }}</p>
        <p v-if="error?.fieldError('menus')" class="mt-1 text-xs text-red-600">{{ error.fieldError('menus') }}</p>
      </div>

      <!-- The plan card on the website's plans page (docs/backoffice/stage-8-signup.md, 8a). -->
      <fieldset class="space-y-4 border-t border-neutral-100 pt-4">
        <legend class="sr-only">{{ t('bo.billing.plans.web.section') }}</legend>
        <div>
          <p class="font-medium text-neutral-900">{{ t('bo.billing.plans.web.section') }}</p>
          <p class="text-sm text-neutral-500">{{ plan?.is_legacy ? t('bo.billing.plans.web.legacyNo') : t('bo.billing.plans.web.sectionHint') }}</p>
        </div>
        <template v-if="!plan?.is_legacy">
          <label class="flex cursor-pointer items-center gap-2 text-sm text-neutral-800">
            <input v-model="form.show_on_website" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('bo.billing.plans.web.show') }}
          </label>
          <template v-if="form.show_on_website">
            <div class="grid gap-4 sm:grid-cols-3">
              <BaseInput v-model="form.name_km" :label="t('bo.billing.plans.web.nameKm')" :error="error?.fieldError('name_km')" />
              <BaseInput
                v-model="form.website_order"
                type="number"
                :label="t('bo.billing.plans.web.order')"
                :error="error?.fieldError('website_order')"
              />
              <label class="flex cursor-pointer items-start gap-2 pt-7 text-sm text-neutral-800">
                <input v-model="form.recommended" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                <span>
                  {{ t('bo.billing.plans.web.recommended') }}
                  <span class="block text-xs text-neutral-500">{{ t('bo.billing.plans.web.recommendedHint') }}</span>
                </span>
              </label>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <BaseInput
                v-model="form.tagline_km"
                :label="t('bo.billing.plans.web.taglineKm')"
                :hint="t('bo.billing.plans.web.taglineHint')"
                :error="error?.fieldError('tagline_km')"
              />
              <BaseInput v-model="form.tagline_en" :label="t('bo.billing.plans.web.taglineEn')" :error="error?.fieldError('tagline_en')" />
              <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.billing.plans.web.featuresKm') }}</span>
                <textarea v-model="form.features_km" rows="6" :class="textarea" />
                <span class="mt-1 block text-xs text-neutral-500">{{ t('bo.billing.plans.web.featuresHint') }}</span>
                <span v-if="error?.fieldError('features_km')" class="mt-1 block text-xs text-red-600">{{ error.fieldError('features_km') }}</span>
              </label>
              <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.billing.plans.web.featuresEn') }}</span>
                <textarea v-model="form.features_en" rows="6" :class="textarea" />
                <span class="mt-1 block text-xs text-neutral-500">{{ t('bo.billing.plans.web.featuresHint') }}</span>
                <span v-if="error?.fieldError('features_en')" class="mt-1 block text-xs text-red-600">{{ error.fieldError('features_en') }}</span>
              </label>
            </div>
          </template>
        </template>
      </fieldset>
    </form>
    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="bo-plan" :loading="saving">{{ t('bo.common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>
