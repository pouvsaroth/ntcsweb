<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ApiRequestError } from '@/types/api'

import { websiteApi, type WebsiteAbout } from '../../api'
import { useSession } from '../../session'

/** The website's About page: 4 stats, the history block, 3 pillars, 4 achievements. */
const { t } = useI18n()
const session = useSession()
const canManage = computed(() => session.can('platform.website.manage'))

const rows = <K extends string>(n: number, keys: readonly K[]) => Array.from({ length: n }, () => Object.fromEntries(keys.map((k) => [k, ''])) as Record<K, string>)

const form = reactive<Omit<WebsiteAbout, 'history_image_url'>>({
  history_title: '',
  history_paragraph_1: '',
  history_paragraph_2: '',
  stats: rows(4, ['value', 'label'] as const),
  pillars: rows(3, ['icon', 'title', 'description'] as const),
  achievements_title: '',
  achievements: rows(4, ['icon', 'value', 'label'] as const),
})

const image = ref<File | null>(null)
const preview = ref<string | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const busy = ref(false)
const saved = ref(false)
const error = ref<ApiRequestError | null>(null)
const fieldError = (f: string) => error.value?.fieldError(f)

onMounted(async () => {
  try {
    const about = await websiteApi.about()
    form.history_title = about.history_title
    form.history_paragraph_1 = about.history_paragraph_1
    form.history_paragraph_2 = about.history_paragraph_2
    form.achievements_title = about.achievements_title
    if (about.stats.length === 4) form.stats = about.stats.map((r) => ({ value: r.value ?? '', label: r.label ?? '' }))
    if (about.pillars.length === 3) form.pillars = about.pillars.map((r) => ({ icon: r.icon ?? '', title: r.title ?? '', description: r.description ?? '' }))
    if (about.achievements.length === 4) form.achievements = about.achievements.map((r) => ({ icon: r.icon ?? '', value: r.value ?? '', label: r.label ?? '' }))
    preview.value = about.history_image_url
  } catch (e) {
    loadError.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
  }
})

function onFile(event: Event): void {
  const picked = (event.target as HTMLInputElement).files?.[0]
  if (!picked) return
  image.value = picked
  preview.value = URL.createObjectURL(picked)
}

async function save(): Promise<void> {
  busy.value = true
  saved.value = false
  error.value = null
  try {
    const about = await websiteApi.saveAbout({ ...form, history_image: image.value ?? undefined })
    image.value = null
    preview.value = about.history_image_url
    saved.value = true
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : null
  } finally {
    busy.value = false
  }
}

const textarea =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none disabled:bg-neutral-50'
</script>

<template>
  <div class="max-w-4xl">
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <form v-else class="space-y-8" @submit.prevent="save">
      <BaseAlert v-if="error" variant="danger">{{ error.message }}</BaseAlert>
      <BaseAlert v-if="saved" variant="success">{{ t('bo.website.about.saved') }}</BaseAlert>

      <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
        <h2 class="font-semibold text-neutral-900">{{ t('bo.website.about.stats') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('bo.website.about.statsHint') }}</p>
        <div class="grid gap-3 sm:grid-cols-2">
          <div v-for="(stat, i) in form.stats" :key="i" class="flex gap-2 rounded-lg border border-neutral-200 p-3">
            <BaseInput v-model="stat.value" :placeholder="t('bo.website.about.statValue')" :error="fieldError(`stats.${i}.value`)" :disabled="!canManage" class="w-24 shrink-0" />
            <BaseInput v-model="stat.label" :placeholder="t('bo.website.about.statLabel')" :error="fieldError(`stats.${i}.label`)" :disabled="!canManage" class="min-w-0 flex-1" />
          </div>
        </div>
      </section>

      <section class="space-y-4 rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
        <div>
          <h2 class="font-semibold text-neutral-900">{{ t('bo.website.about.history') }}</h2>
          <p class="text-sm text-neutral-500">{{ t('bo.website.about.historyHint') }}</p>
        </div>
        <BaseInput v-model="form.history_title" :label="t('bo.website.about.sectionTitle')" :error="fieldError('history_title')" :disabled="!canManage" />
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.website.about.paragraph1') }}</span>
          <textarea v-model="form.history_paragraph_1" rows="4" :class="textarea" :disabled="!canManage" />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.website.about.paragraph2') }}</span>
          <textarea v-model="form.history_paragraph_2" rows="4" :class="textarea" :disabled="!canManage" />
        </label>
        <div>
          <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bo.website.picture') }}</p>
          <div v-if="preview" class="mb-2 aspect-video w-full max-w-md overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100">
            <img :src="preview" alt="" class="h-full w-full object-cover" />
          </div>
          <input
            v-if="canManage"
            type="file"
            accept="image/jpeg,image/png,image/webp,image/gif"
            class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
            @change="onFile"
          />
          <p v-if="fieldError('history_image')" class="mt-1 text-sm text-red-600">{{ fieldError('history_image') }}</p>
        </div>
      </section>

      <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
        <h2 class="font-semibold text-neutral-900">{{ t('bo.website.about.pillars') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('bo.website.about.pillarsHint') }}</p>
        <div class="space-y-3">
          <div v-for="(pillar, i) in form.pillars" :key="i" class="grid gap-2 rounded-lg border border-neutral-200 p-3 sm:grid-cols-[5rem_1fr_2fr]">
            <BaseInput v-model="pillar.icon" :placeholder="t('bo.website.about.icon')" :disabled="!canManage" />
            <BaseInput v-model="pillar.title" :placeholder="t('bo.website.about.pillarTitle')" :disabled="!canManage" />
            <BaseInput v-model="pillar.description" :placeholder="t('bo.website.about.description')" :disabled="!canManage" />
          </div>
        </div>
      </section>

      <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
        <h2 class="font-semibold text-neutral-900">{{ t('bo.website.about.achievements') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('bo.website.about.achievementsHint') }}</p>
        <BaseInput v-model="form.achievements_title" :label="t('bo.website.about.sectionTitle')" :disabled="!canManage" class="mb-3" />
        <div class="grid gap-3 sm:grid-cols-2">
          <div v-for="(a, i) in form.achievements" :key="i" class="grid grid-cols-[3.5rem_1fr_1fr] gap-2 rounded-lg border border-neutral-200 p-3">
            <BaseInput v-model="a.icon" :placeholder="t('bo.website.about.icon')" :disabled="!canManage" />
            <BaseInput v-model="a.value" :placeholder="t('bo.website.about.achievementValue')" :disabled="!canManage" />
            <BaseInput v-model="a.label" :placeholder="t('bo.website.about.achievementLabel')" :disabled="!canManage" />
          </div>
        </div>
      </section>

      <BaseButton v-if="canManage" type="submit" :loading="busy">{{ t('bo.common.save') }}</BaseButton>
    </form>
  </div>
</template>
