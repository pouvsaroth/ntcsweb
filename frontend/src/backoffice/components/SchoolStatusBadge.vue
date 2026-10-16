<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import BaseBadge from '@/components/ui/BaseBadge.vue'

import type { SchoolStatus } from '../api'

defineProps<{ status: SchoolStatus }>()

const { t } = useI18n()

const variants: Record<SchoolStatus, 'neutral' | 'primary' | 'success' | 'warning' | 'danger'> = {
  provisioning: 'primary',
  failed: 'danger',
  active: 'success',
  suspended: 'warning',
  archived: 'neutral',
}
</script>

<template>
  <BaseBadge :variant="variants[status]">{{ t(`bo.schools.statuses.${status}`) }}</BaseBadge>
</template>
