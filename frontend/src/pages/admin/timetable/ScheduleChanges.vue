<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { timetableService, type ScheduleChange } from '@/services/timetable'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * Timetable > Schedule changes: requests to move a weekly time. A teacher asks
 * from their timetable (click a time); someone who manages the timetable
 * approves — the time moves — or rejects.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('timetable.manage'))

const rows = ref<ScheduleChange[]>([])
const status = ref<'' | ScheduleChange['status']>('pending')
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = await timetableService.changes(status.value)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('timetable.loadFailed')
  } finally {
    loading.value = false
  }
}
function setStatus(value: typeof status.value) {
  status.value = value
  void load()
}

const when = (x: { day_of_week: number; start_time: string; end_time: string }) => `${t(`timetable.days.d${x.day_of_week}`)} ${x.start_time}–${x.end_time}`
const variant = (s: ScheduleChange['status']) => (s === 'approved' ? 'success' : s === 'rejected' ? 'danger' : s === 'pending' ? 'warning' : 'neutral')

async function act(row: ScheduleChange, action: 'approve' | 'reject' | 'cancel') {
  if (action !== 'approve' && !(await confirmDialog.confirm({ message: t(`timetable.change.${action}Confirm`), danger: true }))) return
  error.value = null
  try {
    if (action === 'approve') {
      const r = await timetableService.approve(row.id)
      notice.value = r.conflicts.length ? t('timetable.change.approvedWithConflicts', { count: r.conflicts.length }) : t('timetable.change.approved')
    } else if (action === 'reject') {
      await timetableService.reject(row.id)
    } else {
      await timetableService.cancel(row.id)
    }
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.errors ? (Object.values(e.errors)[0]?.[0] ?? e.message) : e.message) : t('timetable.saveFailed')
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <button
        v-for="s in (['pending', 'approved', 'rejected', ''] as const)"
        :key="s"
        type="button"
        class="rounded-full border px-3 py-1 text-sm"
        :class="status === s ? 'border-primary-500 bg-primary-50 font-medium text-neutral-900' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'"
        @click="setStatus(s)"
      >
        {{ s ? t(`timetable.change.status.${s}`) : t('timetable.change.all') }}
      </button>
      <BaseSpinner v-if="loading" class="h-4 w-4" />
    </div>
    <p class="mb-3 text-xs text-neutral-500">{{ t('timetable.change.hint') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="success" class="mb-4">{{ notice }}</BaseAlert>
    <p v-if="!loading && rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('timetable.change.empty') }}</p>

    <div class="space-y-2">
      <div v-for="r in rows" :key="r.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="text-sm font-semibold text-neutral-800">{{ r.class }}</p>
            <p class="text-sm text-neutral-700">{{ when(r.from) }} → <span class="font-medium">{{ when(r.to) }}</span></p>
            <p v-if="r.new_room || r.new_teacher" class="text-xs text-neutral-500">{{ [r.new_room ? t('timetable.room') + ': ' + r.new_room : null, r.new_teacher ? t('timetable.teacher') + ': ' + r.new_teacher : null].filter(Boolean).join(' · ') }}</p>
            <p class="mt-1 text-sm text-neutral-600">“{{ r.reason }}”</p>
            <p class="text-xs text-neutral-400">{{ r.requested_by }} · {{ r.created_at }}<template v-if="r.decided_by"> · {{ t('timetable.change.decidedBy', { name: r.decided_by }) }}</template></p>
          </div>
          <BaseBadge :variant="variant(r.status)">{{ t(`timetable.change.status.${r.status}`) }}</BaseBadge>
        </div>
        <div v-if="r.status === 'pending'" class="mt-3 flex flex-wrap justify-end gap-2">
          <BaseButton v-if="r.mine" variant="ghost" size="sm" @click="act(r, 'cancel')">{{ t('timetable.change.cancel') }}</BaseButton>
          <template v-if="canManage">
            <BaseButton variant="outline" size="sm" @click="act(r, 'reject')">{{ t('timetable.change.reject') }}</BaseButton>
            <BaseButton size="sm" @click="act(r, 'approve')">{{ t('timetable.change.approve') }}</BaseButton>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
