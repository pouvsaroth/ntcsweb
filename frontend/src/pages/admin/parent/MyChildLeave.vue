<script setup lang="ts">
/** Parent portal: the picked child's leave requests, and asking leave for them (normal approval). */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AskForPermissionModal from '@/components/layout/AskForPermissionModal.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { myLeaveRequestsService, type LeaveRequest } from '@/services/leaveRequests'
import { formatDate } from '@/utils/date'

const { t } = useI18n()
const rows = ref<LeaveRequest[]>([])
const loading = ref(true)
const failed = ref(false)
const asking = ref(false)

async function load(): Promise<void> {
  loading.value = true
  try {
    rows.value = (await myLeaveRequestsService.list({ page: 1, per_page: 50, sort: '-created_at' })).data
    failed.value = false
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}
onMounted(load)

function onModal(open: boolean): void {
  asking.value = open
  if (!open) void load()
}

const variant = (s: string) => (s === 'approved' ? 'success' : s === 'rejected' ? 'danger' : s === 'pending' ? 'warning' : 'neutral')
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold text-neutral-900">{{ t('parentPortal.leave') }}</h1>
      <BaseButton @click="asking = true">{{ t('parentPortal.askLeave') }}</BaseButton>
    </div>
    <div v-if="loading" class="flex justify-center py-12"><BaseSpinner /></div>
    <p v-else-if="failed" class="text-sm text-red-600">{{ t('parentPortal.failed') }}</p>
    <p v-else-if="!rows.length" class="rounded-[--radius-card] border border-dashed border-neutral-300 p-6 text-center text-sm text-neutral-500">{{ t('parentPortal.noLeave') }}</p>
    <div v-else class="space-y-3">
      <div v-for="r in rows" :key="r.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <p class="font-medium text-neutral-900">{{ formatDate(r.from_date) }}<template v-if="r.to_date !== r.from_date"> → {{ formatDate(r.to_date) }}</template></p>
          <BaseBadge :variant="variant(r.status)">{{ t(`parentPortal.leaveStatus.${r.status}`) }}</BaseBadge>
        </div>
        <p v-if="r.school_class || r.course_package" class="text-xs text-neutral-500">{{ r.school_class?.name ?? r.course_package?.name }}</p>
        <p class="mt-1 text-sm text-neutral-700">{{ r.reason }}</p>
      </div>
    </div>
    <AskForPermissionModal :model-value="asking" @update:model-value="onModal" />
  </div>
</template>
