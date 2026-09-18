<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { myVideosService, type MyVideo, type MyVideoCourse } from '@/services/myVideos'
import { ApiRequestError } from '@/types/api'

/**
 * A student's own video library — two steps, not one flat list: pick a
 * course you're studying, then pick a video from it. Every course/video
 * here is already unlocked (see MyVideoController) since it's scoped to
 * this student's own active enrollments — no lock icons like the public
 * Video Lesson page (VideoLessons.vue) needs for a visitor who may not be
 * enrolled.
 */
const { t } = useI18n()

const courses = ref<MyVideoCourse[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const selectedCourse = ref<MyVideoCourse | null>(null)
const playerVideo = ref<MyVideo | null>(null)

onMounted(async () => {
  try {
    courses.value = await myVideosService.list()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.myVideos.loadFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.myVideos.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.myVideos.subtitle') }}</p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <EmptyState
      v-else-if="courses.length === 0"
      :title="t('admin.myVideos.emptyTitle')"
      :message="t('admin.myVideos.emptyMessage')"
    />

    <!-- Step 1: studying courses -->
    <div v-else-if="!selectedCourse" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <button
        v-for="course in courses"
        :key="course.id"
        type="button"
        class="flex flex-col items-center gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-5 text-center transition-colors hover:border-primary-300 hover:bg-primary-50"
        @click="selectedCourse = course"
      >
        <div class="flex h-24 w-full items-center justify-center overflow-hidden rounded-lg bg-neutral-100">
          <img v-if="course.thumbnail_url" :src="course.thumbnail_url" alt="" class="h-full w-full object-cover" />
          <svg v-else class="h-10 w-10 text-neutral-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
          </svg>
        </div>
        <div>
          <p class="font-medium text-neutral-900">{{ course.name }}</p>
          <p class="mt-0.5 text-xs text-neutral-500">{{ t('admin.myVideos.videoCount', { count: course.video_count }) }}</p>
        </div>
      </button>
    </div>

    <!-- Step 2: the selected course's videos -->
    <div v-else>
      <button
        type="button"
        class="mb-4 flex items-center gap-1.5 text-sm font-medium text-primary-700 hover:underline"
        @click="selectedCourse = null"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
        </svg>
        {{ t('admin.myVideos.backToCourses') }}
      </button>

      <h2 class="mb-4 text-lg font-semibold text-neutral-900">{{ selectedCourse.name }}</h2>

      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <button
          v-for="video in selectedCourse.videos"
          :key="video.id"
          type="button"
          class="text-left"
          @click="playerVideo = video"
        >
          <div class="group relative aspect-video overflow-hidden rounded-[--radius-card] bg-neutral-100">
            <img v-if="video.thumbnail_url" :src="video.thumbnail_url" alt="" class="h-full w-full object-cover" />
            <div class="absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover:bg-black/30">
              <svg class="h-10 w-10 text-white opacity-0 transition-opacity group-hover:opacity-100" fill="currentColor" viewBox="0 0 24 24">
                <path d="M8 5v14l11-7z" />
              </svg>
            </div>
          </div>
          <p class="mt-2 line-clamp-2 text-sm font-medium text-neutral-800">{{ video.title }}</p>
        </button>
      </div>
    </div>

    <BaseModal :model-value="playerVideo !== null" size="lg" :title="playerVideo?.title" @update:model-value="playerVideo = null">
      <template v-if="playerVideo">
        <div class="aspect-video w-full overflow-hidden rounded-lg bg-black">
          <iframe
            v-if="playerVideo.embed_url"
            :src="playerVideo.embed_url"
            class="h-full w-full"
            title="Video player"
            frameborder="0"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowfullscreen
          />
        </div>
        <p v-if="playerVideo.description" class="mt-3 text-sm text-neutral-600">{{ playerVideo.description }}</p>
      </template>
    </BaseModal>
  </div>
</template>
