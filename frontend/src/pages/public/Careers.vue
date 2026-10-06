<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { applyForJob, DOCUMENT_ACCEPT, MAX_DOCUMENT_BYTES } from '@/services/applicants'
import { careersService, formatSalary, type CareerJob } from '@/services/jobPositions'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * The public Careers page — the school's open jobs, from HRM > Recruitment
 * (a job with an active Website posting, see CareerController). `?job=<id>`
 * opens one job's full description, so its link can be shared, with the
 * Apply form under it (see CareerController::apply()).
 */
const { t } = useI18n()
const route = useRoute()

const jobId = computed(() => {
  const id = Number(route.query.job)
  return Number.isInteger(id) && id > 0 ? id : null
})

const jobs = ref<CareerJob[]>([])
const job = ref<CareerJob | null>(null)
const loading = ref(true)
const notFound = ref(false)

async function load() {
  loading.value = true
  notFound.value = false
  try {
    if (jobId.value === null) {
      job.value = null
      jobs.value = await careersService.list().catch(() => [])
    } else {
      job.value = await careersService.get(jobId.value)
      notFound.value = job.value === null
    }
  } finally {
    loading.value = false
  }
}

watch(jobId, load, { immediate: true })

// --- Apply form -------------------------------------------------------------

const application = reactive({
  first_name: '',
  last_name: '',
  gender: '',
  date_of_birth: '',
  phone: '',
  email: '',
  address: '',
  expected_salary: '',
  available_from: '',
  cover_letter: '',
})
const cv = ref<File | null>(null)
const applyErrors = ref<Record<string, string[]>>({})
const applyError = ref<string | null>(null)
const applying = ref(false)
const applied = ref(false)

const genderOptions = computed(() => [
  { value: 'male', label: t('common.genderMale') },
  { value: 'female', label: t('common.genderFemale') },
])

function onCv(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0] ?? null
  if (file && file.size > MAX_DOCUMENT_BYTES) {
    applyErrors.value = { ...applyErrors.value, cv: [t('careers.fileTooLarge')] }
    ;(event.target as HTMLInputElement).value = ''
    cv.value = null
    return
  }
  applyErrors.value = { ...applyErrors.value, cv: [] }
  cv.value = file
}

async function apply() {
  if (!job.value) return
  if (!cv.value) {
    applyErrors.value = { cv: [t('careers.cvRequired')] }
    return
  }

  applying.value = true
  applyErrors.value = {}
  applyError.value = null
  try {
    await applyForJob(job.value.id, { ...application, cv: cv.value })
    applied.value = true
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) applyErrors.value = e.errors
    else applyError.value = e instanceof ApiRequestError && e.status === 429 ? t('careers.tooMany') : t('careers.applyFailed')
  } finally {
    applying.value = false
  }
}

// A different job opened: start its form fresh.
watch(jobId, () => {
  applied.value = false
  applyErrors.value = {}
  applyError.value = null
})

function facts(item: CareerJob): string[] {
  return [
    t(`admin.recruitment.employmentTypes.${item.employment_type}`),
    item.location,
    item.department,
    item.headcount > 1 ? t('careers.positions', { count: item.headcount }) : null,
  ].filter((fact): fact is string => Boolean(fact))
}
</script>

<template>
  <div>
    <PageHero :title="job?.title ?? t('careers.title')" :subtitle="job ? undefined : t('careers.subtitle')" />

    <SectionContainer>
      <nav v-if="jobId !== null" class="mb-6 text-sm" aria-label="breadcrumb">
        <RouterLink to="/careers" class="font-medium text-primary-700 hover:underline">← {{ t('careers.allJobs') }}</RouterLink>
      </nav>

      <div v-if="loading" class="grid gap-4 md:grid-cols-2">
        <div v-for="i in 4" :key="i" class="h-36 animate-pulse rounded-2xl bg-neutral-100" />
      </div>

      <EmptyState v-else-if="notFound" :title="t('careers.notFoundTitle')" :message="t('careers.notFoundMessage')" />

      <!-- The list -->
      <template v-else-if="jobId === null">
        <EmptyState v-if="jobs.length === 0" :title="t('careers.emptyTitle')" :message="t('careers.emptyMessage')" />
        <div v-else class="grid gap-4 md:grid-cols-2">
          <RouterLink
            v-for="item in jobs"
            :key="item.id"
            :to="{ path: '/careers', query: { job: item.id } }"
            class="flex flex-col rounded-2xl border border-neutral-200 bg-white p-5 transition hover:border-primary-400 hover:shadow-md"
          >
            <h2 class="text-lg font-semibold text-neutral-900">{{ item.title }}</h2>
            <p class="mt-1 text-sm text-neutral-600">{{ facts(item).join(' · ') }}</p>
            <p class="mt-3 text-sm font-medium text-neutral-800">{{ formatSalary(item) }}</p>
            <span class="mt-3 text-sm font-semibold text-primary-700">{{ t('careers.viewAndApply') }} →</span>
            <p v-if="item.closes_on" class="mt-auto pt-3 text-xs text-neutral-500">{{ t('careers.closesOn', { date: formatDate(item.closes_on) }) }}</p>
          </RouterLink>
        </div>
      </template>

      <!-- One job -->
      <article v-else-if="job" class="max-w-3xl">
        <div class="flex flex-wrap gap-2">
          <span v-for="fact in facts(job)" :key="fact" class="rounded-full bg-primary-50 px-3 py-1 text-sm text-primary-800">{{ fact }}</span>
        </div>
        <dl class="mt-6 grid gap-4 sm:grid-cols-2">
          <div>
            <dt class="text-sm text-neutral-500">{{ t('careers.salary') }}</dt>
            <dd class="font-semibold text-neutral-900">{{ formatSalary(job) }}</dd>
          </div>
          <div v-if="job.closes_on">
            <dt class="text-sm text-neutral-500">{{ t('careers.deadline') }}</dt>
            <dd class="font-semibold text-neutral-900">{{ formatDate(job.closes_on) }}</dd>
          </div>
        </dl>

        <section v-if="job.description" class="mt-8">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('careers.description') }}</h2>
          <p class="mt-2 whitespace-pre-line text-neutral-700">{{ job.description }}</p>
        </section>
        <section v-if="job.requirements" class="mt-8">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('careers.requirements') }}</h2>
          <p class="mt-2 whitespace-pre-line text-neutral-700">{{ job.requirements }}</p>
        </section>

        <!-- Apply -->
        <section id="apply" class="mt-10 rounded-2xl border border-neutral-200 bg-white p-5 sm:p-6">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('careers.applyTitle') }}</h2>

          <BaseAlert v-if="applied" variant="success" class="mt-4">{{ t('careers.applied') }}</BaseAlert>

          <form v-else class="mt-4 space-y-4" @submit.prevent="apply">
            <BaseAlert v-if="applyError" variant="danger">{{ applyError }}</BaseAlert>

            <div class="grid gap-4 sm:grid-cols-2">
              <BaseInput v-model="application.first_name" required :label="t('careers.firstName')" :error="applyErrors.first_name?.[0]" />
              <BaseInput v-model="application.last_name" required :label="t('careers.lastName')" :error="applyErrors.last_name?.[0]" />
              <BaseInput v-model="application.phone" required type="tel" :label="t('careers.phone')" :error="applyErrors.phone?.[0]" />
              <BaseInput v-model="application.email" type="email" :label="t('careers.email')" :error="applyErrors.email?.[0]" />
              <BaseSelect v-model="application.gender" :options="genderOptions" :placeholder="t('careers.selectGender')" :label="t('careers.gender')" />
              <BaseInput v-model="application.date_of_birth" type="date" :label="t('careers.dateOfBirth')" :error="applyErrors.date_of_birth?.[0]" />
              <BaseInput v-model="application.address" class="sm:col-span-2" :label="t('careers.address')" :error="applyErrors.address?.[0]" />
              <BaseInput v-model="application.expected_salary" type="number" min="0" :label="t('careers.expectedSalary')" :error="applyErrors.expected_salary?.[0]" />
              <BaseInput v-model="application.available_from" type="date" :label="t('careers.availableFrom')" :error="applyErrors.available_from?.[0]" />
            </div>

            <div>
              <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('careers.coverLetter') }}</label>
              <textarea
                v-model="application.cover_letter"
                rows="4"
                class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
              />
            </div>

            <div>
              <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('careers.cv') }} <span class="text-danger-600">*</span></label>
              <input
                type="file"
                :accept="DOCUMENT_ACCEPT"
                class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
                @change="onCv"
              />
              <p class="mt-1 text-xs text-neutral-500">{{ t('careers.cvHint') }}</p>
              <p v-if="applyErrors.cv?.[0]" class="mt-1.5 text-sm text-danger-600">{{ applyErrors.cv[0] }}</p>
            </div>

            <BaseButton type="submit" :loading="applying">{{ t('careers.submit') }}</BaseButton>
          </form>
        </section>
      </article>
    </SectionContainer>
  </div>
</template>
