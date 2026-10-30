<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'
import { useHelpCenter } from '@/composables/useHelpCenter'
import { useSupportChat } from '@/composables/useSupportChat'
import { pickText, telegramLink } from '@/utils/helpText'

/** Help & Support → Contact NewTech: chat, phone, Telegram, email, hours. */
const { t, locale } = useI18n()
const { data } = useHelpCenter()
const { openChat } = useSupportChat()

const c = computed(() => data.value?.contact)
const hours = computed(() => (c.value ? pickText(locale.value, c.value.hours_en, c.value.hours_km) : ''))
const telHref = computed(() => `tel:${(c.value?.phone ?? '').replace(/[^\d+]/g, '')}`)
const nothing = computed(() => !!c.value && !c.value.phone && !c.value.telegram && !c.value.email && !hours.value)
</script>

<template>
  <div class="grid max-w-3xl gap-3 sm:grid-cols-2">
    <div class="rounded-[--radius-card] border border-primary-200 bg-primary-50/50 p-4 sm:col-span-2">
      <p class="font-medium text-neutral-900">{{ t('help.contact.chatTitle') }}</p>
      <p class="mt-1 text-sm text-neutral-600">{{ t('help.contact.chatHint') }}</p>
      <BaseButton class="mt-3" size="sm" @click="openChat">{{ t('help.contact.openChat') }}</BaseButton>
    </div>

    <a v-if="c?.phone" :href="telHref" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] hover:border-primary-300">
      <p class="text-xs font-medium text-neutral-500">{{ t('help.contact.phone') }}</p>
      <p class="mt-1 font-medium text-neutral-900">{{ c.phone }}</p>
    </a>
    <a v-if="c?.telegram" :href="telegramLink(c.telegram)" target="_blank" rel="noopener" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] hover:border-primary-300">
      <p class="text-xs font-medium text-neutral-500">{{ t('help.contact.telegram') }}</p>
      <p class="mt-1 font-medium break-all text-neutral-900">{{ c.telegram }}</p>
    </a>
    <a v-if="c?.email" :href="`mailto:${c.email}`" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] hover:border-primary-300">
      <p class="text-xs font-medium text-neutral-500">{{ t('help.contact.email') }}</p>
      <p class="mt-1 font-medium break-all text-neutral-900">{{ c.email }}</p>
    </a>
    <div v-if="hours" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
      <p class="text-xs font-medium text-neutral-500">{{ t('help.contact.hours') }}</p>
      <p class="mt-1 font-medium whitespace-pre-line text-neutral-900">{{ hours }}</p>
    </div>

    <p v-if="nothing" class="text-sm text-neutral-500 sm:col-span-2">{{ t('help.contact.none') }}</p>
  </div>
</template>
