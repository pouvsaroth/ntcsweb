<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { LOCALE_NAMES, SUPPORTED_LOCALES, setLocale, type Locale } from '@/i18n'

interface Props {
  /** 'light' for dark backgrounds (public header on a light page), 'dark' text for light backgrounds. */
  variant?: 'light' | 'dark'
}

withDefaults(defineProps<Props>(), { variant: 'dark' })

const { locale, t } = useI18n()
const open = ref(false)

function choose(next: Locale) {
  setLocale(next)
  open.value = false
}
</script>

<template>
  <div class="relative">
    <button
      type="button"
      class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium transition-colors"
      :class="variant === 'light' ? 'text-white/90 hover:bg-white/10' : 'text-neutral-600 hover:bg-neutral-100'"
      :aria-label="t('common.language')"
      :aria-expanded="open"
      @click="open = !open"
    >
      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M9 5a3.75 3.75 0 01-3.75 3.75M12 20l3-6 3 6m-.75-2h-4.5"
        />
      </svg>
      <span>{{ LOCALE_NAMES[locale as Locale] }}</span>
    </button>

    <div v-if="open" class="fixed inset-0 z-40" @click="open = false" />

    <Transition
      enter-active-class="transition ease-out duration-100"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
    >
      <ul
        v-if="open"
        class="absolute right-0 z-50 mt-2 w-40 rounded-lg border border-neutral-200 bg-white py-1 shadow-lg"
        role="listbox"
      >
        <li v-for="code in SUPPORTED_LOCALES" :key="code">
          <button
            type="button"
            class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
            role="option"
            :aria-selected="locale === code"
            @click="choose(code)"
          >
            {{ LOCALE_NAMES[code] }}
            <svg v-if="locale === code" class="h-4 w-4 text-secondary-600" fill="currentColor" viewBox="0 0 20 20">
              <path
                fill-rule="evenodd"
                d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"
                clip-rule="evenodd"
              />
            </svg>
          </button>
        </li>
      </ul>
    </Transition>
  </div>
</template>
