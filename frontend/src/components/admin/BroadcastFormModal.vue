<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { marketingBroadcastsService, type BroadcastTargets, type MarketingBroadcast } from '@/services/marketingBroadcasts'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/** Marketing > Broadcast: write a promotion and send it to linked Telegram groups (asks to confirm first). */
const props = defineProps<{ modelValue: boolean; copyFrom: MarketingBroadcast | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; sent: [broadcast: MarketingBroadcast] }>()

const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

const MAX = 4000
const form = reactive({ title: '', message: '', marketing_campaign_id: '', group_ids: [] as number[] })
const targets = ref<BroadcastTargets | null>(null)
const errors = ref<Record<string, string[]>>({})
const sendError = ref<string | null>(null)
const sending = ref(false)

const campaignOptions = computed(() => [
  { value: '', label: t('marketing.broadcasts.noCampaign') },
  ...(targets.value?.campaigns ?? []).map((c) => ({ value: String(c.id), label: c.name, hint: c.code ?? undefined })),
])

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    const c = props.copyFrom
    form.title = c?.title ?? ''
    form.message = c?.message ?? ''
    form.marketing_campaign_id = c?.marketing_campaign_id ? String(c.marketing_campaign_id) : ''
    form.group_ids = []
    errors.value = {}
    sendError.value = null
    targets.value = await marketingBroadcastsService.targets().catch(() => null)
    const ids = (targets.value?.groups ?? []).map((g) => g.id)
    form.group_ids = c ? c.results.map((r) => r.group_id).filter((id) => ids.includes(id)) : ids.length === 1 ? ids : []
  },
)

function toggle(id: number) {
  form.group_ids = form.group_ids.includes(id) ? form.group_ids.filter((g) => g !== id) : [...form.group_ids, id]
}

const canSend = computed(() => !!targets.value?.bot_ready && form.title.trim() !== '' && form.message.trim() !== '' && form.message.length <= MAX && form.group_ids.length > 0)

async function send() {
  if (!(await confirmDialog.confirm({ message: t('marketing.broadcasts.sendConfirm', { n: form.group_ids.length }) }))) return
  sending.value = true
  errors.value = {}
  sendError.value = null
  try {
    const sent = await marketingBroadcastsService.send({
      title: form.title.trim(),
      message: form.message.trim(),
      marketing_campaign_id: form.marketing_campaign_id ? Number(form.marketing_campaign_id) : null,
      group_ids: form.group_ids,
    })
    emit('sent', sent)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else sendError.value = e instanceof ApiRequestError ? e.message : t('marketing.broadcasts.sendFailed')
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" size="lg" :title="t('marketing.broadcasts.new')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" novalidate @submit.prevent="send">
      <BaseAlert v-if="sendError" variant="danger">{{ sendError }}</BaseAlert>
      <BaseAlert v-if="targets && !targets.bot_ready" variant="warning">{{ t('marketing.broadcasts.noBot') }}</BaseAlert>
      <BaseInput v-model="form.title" required :label="t('marketing.broadcasts.titleField')" :hint="t('marketing.broadcasts.titleHint')" :error="errors.title?.[0]" />
      <div>
        <label for="broadcast-message" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('marketing.broadcasts.message') }} <span class="text-danger-600">*</span></label>
        <textarea
          id="broadcast-message"
          v-model="form.message"
          rows="7"
          :maxlength="MAX"
          :placeholder="t('marketing.broadcasts.messagePlaceholder')"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
        <p class="mt-1 text-right text-xs text-neutral-500">{{ form.message.length }} / {{ MAX }}</p>
        <p v-if="errors.message?.[0]" class="text-sm text-danger-600">{{ errors.message[0] }}</p>
      </div>
      <SearchableSelect v-model="form.marketing_campaign_id" :options="campaignOptions" :label="t('marketing.broadcasts.campaign')" :error="errors.marketing_campaign_id?.[0]" />

      <div>
        <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('marketing.broadcasts.sendTo') }} <span class="text-danger-600">*</span></p>
        <p v-if="targets && targets.groups.length === 0" class="rounded-lg border border-dashed border-neutral-300 p-3 text-sm text-neutral-500">
          {{ t('marketing.broadcasts.noGroups') }}
          <RouterLink to="/admin/telegram-notifications" class="font-medium text-primary-700 hover:underline">{{ t('marketing.broadcasts.linkGroups') }}</RouterLink>
        </p>
        <div v-else class="grid gap-2 sm:grid-cols-2">
          <label
            v-for="group in targets?.groups ?? []"
            :key="group.id"
            class="flex cursor-pointer items-start gap-2 rounded-lg border p-2.5 text-sm"
            :class="form.group_ids.includes(group.id) ? 'border-primary-400 bg-primary-50' : 'border-neutral-200'"
          >
            <input type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" :checked="form.group_ids.includes(group.id)" @change="toggle(group.id)" />
            <span class="min-w-0">
              <span class="block font-medium text-neutral-900">{{ group.name }}</span>
              <span v-if="group.telegram_title" class="block truncate text-xs text-neutral-500">{{ group.telegram_title }}</span>
            </span>
          </label>
        </div>
        <p v-if="errors.group_ids?.[0]" class="mt-1 text-sm text-danger-600">{{ errors.group_ids[0] }}</p>
      </div>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="sending" :disabled="!canSend" @click="send">{{ t('marketing.broadcasts.send') }}</BaseButton>
    </template>
  </BaseModal>
</template>
