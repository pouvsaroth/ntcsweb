<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import EmptyState from '@/components/ui/EmptyState.vue'

const route = useRoute()
const { t } = useI18n()

const titleKey = computed(() => String(route.meta.titleKey ?? 'adminNav.items.dashboard'))
const title = computed(() => t(titleKey.value))
</script>

<template>
  <div>
    <h1 class="mb-6 text-xl font-semibold text-neutral-900">{{ title }}</h1>
    <EmptyState :title="t('admin.comingSoon.notBuiltYet', { title })" :message="t('admin.comingSoon.message')" />
  </div>
</template>
