<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue'
import { useAuthStore } from '@/stores/auth'

defineEmits<{ 'toggle-sidebar': [] }>()

const auth = useAuthStore()
const router = useRouter()
const { t } = useI18n()
const menuOpen = ref(false)

async function handleLogout() {
  await auth.logout()
  await router.push('/login')
}
</script>

<template>
  <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-neutral-200 bg-white px-4 sm:px-6">
    <div class="flex items-center gap-3">
      <button
        type="button"
        class="rounded-lg p-2 text-neutral-600 hover:bg-neutral-100 lg:hidden"
        :aria-label="t('common.toggleSidebar')"
        @click="$emit('toggle-sidebar')"
      >
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
      </button>
      <p v-if="auth.tenantName" class="hidden text-sm font-medium text-neutral-500 sm:block">
        {{ auth.tenantName }}
      </p>
      <p v-else-if="auth.isSuperAdmin" class="hidden text-sm font-medium text-neutral-500 sm:block">
        Platform administration
      </p>
    </div>

    <div class="flex items-center gap-2">
      <LanguageSwitcher />

      <div class="relative">
        <button
          type="button"
          class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-neutral-100"
          @click="menuOpen = !menuOpen"
        >
          <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-sm font-semibold text-primary-800">
            {{ auth.user?.name.charAt(0) ?? '?' }}
          </span>
          <span class="hidden text-sm font-medium text-neutral-700 sm:block">{{ auth.user?.name }}</span>
        </button>

        <Transition
          enter-active-class="transition ease-out duration-100"
          enter-from-class="opacity-0 scale-95"
          enter-to-class="opacity-100 scale-100"
        >
          <div
            v-if="menuOpen"
            class="absolute right-0 mt-2 w-48 rounded-lg border border-neutral-200 bg-white py-1 shadow-lg"
            @click="menuOpen = false"
          >
            <p class="truncate border-b border-neutral-100 px-4 py-2 text-xs text-neutral-500">
              {{ auth.user?.email }}
            </p>
            <button
              type="button"
              class="block w-full px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
              @click="handleLogout"
            >
              {{ t('common.signOut') }}
            </button>
          </div>
        </Transition>
      </div>
    </div>
  </header>
</template>
