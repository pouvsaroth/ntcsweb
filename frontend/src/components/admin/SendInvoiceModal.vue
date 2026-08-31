<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { invoicesService, notificationChannels, type NotificationChannelValue } from '@/services/invoices'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{ modelValue: boolean; invoiceId: number }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; sent: [] }>()

const { t } = useI18n()

const form = reactive({
  channel: 'EMAIL' as NotificationChannelValue,
  recipient: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const channelOptions = computed(() => [
  { value: 'EMAIL', label: t('admin.invoices.channelEmail') },
  { value: 'TELEGRAM', label: t('admin.invoices.channelTelegram') },
  { value: 'MESSENGER', label: t('admin.invoices.channelMessenger') },
])

const recipientHint = computed(() =>
  form.channel === 'EMAIL' ? t('admin.invoices.recipientHintEmail') : t('admin.invoices.recipientHintChatId'),
)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    form.channel = 'EMAIL'
    form.recipient = ''
    errors.value = {}
    generalError.value = null
  },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await invoicesService.send(props.invoiceId, form)
    emit('sent')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.invoices.sendFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.invoices.sendModalTitle')" size="sm" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseSelect
        v-model="form.channel"
        :options="notificationChannels.map((c) => channelOptions.find((o) => o.value === c)!)"
        required
        :label="t('admin.invoices.channel')"
        :error="errors.channel?.[0]"
      />
      <BaseInput
        v-model="form.recipient"
        required
        :label="t('admin.invoices.recipient')"
        :hint="recipientHint"
        :error="errors.recipient?.[0]"
      />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="!form.recipient.trim()" @click="submit">{{ t('admin.invoices.sendAction') }}</BaseButton>
    </template>
  </BaseModal>
</template>
