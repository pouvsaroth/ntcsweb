<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { publicContentService, type PublicCourse, type PublicVideo } from '@/services/publicContent'

const props = defineProps<{ course: PublicCourse }>()

const { t } = useI18n()
const expanded = ref(false)

interface FeeRow {
  labelKey: string
  amount: number
}

const currencySymbol: Record<PublicCourse['currency'], string> = {
  USD: '$',
  KHR: '៛',
}

// A handful of catalog entries carry a leftover sentinel like "-1" instead
// of a real duration label — showing that to a visitor reads as a bug, so
// treat anything that's just a non-positive number as "no duration set."
function hasDuration(course: PublicCourse): boolean {
  if (!course.duration) return false
  const numeric = Number(course.duration)
  return Number.isNaN(numeric) || numeric > 0
}

function feeRows(course: PublicCourse): FeeRow[] {
  const rows: FeeRow[] = []
  if (course.fee_monthly !== null) rows.push({ labelKey: 'programs.feeMonthly', amount: course.fee_monthly })
  if (course.fee_term !== null) rows.push({ labelKey: 'programs.feeTerm', amount: course.fee_term })
  if (course.fee_video !== null) rows.push({ labelKey: 'programs.feeVideo', amount: course.fee_video })
  if (course.fee_monthly_online !== null) rows.push({ labelKey: 'programs.feeMonthlyOnline', amount: course.fee_monthly_online })
  if (course.fee_term_online !== null) rows.push({ labelKey: 'programs.feeTermOnline', amount: course.fee_term_online })
  return rows
}

// This course's video menu, loaded lazily the first time the panel opens —
// most visitors never expand a card, so there's no reason to fetch it upfront.
const videos = ref<PublicVideo[] | null>(null)
const videosLoading = ref(false)
const playerVideo = ref<PublicVideo | null>(null)
const lockedPromptOpen = ref(false)

watch(expanded, async (isExpanded) => {
  if (!isExpanded || videos.value !== null || videosLoading.value) return

  videosLoading.value = true
  try {
    const match = await publicContentService.getVideosForCourse(props.course.id)
    videos.value = match?.videos ?? []
  } finally {
    videosLoading.value = false
  }
})

function selectVideo(video: PublicVideo) {
  if (video.is_locked) {
    lockedPromptOpen.value = true
  } else {
    playerVideo.value = video
  }
}
</script>

<template>
  <div class="flex flex-col rounded-[2rem] border border-primary-400 bg-white p-6 shadow-[--shadow-card] transition-shadow hover:shadow-[--shadow-card-hover]">
    <div class="flex items-center gap-4">
      <div class="h-16 w-16 shrink-0 overflow-hidden rounded-full bg-neutral-100">
        <img v-if="course.thumbnail_url" :src="course.thumbnail_url" alt="" class="h-full w-full object-cover" />
        <div v-else class="flex h-full w-full items-center justify-center text-neutral-300">
          <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.42A12.083 12.083 0 0112 21a12.083 12.083 0 01-6.16-10.42L12 14z"
            />
          </svg>
        </div>
      </div>
      <div class="min-w-0">
        <h3 class="truncate text-xl font-bold text-warning-600 sm:text-2xl">{{ course.name }}</h3>
        <p v-if="hasDuration(course)" class="mt-0.5 inline-flex items-center gap-1 text-xs text-neutral-500">
          <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          {{ course.duration }}
        </p>
      </div>
    </div>

    <p v-if="course.description" class="mt-3 text-sm text-neutral-500">{{ course.description }}</p>

    <!-- Fee: shown directly, no click required to see what this course costs. -->
    <div class="mt-4 divide-y divide-neutral-200 rounded-xl bg-neutral-50 px-4 py-1 text-sm">
      <template v-if="feeRows(course).length > 0">
        <div v-for="row in feeRows(course)" :key="row.labelKey" class="flex items-center justify-between py-2">
          <span class="text-neutral-500">{{ t(row.labelKey) }}</span>
          <span class="font-semibold text-neutral-900">{{ currencySymbol[course.currency] }}{{ row.amount.toFixed(2) }}</span>
        </div>
      </template>
      <p v-else class="py-2 text-neutral-400">{{ t('programs.noFeeInfo') }}</p>
    </div>

    <button
      type="button"
      class="mt-4 flex items-center gap-1.5 self-start text-sm font-medium text-secondary-600 hover:text-secondary-700"
      @click="expanded = !expanded"
    >
      <svg class="h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 24 24">
        <path d="M8 5v14l11-7z" />
      </svg>
      {{ t('programs.watchVideos') }}
      <svg
        class="h-4 w-4 shrink-0 transition-transform"
        :class="expanded ? 'rotate-180' : ''"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        stroke-width="2"
      >
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
      </svg>
    </button>

    <div v-if="expanded" class="mt-3 border-t border-neutral-100 pt-3 text-sm">
      <div v-if="videosLoading" class="text-xs text-neutral-400">…</div>
      <p v-else-if="!videos || videos.length === 0" class="text-xs text-neutral-400">{{ t('programs.noVideosYet') }}</p>
      <ul v-else class="divide-y divide-neutral-100 overflow-hidden rounded-lg border border-neutral-100">
        <li v-for="video in videos" :key="video.id">
          <button
            type="button"
            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-neutral-50"
            @click="selectVideo(video)"
          >
            <svg v-if="video.is_locked" class="h-3.5 w-3.5 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"
              />
            </svg>
            <svg v-else class="h-3.5 w-3.5 shrink-0 text-secondary-600" fill="currentColor" viewBox="0 0 24 24">
              <path d="M8 5v14l11-7z" />
            </svg>
            <span class="truncate" :class="video.is_locked ? 'text-neutral-400' : 'text-neutral-800'">{{ video.title }}</span>
          </button>
        </li>
      </ul>
    </div>

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
