<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ApiRequestError } from '@/types/api'

import { websiteServicesApi, type ServiceCard, type WebsiteServices } from '../../api'
import ServiceCardModal from '../../components/ServiceCardModal.vue'
import { useSession } from '../../session'

/**
 * Website → Services page: when switched on, newtechkh.com opens on these
 * cards. Each opens its own page (/services/{address}), the Computer
 * School's pages (/computer-school), or a web address.
 */
const { t } = useI18n()
const session = useSession()
const canManage = computed(() => session.can('platform.website.manage'))

const content = ref<WebsiteServices | null>(null)
const loadError = ref<string | null>(null)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  try {
    content.value = await websiteServicesApi.get()
    loadError.value = null
  } catch (e) {
    loadError.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}
onMounted(load)

// The switch saves at once.
const switching = ref(false)
async function toggle(): Promise<void> {
  if (!content.value || !canManage.value) return
  switching.value = true
  error.value = null
  try {
    content.value = await websiteServicesApi.setEnabled(!content.value.enabled)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    switching.value = false
  }
}

const editing = ref<ServiceCard | null>(null)
const modalOpen = ref(false)
const schoolTaken = computed(() => content.value?.cards.some((c) => c.kind === 'school') ?? false)
const nextOrder = computed(() => Math.max(-1, ...(content.value?.cards.map((c) => c.sort_order) ?? [])) + 1)
function openAdd(): void {
  editing.value = null
  modalOpen.value = true
}
function openEdit(card: ServiceCard): void {
  editing.value = card
  modalOpen.value = true
}

const removing = ref<ServiceCard | null>(null)
const removeBusy = ref(false)
async function confirmRemove(): Promise<void> {
  if (!removing.value) return
  removeBusy.value = true
  try {
    await websiteServicesApi.removeCard(removing.value.id)
    removing.value = null
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
    removing.value = null
  } finally {
    removeBusy.value = false
  }
}

const title = (c: ServiceCard) => c.title_en || c.title_km || '—'
const opens = (c: ServiceCard) =>
  c.kind === 'school' ? '/computer-school' : c.kind === 'link' ? c.link_url : `/services/${c.slug}`
</script>

<template>
  <div class="max-w-5xl">
    <div v-if="!content && !loadError" class="flex justify-center py-10"><BaseSpinner /></div>
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <template v-else-if="content">
      <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

      <!-- The switch -->
      <section class="mb-6 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
        <div class="min-w-0 flex-1 basis-64">
          <p id="bo-services-on" class="font-semibold text-neutral-900">{{ t('bo.website.services.enabled') }}</p>
          <p class="mt-0.5 text-sm text-neutral-500">{{ content.enabled ? t('bo.website.services.enabledOn') : t('bo.website.services.enabledOff') }}</p>
        </div>
        <BaseBadge :variant="content.enabled ? 'success' : 'neutral'">{{ content.enabled ? t('bo.website.services.on') : t('bo.website.services.off') }}</BaseBadge>
        <button
          type="button"
          role="switch"
          aria-labelledby="bo-services-on"
          :aria-checked="content.enabled"
          :disabled="!canManage || switching"
          class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-60"
          :class="content.enabled ? 'bg-primary-500' : 'bg-neutral-300'"
          @click="toggle"
        >
          <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform" :class="content.enabled ? 'translate-x-5' : 'translate-x-0.5'" />
        </button>
      </section>

      <!-- The cards -->
      <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-neutral-500">{{ t('bo.website.services.cardsHint') }}</p>
        <BaseButton v-if="canManage" @click="openAdd">{{ t('bo.website.services.addCard') }}</BaseButton>
      </div>

      <p v-if="content.cards.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('bo.website.services.noCards') }}
      </p>
      <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <article
          v-for="card in content.cards"
          :key="card.id"
          class="flex flex-col overflow-hidden rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card]"
          :class="{ 'opacity-60': card.status === 'inactive' }"
        >
          <div class="aspect-[16/9] bg-neutral-100">
            <img v-if="card.image_url" :src="card.image_url" alt="" class="h-full w-full object-cover" />
            <div v-else class="flex h-full items-center justify-center text-3xl text-neutral-300" aria-hidden="true">🖼️</div>
          </div>
          <div class="flex flex-1 flex-col p-4">
            <div class="flex items-start justify-between gap-2">
              <h3 class="min-w-0 font-semibold break-words text-neutral-900">{{ title(card) }}</h3>
              <BaseBadge :variant="card.status === 'active' ? 'success' : 'neutral'">
                {{ card.status === 'active' ? t('bo.website.shown') : t('bo.website.hidden') }}
              </BaseBadge>
            </div>
            <p class="mt-1 text-xs text-neutral-500">
              {{ t(`bo.website.services.kinds.${card.kind}`) }} · <span class="break-all">{{ opens(card) }}</span>
            </p>
            <p class="text-xs text-neutral-400">{{ t('bo.website.orderN', { n: card.sort_order }) }}</p>
            <div v-if="canManage" class="mt-auto flex gap-4 pt-3 text-sm">
              <button type="button" class="text-secondary-700 hover:underline" @click="openEdit(card)">{{ t('bo.website.edit') }}</button>
              <button type="button" class="text-red-600 hover:underline" @click="removing = card">{{ t('bo.website.remove') }}</button>
            </div>
          </div>
        </article>
      </div>

      <ServiceCardModal v-model="modalOpen" :card="editing" :school-taken="schoolTaken" :next-order="nextOrder" @saved="load" />

      <BaseModal :model-value="removing !== null" :title="t('bo.website.removeTitle')" size="sm" @update:model-value="removing = null">
        <p class="text-sm text-neutral-600">{{ t('bo.website.removeText') }}</p>
        <template #footer>
          <BaseButton variant="ghost" @click="removing = null">{{ t('bo.common.cancel') }}</BaseButton>
          <BaseButton variant="danger" :loading="removeBusy" @click="confirmRemove">{{ t('bo.website.remove') }}</BaseButton>
        </template>
      </BaseModal>
    </template>
  </div>
</template>
