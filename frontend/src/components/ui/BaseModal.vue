<script setup lang="ts">
import { onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'

interface Props {
  modelValue: boolean
  title?: string
  size?: 'sm' | 'md' | 'lg'
}

const props = withDefaults(defineProps<Props>(), {
  title: undefined,
  size: 'md',
})

const { t } = useI18n()

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const sizes = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl' } as const

function close() {
  emit('update:modelValue', false)
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && props.modelValue) close()
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))

// Prevent the page from scrolling behind an open modal.
watch(
  () => props.modelValue,
  (open) => {
    document.body.style.overflow = open ? 'hidden' : ''
  },
)
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition ease-out duration-150"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition ease-in duration-100"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="modelValue"
        class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/50 p-4"
        @click.self="close"
      >
        <!--
          flex-col + max-h caps the dialog to the viewport and makes the
          *body* the scrolling region, with header/footer pinned outside that
          scroll — Save stays reachable (and visible) no matter how tall the
          content is (a large image preview plus a full form easily exceeds a
          laptop-height viewport, which is exactly what made Save unreachable
          before this: the box had no height limit or scroll of its own, so it
          simply extended past the top and bottom of the screen).
        -->
        <div
          role="dialog"
          aria-modal="true"
          class="flex max-h-[calc(100vh-2rem)] w-full flex-col rounded-xl bg-white shadow-xl"
          :class="sizes[size]"
        >
          <div v-if="title || $slots.header" class="flex shrink-0 items-center justify-between border-b border-neutral-100 p-6 pb-4">
            <slot name="header">
              <h2 class="text-lg font-semibold text-neutral-900">{{ title }}</h2>
            </slot>
            <button
              type="button"
              class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600"
              :aria-label="t('common.close')"
              @click="close"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="min-h-0 flex-1 overflow-y-auto p-6" :class="title || $slots.header ? 'pt-4' : ''">
            <slot />
          </div>

          <div v-if="$slots.footer" class="flex shrink-0 justify-end gap-3 border-t border-neutral-100 p-6 pt-4">
            <slot name="footer" />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
