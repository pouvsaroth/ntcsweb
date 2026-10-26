<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useConfirmDialogStore } from '@/stores/confirmDialog'

/**
 * The one place a "are you sure?" prompt is actually drawn — see
 * confirmDialog.ts for why every page shares this single instance instead
 * of each owning its own modal. Deliberately its own compact, centered
 * layout (title, message, a divider, two evenly split text buttons) rather
 * than BaseModal's form-dialog look (left-aligned header, X button,
 * right-aligned footer buttons) — a yes/no prompt reads better as a small
 * alert than a document.
 */
const store = useConfirmDialogStore()
const { t } = useI18n()

/** Only a click that also began outside the box cancels (see BaseModal). */
const pressedOutside = ref(false)
const releasedOutside = ref(false)

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && store.open) store.settle(false)
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <!-- z-[70]: above BaseModal (z-50), since a confirm is often asked from inside a popup (e.g. Approve in an Approvals detail). -->
  <Teleport to="body">
    <Transition
      enter-active-class="transition ease-out duration-150"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition ease-in duration-100"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div v-if="store.open" class="fixed inset-0 z-[70] flex items-center justify-center bg-neutral-900/50 p-4" @pointerdown="pressedOutside = $event.target === $event.currentTarget" @pointerup="releasedOutside = $event.target === $event.currentTarget" @click.self="pressedOutside && releasedOutside && store.settle(false)">
        <div role="alertdialog" aria-modal="true" class="w-full max-w-[300px] overflow-hidden rounded-2xl bg-white shadow-xl">
          <div class="px-5 pb-5 pt-6 text-center">
            <h2 class="text-base font-semibold text-neutral-900">{{ store.options.title ?? t('common.confirm') }}</h2>
            <p class="mt-2 text-sm text-neutral-600">{{ store.options.message }}</p>
          </div>

          <div class="flex border-t border-neutral-200">
            <button
              type="button"
              class="flex-1 border-r border-neutral-200 py-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50"
              @click="store.settle(false)"
            >
              {{ store.options.cancelLabel ?? t('common.cancel') }}
            </button>
            <button
              type="button"
              class="flex-1 py-3 text-sm font-semibold hover:bg-neutral-50"
              :class="store.options.danger ? 'text-danger-600' : 'text-primary-600'"
              @click="store.settle(true)"
            >
              {{ store.options.confirmLabel ?? t('common.yes') }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
