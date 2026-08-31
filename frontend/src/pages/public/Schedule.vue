<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseCard from '@/components/ui/BaseCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type ScheduledClass } from '@/services/publicContent'

const { t } = useI18n()
const classes = ref<ScheduledClass[]>([])
const loading = ref(true)

const dayLabel: Record<number, string> = {
  1: 'schedule.monday',
  2: 'schedule.tuesday',
  3: 'schedule.wednesday',
  4: 'schedule.thursday',
  5: 'schedule.friday',
  6: 'schedule.saturday',
  7: 'schedule.sunday',
}

onMounted(async () => {
  const result = await publicContentService.getSchedules()
  classes.value = result.data
  loading.value = false
})

/** "18:00:00" -> "18:00" — the API sends a raw SQL TIME string. */
function formatTime(value: string): string {
  return value.slice(0, 5)
}
</script>

<template>
  <div>
    <SectionContainer>
      <div v-if="loading" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="i in 6" :key="i" class="h-40 animate-pulse rounded-[--radius-card] bg-neutral-100" />
      </div>
      <EmptyState v-else-if="classes.length === 0" :title="t('schedule.emptyTitle')" :message="t('schedule.emptyMessage')" />
      <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <BaseCard v-for="schoolClass in classes" :key="schoolClass.id">
          <h3 class="font-semibold text-neutral-900">{{ schoolClass.name }}</h3>
          <p v-if="schoolClass.teacher_name" class="mb-3 text-sm text-neutral-500">
            {{ t('schedule.teacher', { name: schoolClass.teacher_name }) }}
          </p>

          <ul class="space-y-1.5">
            <li
              v-for="(slot, index) in schoolClass.schedules"
              :key="index"
              class="flex items-center justify-between rounded-lg bg-neutral-50 px-3 py-1.5 text-sm"
            >
              <span class="font-medium text-neutral-700">{{ t(dayLabel[slot.day_of_week]) }}</span>
              <span class="text-neutral-500">{{ formatTime(slot.start_time) }} – {{ formatTime(slot.end_time) }}</span>
            </li>
          </ul>
        </BaseCard>
      </div>
    </SectionContainer>
  </div>
</template>
