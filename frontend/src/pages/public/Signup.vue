<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { publicPlansService, type PublicPlan, type PublicPlans } from '@/services/plans'
import { signupService, type SignupForm } from '@/services/signup'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'

/**
 * Self-service sign-up (/signup?plan=…, docs/backoffice/stage-8-signup.md, 8b),
 * steps: the school, its owner, the plan (skipped when the link names one,
 * as the plan cards do), then a last check. A free
 * trial's school is created at once; a paid plan goes on to payment.
 */
const { t, te, locale } = useI18n()
const route = useRoute()
const router = useRouter()

const plans = ref<PublicPlans | null>(null)
const loadFailed = ref(false)
/** The customer came from a plan card: the plan is already chosen, so there is no plan step. */
const planChosen = ref(false)

const systemCode = typeof route.query.system === 'string' && route.query.system ? route.query.system : null

const form = ref<SignupForm>({
  plan: typeof route.query.plan === 'string' ? route.query.plan : 'trial',
  system: systemCode,
  periods: 1,
  school_name: '',
  school_name_en: '',
  slug: '',
  owner_name: '',
  owner_email: '',
  owner_phone: '',
  password: '',
  password_confirmation: '',
  locale: locale.value === 'km' ? 'km' : 'en',
  website: '',
})

onMounted(async () => {
  try {
    plans.value = await publicPlansService.list(systemCode)
    // An unknown plan in the link: start from the free trial (or the first plan).
    const codes = plans.value.plans.map((p) => p.code)
    const asked = route.query.plan
    planChosen.value = typeof asked === 'string' && (codes.includes(asked) || (asked === 'trial' && plans.value.trial_days > 0))
    if (form.value.plan !== 'trial' && !codes.includes(form.value.plan)) form.value.plan = plans.value.trial_days > 0 ? 'trial' : (codes[0] ?? 'trial')
    if (form.value.plan === 'trial' && plans.value.trial_days < 1 && codes.length) form.value.plan = codes[0]
  } catch {
    loadFailed.value = true
  }
})

const km = computed(() => locale.value === 'km')
/** Not the school system: "organization" words (signupPage.org.*) where there are any. */
const org = computed(() => plans.value !== null && !plans.value.system.is_school)
function w(key: string, values?: Record<string, unknown>): string {
  const orgKey = key.replace('signupPage.', 'signupPage.org.')
  return org.value && te(orgKey) ? t(orgKey, values ?? {}) : t(key, values ?? {})
}
const systemName = computed(() => (plans.value ? (km.value ? plans.value.system.name_km || plans.value.system.name : plans.value.system.name) : ''))
const planName = (p: PublicPlan) => ((km.value ? p.name_km || p.name : p.name) ?? '').trim()
const plan = computed(() => plans.value?.plans.find((p) => p.code === form.value.plan) ?? null)
const isTrial = computed(() => form.value.plan === 'trial')

const periodWord = (p: PublicPlan) =>
  p.period_months === 1 ? t('plansPage.perMonth') : p.period_months === 12 ? t('plansPage.perYear') : p.period_months === null ? t('plansPage.once') : t('plansPage.perMonths', { n: p.period_months })
const price = (p: PublicPlan, times = 1) => formatMoney(Number(p.price) * times, p.currency).replace(/\.00$/, '')

/** How long to buy: 1, 3, 6 or 12 months for a monthly plan; 1–3 periods otherwise; once for a one-time plan. */
const periodOptions = computed(() => {
  const p = plan.value
  if (!p || p.period_months === null) return []
  const counts = p.period_months === 1 ? [1, 3, 6, 12] : [1, 2, 3]
  return counts.map((n) => ({ n, months: n * (p.period_months ?? 1) }))
})
watch(
  () => form.value.plan,
  () => (form.value.periods = 1),
)
const monthsLabel = (n: number) => (n === 1 ? w('signupPage.oneMonth') : w('signupPage.months', { n }))
const total = computed(() => (plan.value ? price(plan.value, plan.value.period_months === null ? 1 : (form.value.periods ?? 1)) : ''))

// ── The web address (school code) ───────────────────────────────────────────
const slugTouched = ref(false)
const slugState = ref<{ checking: boolean; available: boolean | null; message: string | null }>({ checking: false, available: null, message: null })
function suggestSlug(): void {
  if (slugTouched.value) return
  form.value.slug = form.value.school_name_en
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 32)
}
let slugTimer: ReturnType<typeof setTimeout> | undefined
watch(
  () => form.value.slug,
  (slug) => {
    clearTimeout(slugTimer)
    slugState.value = { checking: false, available: null, message: null }
    if (slug.length < 3) return
    slugState.value.checking = true
    slugTimer = setTimeout(async () => {
      try {
        const r = await signupService.checkSlug(slug, form.value.owner_email)
        if (form.value.slug === slug) slugState.value = { checking: false, available: r.available, message: r.message }
      } catch {
        slugState.value = { checking: false, available: null, message: null }
      }
    }, 400)
  },
)

// ── Steps ───────────────────────────────────────────────────────────────────
type Step = 'school' | 'owner' | 'plan' | 'confirm'
const STEPS = computed<Step[]>(() => (planChosen.value ? ['school', 'owner', 'confirm'] : ['school', 'owner', 'plan', 'confirm']))
const step = ref(0)
const current = computed<Step>(() => STEPS.value[step.value])
const isLast = computed(() => step.value === STEPS.value.length - 1)
const localErrors = ref<Record<string, string>>({})
const serverError = ref<ApiRequestError | null>(null)
const fieldError = (f: string) => localErrors.value[f] ?? serverError.value?.fieldError(f)

// A field's message goes as soon as the field is changed.
for (const key of Object.keys(form.value) as (keyof SignupForm)[]) {
  watch(
    () => form.value[key],
    () => {
      if (localErrors.value[key]) localErrors.value = Object.fromEntries(Object.entries(localErrors.value).filter(([k]) => k !== key))
      if (serverError.value?.errors?.[key]) {
        const rest = Object.fromEntries(Object.entries(serverError.value.errors).filter(([k]) => k !== key))
        serverError.value = new ApiRequestError(serverError.value.message, serverError.value.status, serverError.value.code, rest)
      }
    },
  )
}

const STEP_FIELDS: Partial<Record<Step, string[]>> = {
  school: ['school_name', 'school_name_en', 'slug'],
  owner: ['owner_name', 'owner_email', 'owner_phone', 'password'],
  plan: ['plan'],
}

function check(n: Step): boolean {
  const e: Record<string, string> = {}
  const f = form.value
  const required = w('signupPage.required')
  if (n === 'school') {
    if (!f.school_name.trim()) e.school_name = required
    if (!/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/.test(f.slug)) e.slug = w('signupPage.slugRule')
    else if (slugState.value.available === false) e.slug = slugState.value.message ?? w('signupPage.slugTaken')
  }
  if (n === 'owner') {
    if (!f.owner_name.trim()) e.owner_name = required
    if (!/^\S+@\S+\.\S+$/.test(f.owner_email.trim())) e.owner_email = w('signupPage.emailRule')
    if (!/^[0-9+\s()-]{6,}$/.test(f.owner_phone.trim())) e.owner_phone = w('signupPage.phoneRule')
    if (f.password.length < 8) e.password = w('signupPage.passwordRule')
    else if (f.password !== f.password_confirmation) e.password_confirmation = w('signupPage.passwordMatch')
  }
  localErrors.value = e
  return Object.keys(e).length === 0
}

function next(): void {
  if (!check(current.value)) return
  serverError.value = null
  step.value = Math.min(step.value + 1, STEPS.value.length - 1)
  window.scrollTo({ top: 0 })
}
function back(): void {
  localErrors.value = {}
  step.value = Math.max(step.value - 1, 0)
}

const submitting = ref(false)
async function submit(): Promise<void> {
  submitting.value = true
  serverError.value = null
  try {
    const result = await signupService.start({ ...form.value, periods: isTrial.value ? null : form.value.periods })
    await router.replace(`/signup/${result.token}`)
  } catch (e) {
    serverError.value = e instanceof ApiRequestError ? e : new ApiRequestError(w('signupPage.failed'), 0)
    // Back to the step of the first field the server refused.
    const bad = Object.keys(serverError.value.errors ?? {})
    const at = Object.entries(STEP_FIELDS).find(([, fields]) => fields.some((f) => bad.includes(f) || bad.includes(`${f}_confirmation`)))
    // The plan itself refused (e.g. no longer sold): show the plan step so another can be picked.
    if (at?.[0] === 'plan') planChosen.value = false
    if (at) step.value = STEPS.value.indexOf(at[0] as Step)
  } finally {
    submitting.value = false
  }
}

const card = 'rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm sm:p-6'
</script>

<template>
  <section class="bg-gradient-to-b from-secondary-50 to-white px-4 pt-16 pb-12 sm:px-6 sm:py-14 lg:px-8">
    <div class="mx-auto max-w-2xl">
      <RouterLink :to="systemCode ? `/plans/${systemCode}` : '/plans'" class="text-sm font-medium text-secondary-700 hover:underline">← {{ w('signupPage.backToPlans') }}</RouterLink>
      <h1 class="mt-3 text-2xl font-bold text-neutral-900 sm:text-3xl">{{ w('signupPage.title') }}</h1>
      <p v-if="systemName" class="mt-1 text-neutral-600">{{ systemName }}</p>

      <!-- Step bar -->
      <ol class="mt-6 grid gap-2" :class="STEPS.length === 3 ? 'grid-cols-3' : 'grid-cols-4'" :aria-label="w('signupPage.steps')">
        <li v-for="(s, i) in STEPS" :key="s" class="min-w-0">
          <div class="h-1.5 rounded-full" :class="i <= step ? 'bg-primary-500' : 'bg-neutral-200'" />
          <p class="mt-1.5 truncate text-xs" :class="i === step ? 'font-semibold text-neutral-900' : 'text-neutral-500'" :aria-current="i === step ? 'step' : undefined">
            {{ i + 1 }}. {{ w(`signupPage.step.${s}`) }}
          </p>
        </li>
      </ol>

      <div v-if="!plans && !loadFailed" class="flex justify-center py-16"><BaseSpinner /></div>
      <BaseAlert v-else-if="loadFailed" variant="danger" class="mt-6">{{ w('signupPage.failed') }}</BaseAlert>

      <form v-else class="mt-6" novalidate @submit.prevent="isLast ? submit() : next()">
        <!-- Never shown to people. -->
        <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" class="absolute -left-[9999px] h-0 w-0 opacity-0" aria-hidden="true" />

        <BaseAlert v-if="serverError && !serverError.errors" variant="danger" class="mb-4">{{ serverError.message }}</BaseAlert>

        <!-- 1. The school -->
        <div v-show="current === 'school'" :class="card" class="space-y-4">
          <BaseInput v-model="form.school_name" :label="w('signupPage.schoolName')" :placeholder="w('signupPage.schoolNameHint')" :error="fieldError('school_name')" required />
          <BaseInput
            v-model="form.school_name_en"
            :label="w('signupPage.schoolNameEn')"
            :error="fieldError('school_name_en')"
            @update:model-value="suggestSlug"
          />
          <div>
            <BaseInput
              v-model="form.slug"
              :label="w('signupPage.slug')"
              :hint="w('signupPage.slugHint')"
              :error="fieldError('slug')"
              autocomplete="off"
              required
              @update:model-value="
                (v: string) => {
                  slugTouched = true
                  form.slug = v.toLowerCase().trim()
                }
              "
            />
            <p v-if="!fieldError('slug') && form.slug.length >= 3" class="mt-1 text-xs" aria-live="polite">
              <span v-if="slugState.checking" class="text-neutral-500">{{ w('signupPage.checking') }}</span>
              <span v-else-if="slugState.available" class="text-green-700">✓ {{ w('signupPage.slugFree') }}</span>
              <span v-else-if="slugState.available === false" class="text-red-600">{{ slugState.message }}</span>
            </p>
          </div>
        </div>

        <!-- 2. The owner -->
        <div v-show="current === 'owner'" :class="card" class="space-y-4">
          <p class="text-sm text-neutral-600">{{ w('signupPage.ownerHint') }}</p>
          <BaseInput v-model="form.owner_name" :label="w('signupPage.ownerName')" :error="fieldError('owner_name')" autocomplete="name" required />
          <div class="grid gap-4 sm:grid-cols-2">
            <BaseInput v-model="form.owner_email" type="email" :label="w('signupPage.email')" :error="fieldError('owner_email')" autocomplete="email" required />
            <BaseInput v-model="form.owner_phone" type="tel" :label="w('signupPage.phone')" :error="fieldError('owner_phone')" autocomplete="tel" required />
            <BaseInput
              v-model="form.password"
              type="password"
              :label="w('signupPage.password')"
              :hint="w('signupPage.passwordRule')"
              :error="fieldError('password')"
              autocomplete="new-password"
              required
            />
            <BaseInput
              v-model="form.password_confirmation"
              type="password"
              :label="w('signupPage.passwordAgain')"
              :error="fieldError('password_confirmation')"
              autocomplete="new-password"
              required
            />
          </div>
        </div>

        <!-- The plan (only when the link did not name one) -->
        <div v-show="current === 'plan'" class="space-y-3">
          <p v-if="fieldError('plan')" class="text-sm text-red-600">{{ fieldError('plan') }}</p>
          <label
            v-if="plans && plans.trial_days > 0"
            class="flex cursor-pointer items-start gap-3 rounded-2xl border bg-white p-4 shadow-sm"
            :class="isTrial ? 'border-primary-500 ring-2 ring-primary-500' : 'border-neutral-200'"
          >
            <input v-model="form.plan" type="radio" value="trial" class="mt-1 text-primary-600 focus:ring-primary-500" />
            <span class="min-w-0 flex-1">
              <span class="block font-semibold text-neutral-900">{{ t('plansPage.trial') }}</span>
              <span class="block text-sm text-neutral-600">{{ t('plansPage.free') }} · {{ t('plansPage.trialDays', { n: plans.trial_days }) }}</span>
            </span>
          </label>
          <label
            v-for="p in plans?.plans ?? []"
            :key="p.code"
            class="flex cursor-pointer items-start gap-3 rounded-2xl border bg-white p-4 shadow-sm"
            :class="form.plan === p.code ? 'border-primary-500 ring-2 ring-primary-500' : 'border-neutral-200'"
          >
            <input v-model="form.plan" type="radio" :value="p.code" class="mt-1 text-primary-600 focus:ring-primary-500" />
            <span class="min-w-0 flex-1">
              <span class="block font-semibold text-neutral-900">
                {{ planName(p) }}
                <span v-if="p.recommended" class="ml-1 rounded-full bg-primary-500 px-2 py-0.5 text-[11px] font-semibold text-secondary-900">{{ t('plansPage.recommended') }}</span>
              </span>
              <span class="block text-sm text-neutral-600">{{ price(p) }} {{ periodWord(p) }}</span>
            </span>
          </label>
        </div>

        <!-- Check and confirm (and how long to buy) -->
        <div v-show="current === 'confirm'" :class="card">
          <p v-if="fieldError('plan')" class="mb-3 text-sm text-red-600">{{ fieldError('plan') }}</p>
          <div v-if="!isTrial && periodOptions.length" class="mb-4 border-b border-neutral-100 pb-4">
            <p class="text-sm font-medium text-neutral-700">{{ w('signupPage.howLong') }}</p>
            <div class="mt-2 flex flex-wrap gap-2">
              <button
                v-for="o in periodOptions"
                :key="o.n"
                type="button"
                class="rounded-lg border px-3 py-1.5 text-sm"
                :class="form.periods === o.n ? 'border-primary-500 bg-primary-50 font-semibold text-neutral-900' : 'border-neutral-300 text-neutral-700 hover:bg-neutral-50'"
                @click="form.periods = o.n"
              >
                {{ monthsLabel(o.months) }}
              </button>
            </div>
          </div>
          <dl class="divide-y divide-neutral-100 text-sm">
            <div class="flex justify-between gap-4 py-2">
              <dt class="text-neutral-500">{{ w('signupPage.schoolName') }}</dt>
              <dd class="text-right font-medium break-words text-neutral-900">{{ form.school_name }}<span v-if="form.school_name_en" class="block font-normal text-neutral-600">{{ form.school_name_en }}</span></dd>
            </div>
            <div class="flex justify-between gap-4 py-2">
              <dt class="text-neutral-500">{{ w('signupPage.slug') }}</dt>
              <dd class="font-mono text-neutral-900">{{ form.slug }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2">
              <dt class="text-neutral-500">{{ w('signupPage.ownerName') }}</dt>
              <dd class="text-right break-all text-neutral-900">{{ form.owner_name }}<span class="block text-neutral-600">{{ form.owner_email }} · {{ form.owner_phone }}</span></dd>
            </div>
            <div class="flex justify-between gap-4 py-2">
              <dt class="text-neutral-500">{{ w('signupPage.step.plan') }}</dt>
              <dd class="text-right text-neutral-900">
                <template v-if="isTrial">{{ t('plansPage.trial') }} · {{ t('plansPage.trialDays', { n: plans?.trial_days ?? 0 }) }}</template>
                <template v-else-if="plan">
                  {{ planName(plan) }}<template v-if="plan.period_months !== null"> · {{ monthsLabel((form.periods ?? 1) * plan.period_months) }}</template>
                </template>
                <RouterLink
                  v-if="planChosen"
                  :to="systemCode ? `/plans/${systemCode}` : '/plans'"
                  class="block text-xs font-medium text-secondary-700 hover:underline"
                >
                  {{ w('signupPage.changePlan') }}
                </RouterLink>
              </dd>
            </div>
            <div v-if="!isTrial && plan" class="flex justify-between gap-4 py-2">
              <dt class="font-semibold text-neutral-900">{{ w('signupPage.total') }}</dt>
              <dd class="text-lg font-bold text-neutral-900">{{ total }}</dd>
            </div>
          </dl>
          <p class="mt-4 text-sm text-neutral-600">{{ isTrial ? w('signupPage.trialNote') : w('signupPage.payNote') }}</p>
        </div>

        <div class="mt-6 flex items-center justify-between gap-3">
          <BaseButton v-if="step > 0" variant="ghost" @click="back">← {{ w('signupPage.back') }}</BaseButton>
          <span v-else />
          <BaseButton v-if="!isLast" type="submit">{{ w('signupPage.next') }} →</BaseButton>
          <BaseButton v-else type="submit" :loading="submitting">{{ isTrial ? w('signupPage.createSchool') : w('signupPage.continueToPay') }}</BaseButton>
        </div>
      </form>
    </div>
  </section>
</template>
