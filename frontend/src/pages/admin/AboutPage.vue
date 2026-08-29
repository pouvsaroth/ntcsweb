<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { aboutPageService, type AboutPageInput } from '@/services/aboutPage'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

function emptyStats() {
  return Array.from({ length: 4 }, () => ({ value: '', label: '' }))
}

function emptyPillars() {
  return Array.from({ length: 3 }, () => ({ icon: '', title: '', description: '' }))
}

function emptyAchievements() {
  return Array.from({ length: 4 }, () => ({ icon: '', value: '', label: '' }))
}

const form = reactive<Omit<AboutPageInput, 'history_image'>>({
  history_title: '',
  history_paragraph_1: '',
  history_paragraph_2: '',
  stats: emptyStats(),
  pillars: emptyPillars(),
  achievements_title: '',
  achievements: emptyAchievements(),
})

const historyImageFile = ref<File | null>(null)
const historyImagePreview = ref<string | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const saved = ref(false)
const saving = ref(false)

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const about = await aboutPageService.get()
    form.history_title = about.history_title
    form.history_paragraph_1 = about.history_paragraph_1
    form.history_paragraph_2 = about.history_paragraph_2
    form.stats = about.stats.length === 4 ? about.stats : emptyStats()
    form.pillars = about.pillars.length === 3 ? about.pillars : emptyPillars()
    form.achievements_title = about.achievements_title
    form.achievements = about.achievements.length === 4 ? about.achievements : emptyAchievements()
    historyImagePreview.value = about.history_image_url
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.aboutPage.loadFailed')
  } finally {
    loading.value = false
  }
}

function onFileChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  historyImageFile.value = file
  historyImagePreview.value = URL.createObjectURL(file)
}

async function save() {
  saving.value = true
  saveError.value = null
  saved.value = false

  try {
    await aboutPageService.save({ ...form, history_image: historyImageFile.value ?? undefined })
    historyImageFile.value = null
    saved.value = true
  } catch (error) {
    saveError.value = error instanceof ApiRequestError ? error.message : t('admin.aboutPage.saveFailed')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-4xl">
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.aboutPage.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.aboutPage.pageSubtitle') }}</p>
    </div>

    <BaseAlert v-if="loadError" variant="danger" class="mb-4">{{ loadError }}</BaseAlert>

    <form v-else-if="!loading" class="space-y-10" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <BaseAlert v-if="saved" variant="success">{{ t('admin.aboutPage.saveSuccess') }}</BaseAlert>

      <!-- Stats -->
      <section>
        <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.aboutPage.statsSection') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('admin.aboutPage.statsHint') }}</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div v-for="(stat, index) in form.stats" :key="index" class="flex gap-3 rounded-lg border border-neutral-200 p-3">
            <BaseInput v-model="stat.value" :placeholder="t('admin.aboutPage.statValuePlaceholder')" class="w-24 shrink-0" />
            <BaseInput v-model="stat.label" :placeholder="t('admin.aboutPage.statLabelPlaceholder')" class="flex-1" />
          </div>
        </div>
      </section>

      <!-- History -->
      <section>
        <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.aboutPage.historySection') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('admin.aboutPage.historyHint') }}</p>

        <div class="space-y-4">
          <BaseInput v-model="form.history_title" :label="t('admin.aboutPage.historyTitle')" />

          <div>
            <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.aboutPage.historyParagraph1') }}</label>
            <textarea
              v-model="form.history_paragraph_1"
              rows="3"
              class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
            />
          </div>

          <div>
            <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.aboutPage.historyParagraph2') }}</label>
            <textarea
              v-model="form.history_paragraph_2"
              rows="3"
              class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
            />
          </div>

          <div>
            <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.aboutPage.historyImage') }}</label>
            <div
              v-if="historyImagePreview"
              class="mb-3 aspect-video w-full max-w-md overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100"
            >
              <img :src="historyImagePreview" alt="" class="h-full w-full object-cover" />
            </div>
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp,image/gif"
              class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
              @change="onFileChange"
            />
          </div>
        </div>
      </section>

      <!-- Pillars -->
      <section>
        <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.aboutPage.pillarsSection') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('admin.aboutPage.pillarsHint') }}</p>
        <div class="space-y-4">
          <div v-for="(pillar, index) in form.pillars" :key="index" class="grid gap-3 rounded-lg border border-neutral-200 p-4 sm:grid-cols-[5rem_1fr_2fr]">
            <BaseInput v-model="pillar.icon" :placeholder="t('admin.aboutPage.pillarIconPlaceholder')" />
            <BaseInput v-model="pillar.title" :placeholder="t('admin.aboutPage.pillarTitlePlaceholder')" />
            <BaseInput v-model="pillar.description" :placeholder="t('admin.aboutPage.pillarDescriptionPlaceholder')" />
          </div>
        </div>
      </section>

      <!-- Achievements -->
      <section>
        <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.aboutPage.achievementsSection') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('admin.aboutPage.achievementsHint') }}</p>

        <BaseInput v-model="form.achievements_title" :label="t('admin.aboutPage.achievementsTitle')" class="mb-4" />

        <div class="grid gap-4 sm:grid-cols-2">
          <div v-for="(achievement, index) in form.achievements" :key="index" class="grid grid-cols-[3.5rem_1fr_1fr] gap-2 rounded-lg border border-neutral-200 p-3">
            <BaseInput v-model="achievement.icon" :placeholder="t('admin.aboutPage.achievementIconPlaceholder')" />
            <BaseInput v-model="achievement.value" :placeholder="t('admin.aboutPage.achievementValuePlaceholder')" />
            <BaseInput v-model="achievement.label" :placeholder="t('admin.aboutPage.achievementLabelPlaceholder')" />
          </div>
        </div>
      </section>

      <BaseButton type="submit" :loading="saving">{{ t('common.save') }}</BaseButton>
    </form>
  </div>
</template>
