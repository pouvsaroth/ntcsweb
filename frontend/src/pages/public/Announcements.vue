<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import EmptyState from '@/components/ui/EmptyState.vue'
import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'

const { t } = useI18n()
</script>

<template>
  <div>
    <PageHero :title="t('announcements.title')" :subtitle="t('announcements.subtitle')" />
    <SectionContainer>
      <EmptyState :title="t('announcements.emptyTitle')" :message="t('announcements.emptyMessage')" />
    </SectionContainer>
  </div>
</template>
