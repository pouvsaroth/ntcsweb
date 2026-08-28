<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'
import { publicContentService, type HomeSlide } from '@/services/publicContent'
import { useSiteStore } from '@/stores/site'

const AUTO_ADVANCE_MS = 6000

const site = useSiteStore()
const { t } = useI18n()

const slides = ref<HomeSlide[]>([])
const loading = ref(true)
const current = ref(0)
let timer: ReturnType<typeof setInterval> | undefined

onMounted(async () => {
  const result = await publicContentService.getHomeSlides()
  slides.value = result.data
  loading.value = false
  startAutoAdvance()
})

onUnmounted(() => stopAutoAdvance())

function startAutoAdvance() {
  stopAutoAdvance()
  // A single slide (or none) has nothing to advance to.
  if (slides.value.length > 1) {
    timer = setInterval(next, AUTO_ADVANCE_MS)
  }
}

function stopAutoAdvance() {
  if (timer) clearInterval(timer)
}

function next() {
  current.value = (current.value + 1) % slides.value.length
}

function previous() {
  current.value = (current.value - 1 + slides.value.length) % slides.value.length
}

function goTo(index: number) {
  current.value = index
  startAutoAdvance() // manual navigation resets the countdown, not just moves the slide
}

// Respect a viewer who has asked their system to minimise motion — restart
// isn't skipped, since arriving at the page late shouldn't opt them into an
// otherwise-static slider forever, only the *timed auto-play* is skipped.
const prefersReducedMotion = computed(
  () => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches,
)

watch(loading, (isLoading) => {
  if (!isLoading && prefersReducedMotion.value) stopAutoAdvance()
})
</script>

<template>
  <section
    v-if="loading || slides.length > 0"
    class="relative isolate aspect-[4/3] w-full overflow-hidden bg-neutral-900 sm:aspect-[16/9] lg:aspect-[21/9]"
    @mouseenter="stopAutoAdvance"
    @mouseleave="startAutoAdvance"
  >
    <div v-if="loading" class="absolute inset-0 animate-pulse bg-neutral-800" />

    <!--
      Transition (singular), not TransitionGroup: the slides array itself
      never changes, only `current` does, so there's nothing for
      TransitionGroup's list-diffing to react to. Keying this single wrapper
      by the current slide's id is what makes Vue treat each slide change as
      an element swap worth animating — default (non-"out-in") mode overlaps
      the old element's leave with the new one's enter, which is the actual
      crossfade; "out-in" would show a black gap between slides instead.
    -->
    <Transition v-else name="fade">
      <div :key="slides[current]!.id" class="absolute inset-0">
        <img :src="slides[current]!.image_url" :alt="slides[current]!.title ?? ''" class="h-full w-full object-cover" />
        <div class="absolute inset-0 bg-gradient-to-t from-neutral-900/80 via-neutral-900/10 to-transparent" />

        <div
          v-if="slides[current]!.title || slides[current]!.subtitle"
          class="absolute inset-x-0 bottom-0 px-6 pb-10 sm:px-12 sm:pb-16 lg:px-20"
        >
          <div class="mx-auto max-w-3xl text-white">
            <h2 v-if="slides[current]!.title" class="text-2xl font-bold tracking-tight sm:text-4xl">
              {{ slides[current]!.title }}
            </h2>
            <p v-if="slides[current]!.subtitle" class="mt-2 max-w-xl text-sm text-white/90 sm:text-lg">
              {{ slides[current]!.subtitle }}
            </p>
            <BaseButton v-if="slides[current]!.link_url" :href="slides[current]!.link_url!" variant="secondary" class="mt-5">
              {{ t('home.learnMore') }}
            </BaseButton>
          </div>
        </div>
      </div>
    </Transition>

    <template v-if="!loading && slides.length > 1">
      <button
        type="button"
        class="absolute left-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/20 text-white backdrop-blur transition-colors hover:bg-white/30 sm:left-6"
        :aria-label="t('common.previousPage')"
        @click="previous"
      >
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
      </button>
      <button
        type="button"
        class="absolute right-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/20 text-white backdrop-blur transition-colors hover:bg-white/30 sm:right-6"
        :aria-label="t('common.nextPage')"
        @click="next"
      >
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
        </svg>
      </button>

      <div class="absolute inset-x-0 bottom-4 flex justify-center gap-2">
        <button
          v-for="(slide, index) in slides"
          :key="slide.id"
          type="button"
          class="h-2 rounded-full transition-all"
          :class="index === current ? 'w-6 bg-white' : 'w-2 bg-white/50 hover:bg-white/75'"
          :aria-label="`${t('common.pagination')} ${index + 1}`"
          :aria-current="index === current"
          @click="goTo(index)"
        />
      </div>
    </template>
  </section>

  <!-- No slides uploaded yet — the original gradient hero, so the homepage
       never looks broken for a school that hasn't added images. -->
  <section v-else class="bg-gradient-to-br from-primary-700 to-primary-900 text-white">
    <div class="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-2 lg:px-8 lg:py-32">
      <div class="flex flex-col justify-center">
        <h1 class="text-3xl font-bold tracking-tight sm:text-5xl">
          {{ t('home.heroTitle', { name: site.info.name }) }}
        </h1>
        <p class="mt-4 max-w-lg text-lg text-primary-100">{{ t('home.heroSubtitle') }}</p>
        <div class="mt-8 flex flex-wrap gap-3">
          <BaseButton href="/programs" variant="secondary" size="lg">{{ t('home.exploreProgramsCta') }}</BaseButton>
          <BaseButton href="/contact" variant="outline" size="lg" class="!border-white/40 !text-white hover:!bg-white/10">
            {{ t('home.contactUsCta') }}
          </BaseButton>
        </div>
      </div>
      <div class="hidden items-center justify-center lg:flex">
        <div class="aspect-video w-full rounded-2xl bg-white/10 ring-1 ring-white/20" />
      </div>
    </div>
  </section>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.7s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
