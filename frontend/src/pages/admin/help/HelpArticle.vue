<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useSupportChat } from '@/composables/useSupportChat'
import { helpService, type HelpArticle } from '@/services/help'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { helpBlocks, pickText } from '@/utils/helpText'

/** One help article in this screen's language, shown as text (never HTML). */
const { t, locale } = useI18n()
const route = useRoute()
const { openChat } = useSupportChat()

const article = ref<HelpArticle | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const title = computed(() => (article.value ? pickText(locale.value, article.value.title_en, article.value.title_km) : ''))
const blocks = computed(() => (article.value ? helpBlocks(pickText(locale.value, article.value.body_en, article.value.body_km)) : []))
const moduleLabel = computed(() => {
  const a = article.value
  if (!a?.module) return t('help.general')
  return pickText(locale.value, a.module_label, a.module_label_km)
})

onMounted(async () => {
  try {
    article.value = await helpService.article(Number(route.params.id))
  } catch (e) {
    error.value = e instanceof ApiRequestError && e.status === 404 ? t('help.articleMissing') : t('help.loadFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="max-w-3xl">
    <RouterLink to="/admin/help/articles" class="mb-4 inline-flex items-center gap-1 text-sm font-medium text-secondary-700 hover:underline">← {{ t('help.backToArticles') }}</RouterLink>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <BaseAlert v-else-if="error" variant="warning">{{ error }}</BaseAlert>

    <article v-else-if="article" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] sm:p-6">
      <p class="text-xs font-medium tracking-wide text-primary-700 uppercase">{{ moduleLabel }}</p>
      <h2 class="mt-1 text-lg font-semibold text-neutral-900 sm:text-xl">{{ title }}</h2>
      <p v-if="article.updated_at" class="mt-1 text-xs text-neutral-500">{{ t('help.updatedOn', { date: formatDate(article.updated_at) }) }}</p>

      <div class="mt-4 space-y-3 text-sm leading-relaxed text-neutral-800">
        <template v-for="(b, i) in blocks" :key="i">
          <h3 v-if="b.type === 'h'" class="pt-2 text-base font-semibold text-neutral-900">{{ b.text }}</h3>
          <ul v-else-if="b.type === 'ul'" class="list-disc space-y-1 pl-5">
            <li v-for="(item, j) in b.items" :key="j">{{ item }}</li>
          </ul>
          <p v-else class="whitespace-pre-line">{{ b.text }}</p>
        </template>
      </div>

      <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-neutral-100 pt-4">
        <p class="text-sm text-neutral-600">{{ t('help.stillStuck') }}</p>
        <BaseButton size="sm" variant="outline" @click="openChat">{{ t('help.askChat') }}</BaseButton>
      </div>
    </article>
  </div>
</template>
