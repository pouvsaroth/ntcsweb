<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { timetableService, type TimetableConflict } from '@/services/timetable'
import { ApiRequestError } from '@/types/api'

/**
 * Timetable > Conflicts: times that overlap — a teacher in two classes, a
 * room used twice, a class twice — and times in a teacher's weekly time off
 * teaching (HRM > Teacher > Availability).
 */
const { t } = useI18n()

const rows = ref<TimetableConflict[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const filter = ref<'' | TimetableConflict['type']>('')

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = await timetableService.conflicts()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('timetable.loadFailed')
  } finally {
    loading.value = false
  }
}
const shown = computed(() => rows.value.filter((r) => !filter.value || r.type === filter.value))
const count = (type: TimetableConflict['type']) => rows.value.filter((r) => r.type === type).length
const variant = (type: TimetableConflict['type']) => (type === 'teacher' || type === 'class' ? 'danger' : 'warning')

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <button
        v-for="type in (['', 'teacher', 'room', 'class', 'unavailable'] as const)"
        :key="type"
        type="button"
        class="rounded-full border px-3 py-1 text-sm"
        :class="filter === type ? 'border-primary-500 bg-primary-50 font-medium text-neutral-900' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'"
        @click="filter = type"
      >
        {{ type ? t(`timetable.conflictTypes.${type}`) : t('timetable.allConflicts') }} ({{ type ? count(type) : rows.length }})
      </button>
      <BaseSpinner v-if="loading" class="h-4 w-4" />
      <BaseButton class="ml-auto" variant="outline" @click="load">{{ t('timetable.recheck') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="!loading && rows.length === 0" variant="success" class="mb-4">{{ t('timetable.noConflicts') }}</BaseAlert>

    <div class="space-y-2">
      <div v-for="(c, i) in shown" :key="i" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
        <div class="flex flex-wrap items-center gap-2">
          <BaseBadge :variant="variant(c.type)">{{ t(`timetable.conflictTypes.${c.type}`) }}</BaseBadge>
          <span class="text-sm font-medium text-neutral-800">{{ t(`timetable.days.d${c.day_of_week}`) }}</span>
          <span v-if="c.who" class="text-sm text-neutral-600">· {{ c.who }}</span>
        </div>
        <ul class="mt-1.5 space-y-0.5 text-sm text-neutral-700">
          <li v-for="s in c.slots" :key="s.id">
            <RouterLink :to="{ path: '/admin/timetable/class', query: { class_id: s.class_id } }" class="text-primary-700 hover:underline">{{ s.class }}</RouterLink>
            {{ s.start_time }}–{{ s.end_time }}<template v-if="s.subject"> · {{ s.subject }}</template><template v-if="s.room"> · {{ s.room }}</template>
          </li>
        </ul>
        <p v-if="c.reason" class="mt-1 text-xs text-neutral-500">{{ c.reason }}</p>
      </div>
    </div>
  </div>
</template>
