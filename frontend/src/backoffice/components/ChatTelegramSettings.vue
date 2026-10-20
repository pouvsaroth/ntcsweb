<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { ApiRequestError } from '@/types/api'

import { chatsApi } from '../api'

/**
 * Support → Settings → Telegram alerts for support chats
 * (docs/backoffice/stage-10-support-chat.md). The bot token is write-only.
 */
const { t } = useI18n()

const loaded = ref(false)
const tokenSet = ref(false)
const chatId = ref('')
const token = ref('')
const saving = ref(false)
const saved = ref(false)
const error = ref<ApiRequestError | null>(null)
const testing = ref(false)
const test = ref<{ ok: boolean; message: string } | null>(null)

onMounted(async () => {
  const s = await chatsApi.settings()
  tokenSet.value = s.telegram_token_set
  chatId.value = s.telegram_chat_id ?? ''
  loaded.value = true
})

async function save(clearToken = false): Promise<void> {
  saving.value = true
  saved.value = false
  error.value = null
  try {
    const s = await chatsApi.saveSettings({ telegram_bot_token: token.value.trim() || null, telegram_chat_id: chatId.value.trim() || null, clear_token: clearToken })
    tokenSet.value = s.telegram_token_set
    chatId.value = s.telegram_chat_id ?? ''
    token.value = ''
    saved.value = true
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    saving.value = false
  }
}

async function sendTest(): Promise<void> {
  testing.value = true
  test.value = null
  try {
    test.value = await chatsApi.testTelegram()
  } finally {
    testing.value = false
  }
}
</script>

<template>
  <form v-if="loaded" class="max-w-xl space-y-4 rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]" @submit.prevent="save()">
    <div>
      <h2 class="font-semibold text-neutral-900">{{ t('bo.chats.telegram.title') }}</h2>
      <p class="text-sm text-neutral-500">{{ t('bo.chats.telegram.hint') }}</p>
    </div>
    <BaseAlert v-if="saved" variant="success">{{ t('bo.common.saved') }}</BaseAlert>
    <BaseAlert v-if="error && !error.errors" variant="danger">{{ error.message }}</BaseAlert>

    <div>
      <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bo.chats.telegram.token') }}</p>
      <p class="mb-2 text-sm" :class="tokenSet ? 'text-green-700' : 'text-amber-700'">
        {{ tokenSet ? `✓ ${t('bo.chats.telegram.tokenSet')}` : t('bo.chats.telegram.tokenNone') }}
      </p>
      <BaseInput
        v-model="token"
        type="password"
        autocomplete="off"
        :placeholder="tokenSet ? t('bo.chats.telegram.tokenReplace') : '123456789:AA…'"
        :hint="t('bo.chats.telegram.tokenHint')"
        :error="error?.fieldError('telegram_bot_token')"
      />
    </div>
    <BaseInput v-model="chatId" :label="t('bo.chats.telegram.chatId')" placeholder="-1001234567890" :hint="t('bo.chats.telegram.chatIdHint')" :error="error?.fieldError('telegram_chat_id')" />

    <div class="flex flex-wrap items-center gap-2">
      <BaseButton type="submit" :loading="saving">{{ t('bo.common.save') }}</BaseButton>
      <BaseButton v-if="tokenSet" variant="outline" :loading="testing" @click="sendTest">{{ t('bo.chats.telegram.test') }}</BaseButton>
      <BaseButton v-if="tokenSet" variant="ghost" :disabled="saving" @click="save(true)">{{ t('bo.chats.telegram.remove') }}</BaseButton>
    </div>
    <p v-if="test" class="text-sm" :class="test.ok ? 'text-green-700' : 'text-red-600'">{{ test.ok ? t('bo.chats.telegram.testOk') : test.message }}</p>
  </form>
</template>
