<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'

import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { publicPlansService, type PublicPlan, type PublicPlans } from '@/services/plans'
import { formatMoney } from '@/utils/currency'

/**
 * The plans page (/plans), opened by the services page's plans card
 * (docs/backoffice/stage-8-signup.md, 8a): a Free trial card, then each
 * plan shown on the website — price, a short line, its features. Khmer to
 * Khmer visitors, English to the rest, each falling back to the other.
 */
const { t, locale } = useI18n()

const route = useRoute()
/** /plans/{system}; /plans = the school system. */
const systemCode = computed(() => (typeof route.params.system === 'string' && route.params.system ? route.params.system : null))

const data = ref<PublicPlans | null>(null)
const failed = ref(false)

async function load(): Promise<void> {
  data.value = null
  failed.value = false
  try {
    data.value = await publicPlansService.list(systemCode.value)
  } catch {
    failed.value = true
  }
}
onMounted(load)
watch(systemCode, load)

const km = computed(() => locale.value === 'km')
/** The system's name as the page title (falls back to the usual title). */
const title = computed(() => (data.value ? (km.value ? data.value.system.name_km || data.value.system.name : data.value.system.name) : t('plansPage.title')))
const signupQuery = (plan: string) => (data.value && !data.value.system.is_school ? { system: data.value.system.code, plan } : { plan })
const width = 'w-full sm:w-[calc(50%-0.625rem)] lg:w-[calc(25%-0.9375rem)]'
const button = 'mt-5 inline-flex w-full items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm'
const pick = (kmText: string | null, enText: string | null) => ((km.value ? kmText || enText : enText || kmText) ?? '').trim()

const cards = computed(() =>
  (data.value?.plans ?? []).map((p: PublicPlan) => ({
    plan: p,
    name: pick(p.name_km, p.name) || p.name,
    tagline: pick(p.tagline_km, p.tagline_en),
    features: km.value ? (p.features_km.length ? p.features_km : p.features_en) : p.features_en.length ? p.features_en : p.features_km,
    modules: (p.modules ?? []).map((m) => (km.value ? m.label_km || m.label : m.label)),
    price: formatMoney(Number(p.price), p.currency).replace(/\.00$/, ''),
    period:
      p.period_months === null
        ? t('plansPage.once')
        : p.period_months === 1
          ? t('plansPage.perMonth')
          : p.period_months === 12
            ? t('plansPage.perYear')
            : t('plansPage.perMonths', { n: p.period_months }),
  })),
)
</script>

<template>
  <section class="bg-gradient-to-b from-secondary-50 to-white px-4 pt-16 pb-12 sm:px-6 sm:py-14 lg:px-8">
    <div class="mx-auto max-w-6xl">
      <RouterLink to="/" class="text-sm font-medium text-secondary-700 hover:underline">← {{ t('servicesPage.backToServices') }}</RouterLink>
      <h1 class="mt-3 text-center text-2xl font-bold text-neutral-900 sm:text-3xl">{{ title }}</h1>
      <p class="mx-auto mt-2 max-w-2xl text-center text-neutral-600">{{ t('plansPage.subtitle') }}</p>

      <div v-if="!data && !failed" class="flex justify-center py-16"><BaseSpinner /></div>
      <p v-else-if="failed || (data && data.plans.length === 0 && data.trial_days < 1)" class="py-16 text-center text-neutral-500">
        {{ t('plansPage.none') }}
      </p>

      <!-- Centred, so two or three plans do not sit to one side. -->
      <div v-else-if="data" class="mt-10 flex flex-wrap items-stretch justify-center gap-5">
        <!-- Free trial: its length from back office → Billing → Settings. -->
        <article v-if="data.trial_days > 0" :class="width" class="flex flex-col rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('plansPage.trial') }}</h2>
          <p class="mt-3 text-3xl font-bold text-neutral-900">{{ t('plansPage.free') }}</p>
          <p class="text-sm text-neutral-500">{{ t('plansPage.trialDays', { n: data.trial_days }) }}</p>
          <p class="mt-3 flex-1 text-sm text-neutral-600">{{ t('plansPage.trialText') }}</p>
          <RouterLink :to="{ path: '/signup', query: signupQuery('trial') }" :class="button" class="border border-neutral-300 bg-white text-neutral-800 hover:bg-neutral-50">
            {{ t('plansPage.startTrial') }}
          </RouterLink>
        </article>

        <article
          v-for="c in cards"
          :key="c.plan.code"
          class="relative flex flex-col rounded-2xl border bg-white p-6 shadow-sm"
          :class="[width, c.plan.recommended ? 'border-primary-500 ring-2 ring-primary-500' : 'border-neutral-200']"
        >
          <span
            v-if="c.plan.recommended"
            class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-primary-500 px-3 py-0.5 text-xs font-semibold whitespace-nowrap text-secondary-900"
          >
            {{ t('plansPage.recommended') }}
          </span>
          <h2 class="text-lg font-semibold text-neutral-900">{{ c.name }}</h2>
          <p class="mt-3 text-3xl font-bold text-neutral-900">{{ c.price }}</p>
          <p class="text-sm text-neutral-500">{{ c.period }}</p>
          <p v-if="c.tagline" class="mt-3 text-sm text-neutral-600">{{ c.tagline }}</p>
          <!-- Its main menus, from the plan's modules in the back office. -->
          <div v-if="c.modules.length" class="mt-4 border-t border-neutral-100 pt-4">
            <p class="text-xs font-medium tracking-wide text-neutral-500 uppercase">{{ t('plansPage.includes') }}</p>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <span v-for="m in c.modules" :key="m" class="rounded-full bg-secondary-50 px-2.5 py-0.5 text-xs font-medium text-secondary-800">{{ m }}</span>
            </div>
          </div>
          <ul v-if="c.features.length" class="mt-4 space-y-2 border-t border-neutral-100 pt-4">
            <li v-for="(f, i) in c.features" :key="i" class="flex items-start gap-2 text-sm text-neutral-800">
              <span class="mt-0.5 text-primary-600" aria-hidden="true">✓</span>
              <span>{{ f }}</span>
            </li>
          </ul>
          <div class="flex-1" />
          <RouterLink
            :to="{ path: '/signup', query: signupQuery(c.plan.code) }"
            :class="[button, c.plan.recommended ? 'bg-primary-500 text-secondary-900 hover:bg-primary-400' : 'bg-secondary-700 text-white hover:bg-secondary-800']"
          >
            {{ t('plansPage.choose') }}
          </RouterLink>
        </article>
      </div>
    </div>
  </section>
</template>
