<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'

import { billingApi, type Plan, type PlanModule, type ProductChoice } from '../api'
import { moduleLabel } from '../billing'

/**
 * Create or edit a plan, in four short steps: the basics (system, name,
 * price, how often it is paid, student limit), what is included (modules,
 * and the menus inside them when needed), the website card, and a last
 * check with a preview of that card. Any step can be opened from the bar.
 */
const props = defineProps<{ modelValue: boolean; plan: Plan | null; modules: PlanModule[]; products: ProductChoice[]; defaultProductId?: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; saved: [plan: Plan] }>()

const { t, locale } = useI18n()

const schoolId = () => props.products.find((p) => p.code === 'school')?.id ?? props.products[0]?.id ?? 0
const blank = () => ({
  product_id: props.defaultProductId ?? schoolId(),
  code: '',
  name: '',
  name_km: '',
  description: '',
  price: '',
  currency: 'USD' as 'USD' | 'KHR',
  /** '' = paid once, no end. */
  period_months: '1',
  /** '' = no limit. */
  max_students: '',
  placement: 'shared' as 'shared' | 'dedicated',
  modules: [] as string[],
  /** Every ticked menu, across modules. */
  menus: [] as string[],
  show_on_website: true,
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
const localErrors = ref<Record<string, string>>({})

const STEPS = ['basics', 'included', 'website', 'check'] as const
const step = ref(0)
/** The code follows the name until staff change it themselves. */
const codeTouched = ref(false)
const showCode = ref(false)
const noLimit = ref(true)
/** Which modules show their menus. */
const openMenus = ref<string[]>([])

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    error.value = null
    localErrors.value = {}
    step.value = 0
    showCode.value = false
    openMenus.value = []
    const p = props.plan
    codeTouched.value = !!p
    form.value = p
      ? {
          product_id: p.product?.id ?? schoolId(),
          code: p.code,
          name: p.name,
          name_km: p.name_km ?? '',
          description: p.description ?? '',
          price: String(Number(p.price)),
          currency: p.currency,
          period_months: p.period_months === null ? '' : String(p.period_months),
          max_students: p.max_students === null ? '' : String(p.max_students),
          placement: p.placement ?? 'shared',
          modules: [...p.modules],
          // null = every menu of its modules
          menus: p.menus ?? props.modules.filter((m) => p.modules.includes(m.code)).flatMap((m) => m.groups),
          show_on_website: p.show_on_website,
          tagline_km: p.tagline_km ?? '',
          tagline_en: p.tagline_en ?? '',
          features_km: p.features_km ?? '',
          features_en: p.features_en ?? '',
          recommended: p.recommended,
          website_order: String(p.website_order ?? 0),
        }
      : blank()
    noLimit.value = form.value.max_students === ''
  },
)

// ── 1. Basics ────────────────────────────────────────────────────────────────
const productName = (p: ProductChoice) => (locale.value === 'km' && p.name_km ? p.name_km : p.name)
const productChoices = computed(() => props.products.filter((p) => p.status === 'active' || p.id === props.plan?.product?.id))
const product = computed(() => props.products.find((p) => p.id === Number(form.value.product_id)) ?? null)

/** Another system: modules (and their menus) it does not have are dropped. */
function chooseProduct(id: number): void {
  if (props.plan?.is_legacy) return
  form.value.product_id = id
  const keep = product.value?.modules ?? []
  form.value.modules = form.value.modules.filter((m) => keep.includes(m))
  const groups = props.modules.filter((m) => keep.includes(m.code)).flatMap((m) => m.groups)
  form.value.menus = form.value.menus.filter((g) => groups.includes(g))
}

watch(
  () => form.value.name,
  (name) => {
    if (codeTouched.value) return
    form.value.code = name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 50)
  },
)

/** How often it is paid: one click; "other" for any number of months. */
const PERIODS = [
  { value: '1', key: 'monthly' },
  { value: '3', key: 'every3' },
  { value: '6', key: 'every6' },
  { value: '12', key: 'yearly' },
  { value: '', key: 'once' },
] as const
const customPeriod = computed(() => !PERIODS.some((p) => p.value === form.value.period_months))

function setNoLimit(on: boolean): void {
  noLimit.value = on
  if (on) form.value.max_students = ''
}

// ── 2. What is included ──────────────────────────────────────────────────────
const shownModules = computed(() => props.modules.filter((m) => product.value?.modules.includes(m.code)))
const allOn = computed(() => shownModules.value.length > 0 && shownModules.value.every((m) => form.value.modules.includes(m.code)))
const groupsOf = (code: string) => props.modules.find((m) => m.code === code)?.groups ?? []

/** A module on: all its menus on. Off: its menus go too. */
function toggle(code: string, on: boolean): void {
  const groups = groupsOf(code)
  form.value.modules = on ? [...form.value.modules, code] : form.value.modules.filter((m) => m !== code)
  form.value.menus = on ? [...new Set([...form.value.menus, ...groups])] : form.value.menus.filter((g) => !groups.includes(g))
  if (!on) openMenus.value = openMenus.value.filter((m) => m !== code)
}

function toggleAll(): void {
  const on = !allOn.value
  form.value.modules = on ? shownModules.value.map((m) => m.code) : []
  form.value.menus = on ? shownModules.value.flatMap((m) => m.groups) : []
}

/** One menu on or off; its module's last menu off turns the module off. */
function toggleMenu(module: string, group: string, on: boolean): void {
  form.value.menus = on ? [...form.value.menus, group] : form.value.menus.filter((g) => g !== group)
  if (!on && !groupsOf(module).some((g) => form.value.menus.includes(g))) toggle(module, false)
}

const menuCount = (m: PlanModule) => m.groups.filter((g) => form.value.menus.includes(g)).length
function toggleMenus(code: string): void {
  openMenus.value = openMenus.value.includes(code) ? openMenus.value.filter((m) => m !== code) : [...openMenus.value, code]
}

// ── 4. Check: the website card as visitors will see it ───────────────────────
const km = computed(() => locale.value === 'km')
const lines = (text: string) =>
  text
    .split('\n')
    .map((l) => l.replace(/^[\s\-•·*]+/, '').trim())
    .filter(Boolean)
const preview = computed(() => {
  const f = form.value
  const months = f.period_months === '' ? null : Number(f.period_months)
  return {
    name: (km.value ? f.name_km || f.name : f.name || f.name_km) || '—',
    price: formatMoney(Number(f.price) || 0, f.currency).replace(/\.00$/, ''),
    period:
      months === null
        ? t('bo.billing.plans.form.payOnce')
        : months === 1
          ? t('bo.billing.plans.monthly')
          : months === 12
            ? t('bo.billing.plans.yearly')
            : t('bo.billing.plans.every', { n: months }),
    tagline: km.value ? f.tagline_km || f.tagline_en : f.tagline_en || f.tagline_km,
    modules: shownModules.value.filter((m) => f.modules.includes(m.code)).map((m) => moduleLabel(m, locale.value)),
    features: km.value ? lines(f.features_km).length ? lines(f.features_km) : lines(f.features_en) : lines(f.features_en).length ? lines(f.features_en) : lines(f.features_km),
  }
})

// ── Steps ────────────────────────────────────────────────────────────────────
const STEP_FIELDS: Record<number, string[]> = {
  0: ['product_id', 'code', 'name', 'name_km', 'price', 'currency', 'period_months', 'max_students', 'placement'],
  1: ['modules', 'menus'],
  2: ['show_on_website', 'tagline_km', 'tagline_en', 'features_km', 'features_en', 'recommended', 'website_order'],
  3: ['description'],
}
const fieldError = (f: string) => localErrors.value[f] ?? error.value?.fieldError(f)

function check(n: number): boolean {
  const e: Record<string, string> = {}
  const f = form.value
  if (n === 0) {
    if (!f.name.trim()) e.name = t('bo.billing.plans.form.required')
    if (f.price === '' || Number(f.price) < 0) e.price = t('bo.billing.plans.form.required')
    if (!/^[a-z0-9][a-z0-9_-]*$/.test(f.code)) {
      e.code = t('bo.billing.plans.codeHint')
      showCode.value = true
    }
    if (customPeriod.value && !(Number(f.period_months) >= 1)) e.period_months = t('bo.billing.plans.form.required')
    if (!noLimit.value && !(Number(f.max_students) >= 1)) e.max_students = t('bo.billing.plans.form.required')
  }
  if (n === 1 && f.modules.length === 0) e.modules = t('bo.billing.plans.form.pickModule')
  localErrors.value = e
  return Object.keys(e).length === 0
}

function go(target: number): void {
  // Forward only past steps that are filled in; back (or anywhere on an edit) freely.
  if (target > step.value) {
    for (let s = step.value; s < target; s++) {
      if (!check(s)) {
        step.value = s
        return
      }
    }
  }
  localErrors.value = {}
  step.value = target
}

async function save(): Promise<void> {
  for (let s = 0; s < 3; s++) {
    if (!check(s)) {
      step.value = s
      return
    }
  }
  saving.value = true
  error.value = null
  const f = form.value
  const body = {
    product_id: Number(f.product_id),
    code: f.code.trim(),
    name: f.name.trim(),
    description: f.description.trim() || null,
    price: f.price,
    currency: f.currency,
    period_months: f.period_months === '' ? null : Number(f.period_months),
    max_students: noLimit.value || f.max_students === '' ? null : Number(f.max_students),
    placement: f.placement,
    modules: f.modules,
    // The server keeps only menus of the ticked modules, and stores "every menu" as null.
    menus: f.menus,
    // The Legacy plan never goes on the website, so its form sends none of this.
    ...(props.plan?.is_legacy
      ? {}
      : {
          show_on_website: f.show_on_website,
          name_km: f.name_km.trim() || null,
          tagline_km: f.tagline_km.trim() || null,
          tagline_en: f.tagline_en.trim() || null,
          features_km: f.features_km.trim() || null,
          features_en: f.features_en.trim() || null,
          recommended: f.recommended,
          website_order: Number(f.website_order) || 0,
        }),
  }
  try {
    const saved = props.plan ? await billingApi.updatePlan(props.plan.id, body) : await billingApi.createPlan(body)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
    // Open the step of the first field the server refused.
    const bad = Object.keys(error.value.errors ?? {}).map((k) => k.split('.')[0])
    const at = Object.entries(STEP_FIELDS).find(([, fields]) => fields.some((f) => bad.includes(f)))
    if (at) step.value = Number(at[0])
    if (bad.includes('code')) showCode.value = true
  } finally {
    saving.value = false
  }
}

const textarea =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none'
const chip = (on: boolean) =>
  on ? 'border-primary-500 bg-primary-50 font-semibold text-neutral-900' : 'border-neutral-300 text-neutral-700 hover:bg-neutral-50'
</script>

<template>
  <BaseModal :model-value="modelValue" :title="plan ? t('bo.billing.plans.edit') : t('bo.billing.plans.add')" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <!-- Step bar: any step can be opened (forward only once the ones before are filled in). -->
    <ol class="mb-5 grid grid-cols-4 gap-2">
      <li v-for="(s, i) in STEPS" :key="s">
        <button type="button" class="w-full text-left" :aria-current="i === step ? 'step' : undefined" @click="go(i)">
          <span class="block h-1.5 rounded-full" :class="i <= step ? 'bg-primary-500' : 'bg-neutral-200'" />
          <span class="mt-1.5 block truncate text-xs" :class="i === step ? 'font-semibold text-neutral-900' : 'text-neutral-500 hover:text-neutral-800'">
            {{ i + 1 }}. {{ t(`bo.billing.plans.form.steps.${s}`) }}
          </span>
        </button>
      </li>
    </ol>

    <form id="bo-plan" class="space-y-5" @submit.prevent="step < 3 ? go(step + 1) : save()">
      <BaseAlert v-if="error && !error.errors" variant="danger">{{ error.message }}</BaseAlert>
      <p v-if="plan?.is_legacy" class="text-sm text-neutral-500">{{ t('bo.billing.plans.legacyHint') }}</p>

      <!-- 1. Basics -->
      <template v-if="step === 0">
        <div>
          <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bo.billing.plans.form.whichSystem') }}</p>
          <div class="grid gap-2 sm:grid-cols-2">
            <button
              v-for="p in productChoices"
              :key="p.id"
              type="button"
              class="rounded-lg border px-3 py-2.5 text-left text-sm"
              :class="chip(p.id === Number(form.product_id))"
              :disabled="plan?.is_legacy"
              @click="chooseProduct(p.id)"
            >
              {{ productName(p) }}
            </button>
          </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <BaseInput v-model="form.name" :label="t('bo.billing.plans.form.nameEn')" :placeholder="t('bo.billing.plans.form.nameExample')" :error="fieldError('name')" required />
            <p class="mt-1 text-xs text-neutral-500">
              <template v-if="!showCode">
                {{ t('bo.billing.plans.form.codeIs') }} <span class="font-mono">{{ form.code || '—' }}</span>
                <button v-if="!plan?.is_legacy" type="button" class="ml-1 text-secondary-700 hover:underline" @click="showCode = true">{{ t('bo.billing.plans.form.change') }}</button>
              </template>
            </p>
          </div>
          <BaseInput v-model="form.name_km" :label="t('bo.billing.plans.web.nameKm')" :placeholder="t('bo.billing.plans.form.nameKmExample')" :error="fieldError('name_km')" />
        </div>
        <BaseInput
          v-if="showCode"
          v-model="form.code"
          :label="t('bo.billing.plans.code')"
          :hint="t('bo.billing.plans.codeHint')"
          :error="fieldError('code')"
          :disabled="plan?.is_legacy"
          @update:model-value="
            (v: string) => {
              codeTouched = true
              form.code = v.toLowerCase().replace(/\s+/g, '-')
            }
          "
        />

        <div>
          <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bo.billing.plans.price') }} <span class="text-red-600">*</span></p>
          <div class="flex gap-2">
            <div class="flex shrink-0 overflow-hidden rounded-lg border border-neutral-300">
              <button
                v-for="c in ['USD', 'KHR'] as const"
                :key="c"
                type="button"
                class="px-3 text-sm"
                :class="form.currency === c ? 'bg-primary-500 font-semibold text-secondary-900' : 'bg-white text-neutral-600 hover:bg-neutral-50'"
                @click="form.currency = c"
              >
                {{ c === 'USD' ? '$' : '៛' }}
              </button>
            </div>
            <BaseInput v-model="form.price" type="number" class="flex-1" :placeholder="form.currency === 'USD' ? '15' : '60000'" :error="fieldError('price')" />
          </div>
        </div>

        <div>
          <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bo.billing.plans.form.howOften') }}</p>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="p in PERIODS"
              :key="p.key"
              type="button"
              class="rounded-lg border px-3 py-1.5 text-sm"
              :class="chip(form.period_months === p.value)"
              @click="form.period_months = p.value"
            >
              {{ t(`bo.billing.plans.form.periods.${p.key}`) }}
            </button>
            <button type="button" class="rounded-lg border px-3 py-1.5 text-sm" :class="chip(customPeriod)" @click="form.period_months = customPeriod ? form.period_months : '2'">
              {{ t('bo.billing.plans.form.periods.other') }}
            </button>
          </div>
          <div v-if="customPeriod" class="mt-2 flex items-center gap-2 text-sm text-neutral-700">
            {{ t('bo.billing.plans.form.every') }}
            <BaseInput v-model="form.period_months" type="number" class="w-24" :error="fieldError('period_months')" />
            {{ t('bo.billing.plans.form.months') }}
          </div>
        </div>

        <div>
          <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bo.billing.plans.maxStudents') }}</p>
          <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="rounded-lg border px-3 py-1.5 text-sm" :class="chip(noLimit)" @click="setNoLimit(true)">{{ t('bo.billing.plans.noLimit') }}</button>
            <button type="button" class="rounded-lg border px-3 py-1.5 text-sm" :class="chip(!noLimit)" @click="setNoLimit(false)">{{ t('bo.billing.plans.form.upTo') }}</button>
            <BaseInput v-if="!noLimit" v-model="form.max_students" type="number" class="w-32" placeholder="300" :error="fieldError('max_students')" />
          </div>
        </div>

        <!-- Where a customer on this plan keeps its data (new sign-ups are set up this way). -->
        <div>
          <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bo.billing.plans.form.database') }}</p>
          <div class="grid gap-2 sm:grid-cols-2">
            <button
              v-for="p in ['shared', 'dedicated'] as const"
              :key="p"
              type="button"
              class="rounded-lg border px-3 py-2 text-left text-sm"
              :class="chip(form.placement === p)"
              @click="form.placement = p"
            >
              <span class="block">{{ t(`bo.billing.plans.form.db.${p}`) }}</span>
              <span class="block text-xs font-normal text-neutral-500">{{ t(`bo.billing.plans.form.db.${p}Hint`) }}</span>
            </button>
          </div>
          <p class="mt-1 text-xs text-neutral-500">{{ t('bo.billing.plans.form.databaseHint') }}</p>
          <p v-if="fieldError('placement')" class="mt-1 text-xs text-red-600">{{ fieldError('placement') }}</p>
        </div>
      </template>

      <!-- 2. What is included -->
      <template v-else-if="step === 1">
        <div class="flex items-center justify-between gap-2">
          <p class="text-sm text-neutral-600">{{ t('bo.billing.plans.form.includedHint') }}</p>
          <button type="button" class="shrink-0 text-sm text-secondary-700 hover:underline" @click="toggleAll">
            {{ allOn ? t('bo.billing.plans.noModules') : t('bo.billing.plans.form.everything') }}
          </button>
        </div>
        <p v-if="fieldError('modules')" class="text-sm text-red-600">{{ fieldError('modules') }}</p>
        <div class="grid gap-2 sm:grid-cols-2">
          <div
            v-for="module in shownModules"
            :key="module.code"
            class="rounded-lg border text-sm"
            :class="[form.modules.includes(module.code) ? 'border-primary-500 bg-primary-50/40' : 'border-neutral-200', openMenus.includes(module.code) ? 'sm:col-span-2' : '']"
          >
            <div class="flex items-center gap-2 px-3 py-2.5">
              <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-2" :class="form.modules.includes(module.code) ? 'text-neutral-900' : 'text-neutral-600'">
                <input
                  type="checkbox"
                  class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                  :checked="form.modules.includes(module.code)"
                  @change="toggle(module.code, ($event.target as HTMLInputElement).checked)"
                />
                <span class="truncate font-medium">{{ moduleLabel(module, locale) }}</span>
              </label>
              <button
                v-if="form.modules.includes(module.code) && module.menus.length > 1"
                type="button"
                class="shrink-0 text-xs text-secondary-700 hover:underline"
                @click="toggleMenus(module.code)"
              >
                {{
                  menuCount(module) === module.menus.length
                    ? t('bo.billing.plans.form.allMenus', { n: module.menus.length })
                    : t('bo.billing.plans.menusOf', { n: menuCount(module), total: module.menus.length })
                }}
                {{ openMenus.includes(module.code) ? '▴' : '▾' }}
              </button>
            </div>
            <div v-if="openMenus.includes(module.code)" class="flex flex-wrap gap-x-4 gap-y-1.5 border-t border-primary-100 px-3 py-2 pl-9">
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
        <p class="text-xs text-neutral-500">{{ t('bo.billing.plans.modulesHint') }}</p>
      </template>

      <!-- 3. On the website -->
      <template v-else-if="step === 2">
        <p v-if="plan?.is_legacy" class="text-sm text-neutral-500">{{ t('bo.billing.plans.web.legacyNo') }}</p>
        <template v-else>
          <label class="flex cursor-pointer items-center justify-between gap-3 rounded-lg border border-neutral-200 px-4 py-3">
            <span>
              <span class="block font-medium text-neutral-900">{{ t('bo.billing.plans.web.show') }}</span>
              <span class="block text-sm text-neutral-500">{{ t('bo.billing.plans.web.sectionHint') }}</span>
            </span>
            <input v-model="form.show_on_website" type="checkbox" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          </label>
          <template v-if="form.show_on_website">
            <div class="grid gap-4 sm:grid-cols-2">
              <BaseInput v-model="form.tagline_km" :label="t('bo.billing.plans.web.taglineKm')" :placeholder="t('bo.billing.plans.form.taglineKmExample')" :error="fieldError('tagline_km')" />
              <BaseInput v-model="form.tagline_en" :label="t('bo.billing.plans.web.taglineEn')" :placeholder="t('bo.billing.plans.web.taglineHint')" :error="fieldError('tagline_en')" />
              <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.billing.plans.web.featuresKm') }}</span>
                <textarea v-model="form.features_km" rows="5" :class="textarea" :placeholder="t('bo.billing.plans.form.featuresKmExample')" />
              </label>
              <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.billing.plans.web.featuresEn') }}</span>
                <textarea v-model="form.features_en" rows="5" :class="textarea" :placeholder="t('bo.billing.plans.form.featuresEnExample')" />
              </label>
            </div>
            <p class="-mt-2 text-xs text-neutral-500">{{ t('bo.billing.plans.form.featuresHint') }}</p>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
              <label class="flex cursor-pointer items-center gap-2 text-sm text-neutral-800">
                <input v-model="form.recommended" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                {{ t('bo.billing.plans.web.recommended') }}
              </label>
              <label class="flex items-center gap-2 text-sm text-neutral-700">
                {{ t('bo.billing.plans.web.order') }}
                <input v-model="form.website_order" type="number" min="0" class="w-20 rounded-lg border border-neutral-300 px-2 py-1 text-sm" />
              </label>
            </div>
          </template>
        </template>
      </template>

      <!-- 4. Check, with the website card as visitors see it -->
      <template v-else>
        <div class="grid gap-5 sm:grid-cols-2">
          <dl class="space-y-2 text-sm">
            <div>
              <dt class="text-neutral-500">{{ t('bo.billing.plans.system') }}</dt>
              <dd class="font-medium text-neutral-900">{{ product ? productName(product) : '—' }}</dd>
            </div>
            <div>
              <dt class="text-neutral-500">{{ t('bo.billing.plans.name') }}</dt>
              <dd class="font-medium text-neutral-900">{{ form.name }}<span v-if="form.name_km" class="text-neutral-600"> · {{ form.name_km }}</span></dd>
            </div>
            <div>
              <dt class="text-neutral-500">{{ t('bo.billing.plans.price') }}</dt>
              <dd class="font-medium text-neutral-900">{{ preview.price }} {{ preview.period }}</dd>
            </div>
            <div>
              <dt class="text-neutral-500">{{ t('bo.billing.plans.maxStudents') }}</dt>
              <dd class="font-medium text-neutral-900">{{ noLimit ? t('bo.billing.plans.noLimit') : form.max_students }}</dd>
            </div>
            <div>
              <dt class="text-neutral-500">{{ t('bo.billing.plans.form.database') }}</dt>
              <dd class="font-medium text-neutral-900">{{ t(`bo.billing.plans.form.db.${form.placement}`) }}</dd>
            </div>
            <div>
              <dt class="text-neutral-500">{{ t('bo.billing.plans.modules') }}</dt>
              <dd class="font-medium text-neutral-900">{{ preview.modules.join(' · ') || '—' }}</dd>
            </div>
            <div>
              <dt class="text-neutral-500">{{ t('bo.billing.plans.web.section') }}</dt>
              <dd class="font-medium text-neutral-900">{{ form.show_on_website && !plan?.is_legacy ? t('bo.billing.plans.form.shown') : t('bo.billing.plans.form.notShown') }}</dd>
            </div>
            <BaseInput v-model="form.description" :label="t('bo.billing.plans.form.note')" :hint="t('bo.billing.plans.form.noteHint')" :error="fieldError('description')" />
          </dl>

          <div v-if="form.show_on_website && !plan?.is_legacy">
            <p class="mb-2 text-xs font-medium tracking-wide text-neutral-500 uppercase">{{ t('bo.billing.plans.form.preview') }}</p>
            <article class="relative mt-4 rounded-2xl border bg-white p-5 shadow-sm" :class="form.recommended ? 'border-primary-500 ring-2 ring-primary-500' : 'border-neutral-200'">
              <span v-if="form.recommended" class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-primary-500 px-3 py-0.5 text-xs font-semibold whitespace-nowrap text-secondary-900">
                {{ t('bo.billing.plans.web.recommended') }}
              </span>
              <h3 class="font-semibold text-neutral-900">{{ preview.name }}</h3>
              <p class="mt-2 text-2xl font-bold text-neutral-900">{{ preview.price }}</p>
              <p class="text-xs text-neutral-500">{{ preview.period }}</p>
              <p v-if="preview.tagline" class="mt-2 text-sm text-neutral-600">{{ preview.tagline }}</p>
              <div v-if="preview.modules.length" class="mt-3 flex flex-wrap gap-1 border-t border-neutral-100 pt-3">
                <span v-for="m in preview.modules" :key="m" class="rounded-full bg-secondary-50 px-2 py-0.5 text-xs font-medium text-secondary-800">{{ m }}</span>
              </div>
              <ul v-if="preview.features.length" class="mt-3 space-y-1 border-t border-neutral-100 pt-3">
                <li v-for="(f, i) in preview.features" :key="i" class="flex gap-1.5 text-sm text-neutral-800"><span class="text-primary-600">✓</span>{{ f }}</li>
              </ul>
            </article>
          </div>
        </div>
      </template>
    </form>

    <template #footer>
      <BaseButton v-if="step > 0" variant="ghost" class="mr-auto" @click="go(step - 1)">← {{ t('bo.billing.plans.form.back') }}</BaseButton>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <!-- An existing plan can be saved from any step. -->
      <BaseButton v-if="plan && step < 3" variant="outline" :loading="saving" @click="save">{{ t('bo.common.save') }}</BaseButton>
      <BaseButton v-if="step < 3" type="submit" form="bo-plan">{{ t('bo.billing.plans.form.next') }} →</BaseButton>
      <BaseButton v-else type="submit" form="bo-plan" :loading="saving">{{ plan ? t('bo.common.save') : t('bo.billing.plans.form.create') }}</BaseButton>
    </template>
  </BaseModal>
</template>
