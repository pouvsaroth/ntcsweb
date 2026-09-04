<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type PublicVideo, type PublicVideoCourse } from '@/services/publicContent'

const { t } = useI18n()

const courses = ref<PublicVideoCourse[]>([])
const loading = ref(true)

const openMenuCourseId = ref<number | null>(null)
const playerVideo = ref<PublicVideo | null>(null)
const lockedPromptOpen = ref(false)

const sliders = new Map<number, HTMLElement>()

function setSliderRef(courseId: number) {
  return (el: unknown) => {
    if (el instanceof HTMLElement) sliders.set(courseId, el)
  }
}

function scrollSlider(courseId: number, direction: 1 | -1) {
  sliders.get(courseId)?.scrollBy({ left: direction * 600, behavior: 'smooth' })
}

function toggleMenu(courseId: number) {
  openMenuCourseId.value = openMenuCourseId.value === courseId ? null : courseId
}

function selectVideo(video: PublicVideo) {
  openMenuCourseId.value = null

  if (video.is_locked) {
    lockedPromptOpen.value = true
  } else {
    playerVideo.value = video
  }
}

function onDocumentClick(event: MouseEvent) {
  if (!(event.target as Element).closest('[data-video-course-menu]')) {
    openMenuCourseId.value = null
  }
}

onMounted(async () => {
  document.addEventListener('click', onDocumentClick)

  try {
    courses.value = await publicContentService.getVideoLessons()
  } finally {
    loading.value = false
  }
})

onUnmounted(() => document.removeEventListener('click', onDocumentClick))
</script>

<template>
  <div>
    <PageHero :title="t('videoLessons.title')" :subtitle="t('videoLessons.subtitle')" />
    <SectionContainer>
      <div v-if="loading" class="space-y-4">
        <div v-for="i in 2" :key="i" class="h-64 animate-pulse rounded-[--radius-card] bg-neutral-100" />
      </div>
      <EmptyState v-else-if="courses.length === 0" :title="t('videoLessons.emptyTitle')" :message="t('videoLessons.emptyMessage')" />

      <div v-else class="space-y-12">
        <div v-for="course in courses" :key="course.id">
          <div class="mb-4 flex items-center justify-between">
            <h2 class="text-xl font-bold text-neutral-900">{{ course.name }}</h2>

            <div data-video-course-menu class="relative">
              <button
                type="button"
                class="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100"
                :aria-label="t('videoLessons.videoList')"
                @click="toggleMenu(course.id)"
              >
                <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                  <path d="M10 6a2 2 0 100-4 2 2 0 000 4zM10 12a2 2 0 100-4 2 2 0 000 4zM10 18a2 2 0 100-4 2 2 0 000 4z" />
                </svg>
              </button>

              <div
                v-if="openMenuCourseId === course.id"
                class="absolute right-0 z-20 mt-1 max-h-80 w-72 overflow-y-auto rounded-lg border border-neutral-200 bg-white py-1 shadow-lg"
              >
                <button
                  v-for="video in course.videos"
                  :key="video.id"
                  type="button"
                  class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-neutral-50"
                  @click="selectVideo(video)"
                >
                  <svg v-if="video.is_locked" class="h-3.5 w-3.5 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                  </svg>
                  <span class="truncate" :class="video.is_locked ? 'text-neutral-400' : 'text-neutral-800'">{{ video.title }}</span>
                </button>
              </div>
            </div>
          </div>

          <div class="relative">
            <button
              type="button"
              class="absolute -left-3 top-1/2 z-10 hidden -translate-y-1/2 rounded-full bg-white p-1.5 shadow-md hover:bg-neutral-50 sm:flex"
              :aria-label="t('videoLessons.previous')"
              @click="scrollSlider(course.id, -1)"
            >
              <svg class="h-5 w-5 text-neutral-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
              </svg>
            </button>

            <div :ref="setSliderRef(course.id)" class="flex gap-4 overflow-x-auto scroll-smooth pb-2" style="scroll-snap-type: x mandatory">
              <button
                v-for="video in course.videos"
                :key="video.id"
                type="button"
                class="w-60 shrink-0 text-left"
                style="scroll-snap-align: start"
                @click="selectVideo(video)"
              >
                <div class="group relative aspect-video overflow-hidden rounded-[--radius-card] bg-neutral-100">
                  <img v-if="video.thumbnail_url" :src="video.thumbnail_url" alt="" class="h-full w-full object-cover" />

                  <div v-if="video.is_locked" class="absolute inset-0 flex items-center justify-center bg-black/50">
                    <svg class="h-8 w-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                  </div>
                  <div v-else class="absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover:bg-black/30">
                    <svg class="h-10 w-10 text-white opacity-0 transition-opacity group-hover:opacity-100" fill="currentColor" viewBox="0 0 24 24">
                      <path d="M8 5v14l11-7z" />
                    </svg>
                  </div>
                </div>
                <p class="mt-2 line-clamp-2 text-sm font-medium text-neutral-800">{{ video.title }}</p>
              </button>
            </div>

            <button
              type="button"
              class="absolute -right-3 top-1/2 z-10 hidden -translate-y-1/2 rounded-full bg-white p-1.5 shadow-md hover:bg-neutral-50 sm:flex"
              :aria-label="t('videoLessons.next')"
              @click="scrollSlider(course.id, 1)"
            >
              <svg class="h-5 w-5 text-neutral-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </SectionContainer>

    <BaseModal :model-value="playerVideo !== null" size="lg" :title="playerVideo?.title" @update:model-value="playerVideo = null">
      <template v-if="playerVideo">
        <div class="aspect-video w-full overflow-hidden rounded-lg bg-black">
          <iframe
            v-if="playerVideo.embed_url"
            :src="playerVideo.embed_url"
            class="h-full w-full"
            title="YouTube video player"
            frameborder="0"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowfullscreen
          />
        </div>
        <p v-if="playerVideo.description" class="mt-3 text-sm text-neutral-600">{{ playerVideo.description }}</p>
      </template>
    </BaseModal>

    <BaseModal v-model="lockedPromptOpen" size="sm" :title="t('videoLessons.lockedTitle')">
      <p class="text-sm text-neutral-600">{{ t('videoLessons.lockedMessage') }}</p>

      <template #footer>
        <BaseButton variant="outline" @click="lockedPromptOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton to="/register">{{ t('nav.register') }}</BaseButton>
        <BaseButton href="/login" target="_blank">{{ t('nav.portalLogin') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
