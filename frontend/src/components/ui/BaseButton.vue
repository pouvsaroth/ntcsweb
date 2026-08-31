<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

interface Props {
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger'
  size?: 'sm' | 'md' | 'lg'
  type?: 'button' | 'submit' | 'reset'
  disabled?: boolean
  loading?: boolean
  /**
   * Client-side SPA navigation via vue-router — use this for any in-app
   * route. Renders a <RouterLink>, so no full page reload happens.
   */
  to?: string
  /**
   * Renders a plain <a>, styled identically to a button — reserve this for
   * genuinely external URLs, or an internal route that should deliberately
   * open in a new tab/window (pass target="_blank" alongside it). Using it
   * for an internal route that should stay in the same tab, use `to`
   * instead — a plain href there forces a full page reload.
   */
  href?: string
  /** Only meaningful together with `href`, e.g. target="_blank". */
  target?: string
  block?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  variant: 'primary',
  size: 'md',
  type: 'button',
  disabled: false,
  loading: false,
  to: undefined,
  href: undefined,
  target: undefined,
  block: false,
})

const tag = computed(() => (props.to ? RouterLink : props.href ? 'a' : 'button'))

const variantClasses: Record<NonNullable<Props['variant']>, string> = {
  primary: 'bg-primary-600 text-secondary-900 hover:bg-primary-700 focus-visible:outline-primary-600',
  secondary: 'bg-secondary-500 text-white hover:bg-secondary-600 focus-visible:outline-secondary-500',
  outline: 'border border-neutral-300 text-neutral-700 hover:bg-neutral-100 focus-visible:outline-primary-600',
  ghost: 'text-neutral-700 hover:bg-neutral-100 focus-visible:outline-primary-600',
  danger: 'bg-danger-600 text-white hover:bg-red-700 focus-visible:outline-danger-600',
}

const sizeClasses: Record<NonNullable<Props['size']>, string> = {
  sm: 'px-3 py-1.5 text-sm gap-1.5',
  md: 'px-4 py-2 text-sm gap-2',
  lg: 'px-5 py-2.5 text-base gap-2',
}
</script>

<template>
  <component
    :is="tag"
    :type="tag === 'button' ? type : undefined"
    :to="to"
    :href="to ? undefined : href"
    :target="to ? undefined : target"
    :rel="!to && target === '_blank' ? 'noopener noreferrer' : undefined"
    :disabled="tag === 'button' ? disabled || loading : undefined"
    :aria-disabled="disabled || loading"
    class="inline-flex items-center justify-center rounded-lg font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50 disabled:pointer-events-none"
    :class="[variantClasses[variant], sizeClasses[size], block ? 'w-full' : '']"
  >
    <svg
      v-if="loading"
      class="h-4 w-4 animate-spin"
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
    >
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
    </svg>
    <slot />
  </component>
</template>
