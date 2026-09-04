<script setup lang="ts">
import { useAutoReloadOnNewVersion } from '@/composables/useAutoReloadOnNewVersion'

useAutoReloadOnNewVersion()
</script>

<template>
  <RouterView />
</template>
