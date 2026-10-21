<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { timetableTabs } from '@/router/timetableTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * Academic > Timetable (docs/academics/timetable.md) — one sidebar link, each
 * section a tab. The timetable is the classes' own weekly times (class form);
 * here each time also gets its subject, teacher and room.
 */
const { t } = useI18n()
const auth = useAuthStore()

const tabs = computed(() => timetableTabs.filter((tab) => auth.can(tab.permission) || (tab.permission === 'timetable.request' && auth.can('timetable.manage'))))
</script>

<template>
  <div>
    <div class="mb-4 print:hidden">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('timetable.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('timetable.subtitle') }}</p>
    </div>

    <div class="print:hidden"><RouteTabBar :tabs="tabs" /></div>

    <RouterView />
  </div>
</template>

<style>
/* Printable timetable: only the .print-area is printed. */
@media print {
  body * {
    visibility: hidden;
  }
  .print-area,
  .print-area * {
    visibility: visible;
  }
  .print-area {
    position: absolute;
    inset: 0 auto auto 0;
    width: 100%;
  }
  @page {
    size: landscape;
    margin: 10mm;
  }
}
</style>
