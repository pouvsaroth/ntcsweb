<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { documentsAndFormLinks } from '@/router/publicNav'
import { useSiteStore } from '@/stores/site'

/**
 * The public site's "Document and Form" menu — Form items route into
 * `/admin/*` self-service pages (login-gated for free, see publicNav.ts's
 * docblock); Document items link straight to the two files a school admin
 * uploaded under School Documents, with no login required. `mobile` renders
 * an always-expanded inline section instead of a click-to-open dropdown,
 * matching how the rest of PublicHeader's mobile panel behaves.
 */
withDefaults(defineProps<{ mobile?: boolean }>(), { mobile: false })

const emit = defineEmits<{ navigate: [] }>()

const { t } = useI18n()
const site = useSiteStore()

const open = ref(false)

const documentLinks = [
  { labelKey: 'nav.documentsAndForm.schoolRegulation', urlKey: 'school_regulation_url' as const },
  { labelKey: 'nav.documentsAndForm.attendancePolicy', urlKey: 'student_attendance_policy_url' as const },
]
</script>

<template>
  <div v-if="mobile" class="border-t border-neutral-100 pt-2">
    <p class="px-3 pt-1 text-xs font-semibold uppercase tracking-wide text-neutral-400">{{ t('nav.documentsAndForm.title') }}</p>

    <p class="px-3 pt-2 text-xs font-medium text-neutral-400">{{ t('nav.documentsAndForm.formGroup') }}</p>
    <RouterLink
      v-for="item in documentsAndFormLinks"
      :key="item.to + item.labelKey"
      :to="item.to"
      class="block rounded-lg px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-100"
      @click="emit('navigate')"
    >
      {{ t(item.labelKey) }}
    </RouterLink>

    <p class="px-3 pt-2 text-xs font-medium text-neutral-400">{{ t('nav.documentsAndForm.documentGroup') }}</p>
    <template v-for="doc in documentLinks" :key="doc.labelKey">
      <a
        v-if="site.info.documents[doc.urlKey]"
        :href="site.info.documents[doc.urlKey] ?? undefined"
        target="_blank"
        rel="noopener"
        class="block rounded-lg px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-100"
        @click="emit('navigate')"
      >
        {{ t(doc.labelKey) }}
      </a>
      <span v-else class="block px-3 py-2 text-sm text-neutral-300">{{ t(doc.labelKey) }}</span>
    </template>
  </div>

  <div v-else class="relative">
    <button
      type="button"
      class="flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
      :aria-expanded="open"
      @click="open = !open"
    >
      {{ t('nav.documentsAndForm.title') }}
      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
      </svg>
    </button>

    <div v-if="open" class="fixed inset-0 z-40" @click="open = false" />

    <Transition enter-active-class="transition ease-out duration-100" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
      <div v-if="open" class="absolute left-0 z-50 mt-2 w-64 rounded-lg border border-neutral-200 bg-white py-1 shadow-lg">
        <p class="px-3 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide text-neutral-400">{{ t('nav.documentsAndForm.formGroup') }}</p>
        <RouterLink
          v-for="item in documentsAndFormLinks"
          :key="item.to + item.labelKey"
          :to="item.to"
          class="block px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50"
          @click="open = false"
        >
          {{ t(item.labelKey) }}
        </RouterLink>

        <p class="mt-1 border-t border-neutral-100 px-3 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide text-neutral-400">
          {{ t('nav.documentsAndForm.documentGroup') }}
        </p>
        <template v-for="doc in documentLinks" :key="doc.labelKey">
          <a
            v-if="site.info.documents[doc.urlKey]"
            :href="site.info.documents[doc.urlKey] ?? undefined"
            target="_blank"
            rel="noopener"
            class="block px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50"
            @click="open = false"
          >
            {{ t(doc.labelKey) }}
          </a>
          <span v-else class="block px-3 py-2 text-sm text-neutral-300">{{ t(doc.labelKey) }}</span>
        </template>
      </div>
    </Transition>
  </div>
</template>
