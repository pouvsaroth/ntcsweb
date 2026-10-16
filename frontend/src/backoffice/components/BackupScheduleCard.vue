<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

import { dataApi, type BackupScheduleRow } from '../api'

/** The platform's one backup schedule: on/off, weekdays, time, keep-days; the second copy is shown, not set here. */
defineProps<{ canEdit: boolean }>()

const { t } = useI18n()
const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const

const schedule = ref<BackupScheduleRow | null>(null)
const form = ref({ enabled: true, days: [] as string[], at: '02:00', keep_days: '14' })
const saving = ref(false)
const saved = ref(false)
const error = ref<ApiRequestError | null>(null)

function take(s: BackupScheduleRow): void {
  schedule.value = s
  form.value = { enabled: s.enabled, days: [...s.days], at: s.at, keep_days: String(s.keep_days) }
}

function toggleDay(day: string, on: boolean): void {
  form.value.days = on ? [...form.value.days, day] : form.value.days.filter((d) => d !== day)
}

async function save(): Promise<void> {
  saving.value = true
  saved.value = false
  error.value = null
  try {
    take(await dataApi.saveSchedule({ ...form.value, keep_days: Number(form.value.keep_days) }))
    saved.value = true
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    saving.value = false
  }
}

onMounted(async () => take(await dataApi.schedule()))
</script>

<template>
  <section v-if="schedule" class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
    <h2 class="mb-1 font-semibold text-neutral-900">{{ t('bo.data.schedule') }}</h2>
    <p class="text-sm text-neutral-600">
      {{ schedule.next_run_at ? t('bo.data.nextRun', { when: formatDateTime(schedule.next_run_at) }) : t('bo.data.noNextRun') }}
    </p>
    <p class="mb-4 text-xs text-neutral-500">
      {{ schedule.copy_disk ? t('bo.data.secondCopy', { disk: schedule.copy_disk }) : t('bo.data.secondCopyOff') }}
    </p>

    <BaseAlert v-if="saved" variant="success" class="mb-3">{{ t('bo.common.saved') }}</BaseAlert>
    <BaseAlert v-if="error && !error.errors" variant="danger" class="mb-3">{{ error.message }}</BaseAlert>

    <form class="space-y-4" @submit.prevent="save">
      <fieldset :disabled="!canEdit" class="space-y-4">
        <label class="flex items-center gap-2 text-sm text-neutral-800">
          <input v-model="form.enabled" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('bo.data.scheduleOn') }}
        </label>

        <div>
          <p class="mb-2 text-sm font-medium text-neutral-700">{{ t('bo.data.days') }}</p>
          <div class="flex flex-wrap gap-2">
            <label
              v-for="day in DAYS"
              :key="day"
              class="flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-sm"
              :class="form.days.includes(day) ? 'border-primary-500 bg-primary-50 text-neutral-900' : 'border-neutral-200 text-neutral-500'"
            >
              <input
                type="checkbox"
                class="sr-only"
                :checked="form.days.includes(day)"
                @change="toggleDay(day, ($event.target as HTMLInputElement).checked)"
              />
              {{ t(`bo.data.dayNames.${day}`) }}
            </label>
          </div>
          <p v-if="error?.fieldError('days')" class="mt-1 text-xs text-red-600">{{ error.fieldError('days') }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.at" type="time" :label="t('bo.data.at', { tz: schedule.timezone })" :error="error?.fieldError('at')" required />
          <BaseInput
            v-model="form.keep_days"
            type="number"
            :label="t('bo.data.keepDays')"
            :hint="t('bo.data.keepHint')"
            :error="error?.fieldError('keep_days')"
            required
          />
        </div>
      </fieldset>
      <BaseButton v-if="canEdit" type="submit" :loading="saving">{{ t('bo.data.saveSchedule') }}</BaseButton>
    </form>
  </section>
</template>
