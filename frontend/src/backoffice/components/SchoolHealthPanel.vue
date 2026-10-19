<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ApiRequestError } from '@/types/api'
import { formatDate, formatDateTime } from '@/utils/date'

import { formatBytes, schoolsApi, type HealthLine, type HealthState, type School, type SchoolHealthReport } from '../api'
import { endLabel } from '../billing'

/** One school's health in one place (stage 6a): status, database, backup, plan, students, users, sign-in, failed jobs. */
const props = defineProps<{ school: School }>()

const { t } = useI18n()

const report = ref<SchoolHealthReport | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  try {
    report.value = await schoolsApi.health(props.school.id)
    error.value = null
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
  }
}

onMounted(load)
defineExpose({ load })

const dot: Record<HealthState, string> = {
  ok: 'bg-green-500',
  warn: 'bg-amber-500',
  bad: 'bg-red-500',
  off: 'bg-neutral-300',
}

const headline: Record<Exclude<HealthState, 'off'>, string> = {
  ok: 'text-green-700',
  warn: 'text-amber-700',
  bad: 'text-red-700',
}

/** The main text of a line, and an optional second, smaller one. */
function describe(line: HealthLine): { main: string; sub?: string } {
  if (line.state === 'off') return { main: t('bo.health.notApplicable') }

  switch (line.key) {
    case 'status':
      return {
        main: [t(`bo.schools.statuses.${line.status}`), line.since ? t('bo.health.since', { date: formatDate(line.since) }) : ''].filter(Boolean).join(' · '),
        sub: line.state !== 'ok' && line.reason ? `“${line.reason}”` : undefined,
      }
    case 'database': {
      const where = [t(`bo.placement.${line.mode}`), line.database, line.server].filter(Boolean).join(' · ')
      if (line.error) return { main: t('bo.health.unreachable', { error: line.error }), sub: where }
      if (line.exists === false) return { main: t('bo.health.missing'), sub: where }
      return {
        main: [where, formatBytes(line.size_bytes)].join(' · '),
        sub: line.pending_migrations ? t('bo.health.pending', { n: line.pending_migrations }) : t('bo.health.upToDate'),
      }
    }
    case 'backup': {
      if (!line.at) return { main: t('bo.health.noBackup') }
      const parts = [formatDateTime(line.at)]
      if (line.state === 'bad') parts.push(t('bo.health.tooOld'))
      else if (line.copied) parts.push(t('bo.health.copied'))
      return {
        main: parts.join(' · '),
        sub: line.copy_error ? t('bo.health.copyFailed', { error: line.copy_error }) : !line.schedule_enabled ? t('bo.health.scheduleOff') : undefined,
      }
    }
    case 'plan':
      if (!line.subscription) return { main: t('bo.health.noPlan') }
      return {
        main: [line.subscription.plan?.name, t(`bo.billing.statuses.${line.subscription.status}`)].filter(Boolean).join(' · '),
        sub: endLabel(line.subscription, t) || undefined,
      }
    case 'students':
      if (line.unavailable || line.active === null || line.active === undefined) return { main: t('bo.health.unavailable') }
      return {
        main:
          line.limit === null || line.limit === undefined
            ? t('bo.health.studentsNoLimit', { active: line.active.toLocaleString() })
            : t('bo.health.studentsOf', { active: line.active.toLocaleString(), limit: line.limit.toLocaleString() }),
      }
    case 'users':
      return { main: t('bo.health.usersLine', { total: line.total ?? 0, active: line.active ?? 0, days: line.active_days ?? 30 }) }
    case 'sign_in':
      return {
        main: line.at ? t('bo.health.signInBy', { at: formatDateTime(line.at), name: line.name ?? '' }) : t('bo.health.noSignIn', { days: line.quiet_days ?? 14 }),
        sub: line.at && line.state === 'warn' ? t('bo.health.noSignIn', { days: line.quiet_days ?? 14 }) : undefined,
      }
    case 'failed_jobs':
      return line.count
        ? { main: t('bo.health.failures', { count: line.count, days: line.days ?? 7 }), sub: line.latest ? t('bo.health.latest', { message: line.latest.message }) : undefined }
        : { main: t('bo.health.noFailures', { days: line.days ?? 7 }) }
  }
}
</script>

<template>
  <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
    <div class="mb-3 flex items-start justify-between gap-2">
      <div>
        <h2 class="font-semibold text-neutral-900">{{ t('bo.health.title') }}</h2>
        <p v-if="report" class="text-sm font-medium" :class="headline[report.state]">{{ t(`bo.health.states.${report.state}`) }}</p>
      </div>
      <button
        type="button"
        class="text-sm text-secondary-700 hover:underline disabled:opacity-50"
        :disabled="loading"
        @click="load"
      >
        {{ t('bo.health.refresh') }}
      </button>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-3">{{ error }}</BaseAlert>
    <div v-if="!report && loading" class="flex justify-center py-6"><BaseSpinner /></div>

    <ul v-if="report" class="divide-y divide-neutral-100">
      <li v-for="line in report.lines" :key="line.key" class="flex gap-3 py-2.5">
        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="dot[line.state]" :title="t(`bo.health.states.${line.state}`)" />
        <div class="min-w-0 flex-1">
          <p class="text-xs text-neutral-500">{{ t(`bo.health.lines.${line.key}`) }}</p>
          <p class="break-words text-sm text-neutral-800">{{ describe(line).main }}</p>
          <p v-if="describe(line).sub" class="break-words text-xs text-neutral-500">{{ describe(line).sub }}</p>
        </div>
      </li>
    </ul>

    <p v-if="report" class="mt-2 text-xs text-neutral-400">{{ t('bo.health.checkedAt', { at: formatDateTime(report.checked_at) }) }}</p>
  </section>
</template>
