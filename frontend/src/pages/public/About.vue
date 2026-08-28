<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
const { t } = useI18n()
</script>

<template>
  <div>
    <PageHero :title="t('about.title')" :subtitle="t('about.subtitle')" />
    <SectionContainer>
      <div class="prose prose-neutral mx-auto max-w-3xl">
        <p class="text-neutral-600">{{ t('about.body', { name: site.info.name }) }}</p>
      </div>
    </SectionContainer>
  </div>
</template>
