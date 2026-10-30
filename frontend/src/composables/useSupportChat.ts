import { ref } from 'vue'

/**
 * Lets any page open the top bar's support chat panel (SupportChat.vue
 * watches this), e.g. Help & Support → Contact → "Chat with us".
 */
const openRequests = ref(0)

export function useSupportChat() {
  return {
    openRequests,
    openChat: () => {
      openRequests.value++
    },
  }
}
