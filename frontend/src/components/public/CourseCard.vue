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

function feeRows(course: PublicCourse): FeeRow[] {
  const rows: FeeRow[] = []
  if (course.fee_monthly !== null) rows.push({ labelKey: 'programs.feeMonthly', amount: course.fee_monthly })
  if (course.fee_term !== null) rows.push({ labelKey: 'programs.feeTerm', amount: course.fee_term })
  if (course.fee_video !== null) rows.push({ labelKey: 'programs.feeVideo', amount: course.fee_video })
  if (course.fee_monthly_online !== null) rows.push({ labelKey: 'programs.feeMonthlyOnline', amount: course.fee_monthly_online })
  if (course.fee_term_online !== null) rows.push({ labelKey: 'programs.feeTermOnline', amount: course.fee_term_online })
  return rows
}

const noteKeys = ['programs.noteMonthly', 'programs.noteTerm', 'programs.noteVideo']

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
  <div class="relative rounded-[2rem] border border-primary-400 bg-white p-6 shadow-[--shadow-card] transition-shadow hover:shadow-[--shadow-card-hover]">
    <button
      type="button"
      class="absolute right-6 top-6 flex shrink-0 items-center gap-1 text-sm font-medium text-secondary-600 hover:text-secondary-700"
      @click="expanded = !expanded"
    >
      {{ t('programs.detailAndFee') }}
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

    <div class="flex items-center gap-4 pr-24">
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
      <h3 class="text-xl font-bold text-warning-600 sm:text-2xl">{{ course.name }}</h3>
    </div>

    <p class="mt-4 text-sm text-neutral-500">{{ course.description || t('programs.noDescription') }}</p>

    <div v-if="expanded" class="mt-4 space-y-5 border-t border-neutral-100 pt-4 text-sm">
      <div>
        <p v-if="feeRows(course).length > 0" class="mb-2 font-medium text-neutral-700">{{ t('programs.detailIntro') }}</p>
        <template v-if="feeRows(course).length > 0">
          <div v-for="row in feeRows(course)" :key="row.labelKey" class="flex items-center justify-between py-0.5">
            <span class="text-neutral-500">{{ t(row.labelKey) }}</span>
            <span class="font-medium text-neutral-800">{{ currencySymbol[course.currency] }}{{ row.amount.toFixed(2) }}</span>
          </div>
        </template>
        <p v-else class="text-neutral-400">{{ t('programs.noFeeInfo') }}</p>

        <p v-if="course.duration" class="mt-2 inline-flex items-center gap-1 text-xs text-neutral-500">
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          {{ course.duration }}
        </p>
      </div>

      <div v-if="feeRows(course).length > 0">
        <p class="mb-1.5 font-medium text-neutral-700">{{ t('programs.notesTitle') }}</p>
        <ul class="list-disc space-y-1.5 pl-4 text-xs leading-relaxed text-neutral-500">
          <li v-for="key in noteKeys" :key="key">{{ t(key) }}</li>
        </ul>
      </div>

      <div v-if="videosLoading" class="text-xs text-neutral-400">…</div>
      <div v-else-if="videos && videos.length > 0">
        <p class="mb-1.5 font-medium text-neutral-700">{{ t('videoLessons.videoList') }}</p>
        <ul class="divide-y divide-neutral-100 overflow-hidden rounded-lg border border-neutral-100">
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
