<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import { setLocale } from '../i18n'

/**
 * ខ្មែរ | English, the current one highlighted (as in SmartGPS-V3's back
 * office). Each language is named in its own script, so someone who cannot
 * read the other can still find theirs. `dark` for the navy bars.
 */
defineProps<{ dark?: boolean }>()

const { t, locale } = useI18n()

const languages = [
  { code: 'km', label: 'ខ្មែរ' },
  { code: 'en', label: 'English' },
] as const
</script>

<template>
  <div
    class="flex shrink-0 gap-0.5 rounded-[10px] border p-[3px]"
    :class="dark ? 'border-white/20' : 'border-neutral-200 bg-white'"
    role="group"
    :aria-label="t('bo.language')"
  >
    <button
      v-for="lang in languages"
      :key="lang.code"
      type="button"
      :lang="lang.code"
      class="min-h-[28px] rounded-[7px] px-2.5 text-xs font-semibold"
      :class="
        locale === lang.code
          ? dark
            ? 'bg-primary-500 text-secondary-900'
            : 'bg-primary-100 text-secondary-900'
          : dark
            ? 'text-secondary-200 hover:bg-white/10'
            : 'text-neutral-500 hover:text-neutral-800'
      "
      :aria-pressed="locale === lang.code"
      @click="setLocale(lang.code)"
    >
      {{ lang.label }}
    </button>
  </div>
</template>
