<script setup lang="ts">
import type { Locale } from '@/i18n'

/**
 * A small drawn flag for a language. Emoji flags (🇰🇭) are not drawn on
 * Windows — it shows the letters "KH" instead — so the flags are SVG.
 * Simplified on purpose: they are 20×14 pixels.
 */
defineProps<{ locale: Locale }>()
</script>

<template>
  <svg viewBox="0 0 30 20" class="h-3.5 w-5 shrink-0 overflow-hidden rounded-[2px] ring-1 ring-black/10" aria-hidden="true">
    <!-- Cambodia: blue, red with Angkor Wat, blue -->
    <g v-if="locale === 'km'">
      <rect width="30" height="20" fill="#032EA1" />
      <rect y="5" width="30" height="10" fill="#E00025" />
      <path
        fill="#fff"
        d="M8 13.4h14v-1H21v-1.4h-1V9.6h-.8V8.2h-.7V7h-.8V5.8h-.6L16.4 4.6 15 3.4l-1.4 1.2-.7 1.2h-.6V7h-.8v1.2h-.7v1.4H10V11H9v1.4H8z"
      />
    </g>
    <!-- United States -->
    <g v-else-if="locale === 'en'">
      <rect width="30" height="20" fill="#fff" />
      <path fill="#B22234" d="M0 0h30v1.54H0zm0 3.08h30v1.54H0zm0 3.08h30V7.7H0zm0 3.08h30v1.54H0zm0 3.08h30v1.54H0zm0 3.08h30v1.54H0zm0 3.08h30V20H0z" />
      <rect width="13" height="10.77" fill="#3C3B6E" />
      <g fill="#fff">
        <circle cx="2.2" cy="2" r=".55" /><circle cx="5.2" cy="2" r=".55" /><circle cx="8.2" cy="2" r=".55" /><circle cx="11" cy="2" r=".55" />
        <circle cx="3.7" cy="4.2" r=".55" /><circle cx="6.7" cy="4.2" r=".55" /><circle cx="9.6" cy="4.2" r=".55" />
        <circle cx="2.2" cy="6.4" r=".55" /><circle cx="5.2" cy="6.4" r=".55" /><circle cx="8.2" cy="6.4" r=".55" /><circle cx="11" cy="6.4" r=".55" />
        <circle cx="3.7" cy="8.6" r=".55" /><circle cx="6.7" cy="8.6" r=".55" /><circle cx="9.6" cy="8.6" r=".55" />
      </g>
    </g>
    <!-- China -->
    <g v-else-if="locale === 'zh'">
      <rect width="30" height="20" fill="#DE2910" />
      <path fill="#FFDE00" d="M5 2l1.18 3.6H10L6.9 7.8l1.18 3.6L5 9.2 1.92 11.4 3.1 7.8 0 5.6h3.82z" />
      <g fill="#FFDE00"><circle cx="10" cy="2" r=".8" /><circle cx="12" cy="4" r=".8" /><circle cx="12" cy="7" r=".8" /><circle cx="10" cy="9" r=".8" /></g>
    </g>
    <!-- South Korea -->
    <g v-else-if="locale === 'ko'">
      <rect width="30" height="20" fill="#fff" />
      <path fill="#CD2E3A" d="M10 10a5 5 0 0110 0 2.5 2.5 0 01-5 0 2.5 2.5 0 00-5 0z" />
      <path fill="#0047A0" d="M10 10a2.5 2.5 0 005 0 2.5 2.5 0 015 0 5 5 0 01-10 0z" />
      <g stroke="#000" stroke-width="1">
        <path d="M4 4.5l3-2.5M4.8 5.4l3-2.5M5.6 6.3l3-2.5" />
        <path d="M22.4 3.8l3 2.5M21.6 4.7l3 2.5M20.8 5.6l3 2.5" />
        <path d="M4 15.5l3 2.5M4.8 14.6l3 2.5M5.6 13.7l3 2.5" />
        <path d="M22.4 16.2l3-2.5M21.6 15.3l3-2.5M20.8 14.4l3-2.5" />
      </g>
    </g>
    <!-- Japan -->
    <g v-else>
      <rect width="30" height="20" fill="#fff" />
      <circle cx="15" cy="10" r="6" fill="#BC002D" />
    </g>
  </svg>
</template>
