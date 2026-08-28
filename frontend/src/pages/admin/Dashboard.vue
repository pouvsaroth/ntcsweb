<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseCard from '@/components/ui/BaseCard.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const { t } = useI18n()

const stats = computed(() => [
  { label: t('admin.dashboard.statStudents'), value: '—', hint: t('admin.dashboard.statHintStudents') },
  { label: t('admin.dashboard.statTeachers'), value: '—', hint: t('admin.dashboard.statHintTeachers') },
  { label: t('admin.dashboard.statUsers'), value: '—', hint: t('admin.dashboard.statHintUsers') },
  { label: t('admin.dashboard.statPrograms'), value: '—', hint: t('admin.dashboard.statHintPrograms') },
])
</script>

<template>
  <div>
    <h1 class="text-xl font-semibold text-neutral-900">
      {{ t('admin.dashboard.welcomeBack', { name: auth.user?.name ?? '' }) }}
    </h1>
    <p class="mt-1 text-sm text-neutral-500">
      {{ auth.isSuperAdmin ? t('admin.dashboard.platformAdministration') : auth.tenantName }}
    </p>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <BaseCard v-for="stat in stats" :key="stat.label">
        <p class="text-sm font-medium text-neutral-500">{{ stat.label }}</p>
        <p class="mt-1 text-3xl font-bold text-neutral-900">{{ stat.value }}</p>
        <p class="mt-1 text-xs text-neutral-400">{{ stat.hint }}</p>
      </BaseCard>
    </div>
  </div>
</template>
