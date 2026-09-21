<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { myMonthlyPaymentAlertsService, type MyMonthlyPaymentAlert } from '@/services/myMonthlyPaymentAlerts'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/utils/date'

/**
 * The student-facing "your monthly payment is coming due" popup, mounted
 * from AdminLayout.vue (a student's self-service pages all live under
 * /admin/my-* now — see docs/multi-tenancy.md's ERP domain section) — shown
 * once per day per browser (see DISMISSED_KEY) rather than on every page
 * load, so a student who's already seen it today isn't nagged on every
 * navigation. Backed by MyMonthlyPaymentAlertController, which already
 * scopes strictly to the signed-in student's own enrollments.
 */
const auth = useAuthStore()
const { t } = useI18n()

const DISMISSED_KEY = 'ntcsweb.monthly_payment_alert_dismissed_on'

const open = ref(false)
const alerts = ref<MyMonthlyPaymentAlert[]>([])

function todayKey(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

function alreadyDismissedToday(): boolean {
  try {
    return sessionStorage.getItem(DISMISSED_KEY) === todayKey()
  } catch {
    // Private browsing / storage blocked — never dismissed, so the popup can still show.
    return false
  }
}

function dismiss(): void {
  open.value = false
  try {
    sessionStorage.setItem(DISMISSED_KEY, todayKey())
  } catch {
    // Nothing to persist if storage is unavailable — the popup will just show again next time.
  }
}

watch(
  () => auth.initialized,
  async (ready) => {
    if (!ready || !auth.isAuthenticated || !auth.hasRole('student') || alreadyDismissedToday()) return

    try {
      alerts.value = await myMonthlyPaymentAlertsService.list()
      if (alerts.value.length > 0) open.value = true
    } catch {
      // Silently skip — a failed check just means no popup this visit.
    }
  },
  { immediate: true },
)
</script>

<template>
  <BaseModal v-model="open" :title="t('monthlyPaymentAlert.title')">
    <p class="mb-4 text-sm text-neutral-600">{{ t('monthlyPaymentAlert.intro') }}</p>
    <ul class="space-y-2">
      <li v-for="(alert, index) in alerts" :key="index" class="rounded-lg border border-neutral-200 p-3 text-sm">
        <p class="font-medium text-neutral-900">{{ alert.course ?? t('monthlyPaymentAlert.unknownCourse') }}</p>
        <p class="text-neutral-500">{{ t('monthlyPaymentAlert.dueOn', { date: formatDate(alert.next_payment_date) }) }}</p>
      </li>
    </ul>

    <template #footer>
      <BaseButton @click="dismiss">{{ t('monthlyPaymentAlert.dismiss') }}</BaseButton>
    </template>
  </BaseModal>
</template>
