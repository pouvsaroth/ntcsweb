<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import ExaminationTabs from '@/components/admin/ExaminationTabs.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const title = t('adminNav.items.grades')
</script>

<template>
  <div>
    <ExaminationTabs />

    <h1 class="mb-6 text-xl font-semibold text-neutral-900">{{ title }}</h1>
    <EmptyState :title="t('admin.comingSoon.notBuiltYet', { title })" :message="t('admin.comingSoon.message')" />
  </div>
</template>
