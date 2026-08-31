<script setup lang="ts">
import { useI18n } from 'vue-i18n'

interface Props {
  size?: 'sm' | 'md' | 'lg'
}

withDefaults(defineProps<Props>(), { size: 'md' })

const { t } = useI18n()

const sizes = { sm: 'h-4 w-4', md: 'h-6 w-6', lg: 'h-10 w-10' } as const
</script>

<template>
  <svg
    class="animate-spin text-secondary-600"
    :class="sizes[size]"
    viewBox="0 0 24 24"
    fill="none"
    role="status"
    :aria-label="t('common.loading')"
  >
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
  </svg>
</template>
