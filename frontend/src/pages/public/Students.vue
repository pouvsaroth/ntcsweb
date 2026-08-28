<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import EmptyState from '@/components/ui/EmptyState.vue'
import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'

const { t } = useI18n()
</script>

<template>
  <div>
    <PageHero :title="t('students.title')" :subtitle="t('students.subtitle')" />
    <SectionContainer>
      <EmptyState :title="t('students.emptyTitle')" :message="t('students.emptyMessage')" />
    </SectionContainer>
  </div>
</template>
