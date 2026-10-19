<script setup lang="ts">
import { onMounted } from 'vue'

import ServicesHome from '@/components/public/ServicesHome.vue'
import { useSiteStore } from '@/stores/site'

import Home from './Home.vue'

/**
 * The website's `/`: the "group of services" page while it is switched on
 * (back office → Website → Services page), otherwise the school's own home
 * page as before — which also lives at /computer-school.
 */
const site = useSiteStore()

onMounted(() => site.load())
</script>

<template>
  <div v-if="!site.loaded" class="min-h-[60vh]" />
  <ServicesHome v-else-if="site.servicesOn" />
  <Home v-else />
</template>
