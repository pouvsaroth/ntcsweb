<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

import type { ProvisioningStep, School } from '../api'

/**
 * The latest setup attempt, step by step. While the school is setting up
 * the parent polls, so a step flips from waiting to done in place.
 */
const props = defineProps<{ school: School; canRetry: boolean; retrying: boolean }>()
const emit = defineEmits<{ retry: [] }>()

const { t } = useI18n()


const rows = computed(() => {
  const log = props.school.provisioning_log ?? []
  let blocked = false

  // "copy" only for a school created from another school's setup.
  const order: ProvisioningStep['step'][] =
    props.school.copy_setup_from || log.some((l) => l.step === 'copy')
      ? ['database', 'seed', 'copy', 'health', 'activate', 'owner']
      : ['database', 'seed', 'health', 'activate', 'owner']

  return order.map((step) => {
    const entry = log.find((l) => l.step === step)
    let state: 'ok' | 'failed' | 'running' | 'waiting' = 'waiting'
    if (entry) state = entry.status
    else if (!blocked && props.school.status === 'provisioning') state = 'running'
    if (state !== 'ok') blocked = true
    return { step, state, entry }
  })
})
</script>

<template>
  <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
    <div class="mb-3 flex items-center justify-between gap-3">
      <h2 class="font-semibold text-neutral-900">{{ t('bo.schools.setup') }}</h2>
      <span v-if="school.provisioning_attempts > 1" class="text-xs text-neutral-500">
        {{ t('bo.schools.attempt', { n: school.provisioning_attempts }) }}
      </span>
    </div>

    <p v-if="school.status === 'provisioning'" class="mb-3 text-sm text-neutral-600">{{ t('bo.schools.setupRunning') }}</p>
    <div v-if="school.status === 'failed'" class="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
      <p class="font-medium">{{ t('bo.schools.setupFailed') }}</p>
      <p class="mt-1 font-mono text-xs break-all">{{ school.status_detail }}</p>
    </div>

    <ol class="space-y-2">
      <li v-for="row in rows" :key="row.step" class="flex items-center gap-3 text-sm">
        <span class="flex h-6 w-6 shrink-0 items-center justify-center">
          <BaseSpinner v-if="row.state === 'running'" class="h-4 w-4" />
          <svg v-else-if="row.state === 'ok'" class="h-5 w-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
          </svg>
          <svg v-else-if="row.state === 'failed'" class="h-5 w-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
          <span v-else class="h-2.5 w-2.5 rounded-full bg-neutral-300" />
        </span>
        <span :class="row.state === 'waiting' ? 'text-neutral-400' : 'text-neutral-800'">{{ t(`bo.schools.steps.${row.step}`) }}</span>
        <span v-if="row.entry" class="ml-auto text-xs text-neutral-400 tabular-nums">{{ (row.entry.duration_ms / 1000).toFixed(1) }} s</span>
      </li>
    </ol>

    <BaseButton v-if="school.status === 'failed' && canRetry" class="mt-4" :loading="retrying" @click="emit('retry')">
      {{ t('bo.schools.retry') }}
    </BaseButton>
  </section>
</template>
