import { ref } from 'vue'
import { defineStore } from 'pinia'

export interface ConfirmOptions {
  /** Defaults to the generic "Confirm" translation when omitted. */
  title?: string
  message: string
  /** Defaults to the generic "Yes"/"Cancel" translations when omitted. */
  confirmLabel?: string
  cancelLabel?: string
  /** Renders the confirm button in the danger color for a consequential action. */
  danger?: boolean
}

/**
 * A single, app-wide "are you sure?" dialog — one <ConfirmDialog> (mounted
 * once in AdminLayout) renders whatever this store's state currently holds,
 * so any page can request one without owning its own modal markup/state.
 * Promise-based specifically so call sites read exactly like the native
 * `confirm()` they replace: `if (!(await confirmDialog.confirm(message))) return`.
 */
export const useConfirmDialogStore = defineStore('confirmDialog', () => {
  const open = ref(false)
  const options = ref<ConfirmOptions>({ message: '' })

  let resolver: ((value: boolean) => void) | null = null

  function confirm(optionsOrMessage: ConfirmOptions | string): Promise<boolean> {
    options.value = typeof optionsOrMessage === 'string' ? { message: optionsOrMessage } : optionsOrMessage
    open.value = true

    return new Promise((resolve) => {
      resolver = resolve
    })
  }

  function settle(value: boolean): void {
    open.value = false
    resolver?.(value)
    resolver = null
  }

  return { open, options, confirm, settle }
})
