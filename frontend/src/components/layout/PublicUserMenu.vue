<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'

import AskForPermissionModal from '@/components/layout/AskForPermissionModal.vue'
import ChangePasswordModal from '@/components/layout/ChangePasswordModal.vue'
import EditProfileModal from '@/components/layout/EditProfileModal.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * The public site's "you're logged in" indicator — a student who registered
 * and was approved logs in and stays right here (see Login.vue's redirect),
 * so the site needs its own visible confirmation of who's signed in, same
 * idea as AdminSidebar's account card but sized for a header instead of a
 * sidebar. Instantiated twice in PublicHeader.vue (desktop, mobile), same
 * pattern as LanguageSwitcher — `compact` drops the name text where there's
 * no room for it.
 */
withDefaults(defineProps<{ compact?: boolean }>(), { compact: false })

const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()

const menuOpen = ref(false)
const editProfileOpen = ref(false)
const changePasswordOpen = ref(false)
const askForPermissionOpen = ref(false)

async function handleLogout() {
  menuOpen.value = false
  await auth.logout()
  await router.push('/')
}
</script>

<template>
  <div class="relative">
    <button
      type="button"
      class="flex items-center gap-2 rounded-lg p-1 hover:bg-neutral-100"
      :aria-expanded="menuOpen"
      :aria-label="t('common.account')"
      @click="menuOpen = !menuOpen"
    >
      <span class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-primary-100 text-sm font-semibold text-primary-800">
        <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" alt="" class="h-full w-full object-cover" />
        <template v-else>{{ auth.user?.name.charAt(0) ?? '?' }}</template>
      </span>
      <span v-if="!compact" class="max-w-[8rem] truncate text-sm font-medium text-neutral-700">{{ auth.user?.name }}</span>
    </button>

    <!-- Click-outside-to-close overlay — see LanguageSwitcher.vue. -->
    <div v-if="menuOpen" class="fixed inset-0 z-40" @click="menuOpen = false" />

    <Transition enter-active-class="transition ease-out duration-100" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
      <div v-if="menuOpen" class="absolute right-0 z-50 mt-2 w-64 rounded-lg border border-neutral-200 bg-white py-1 shadow-lg">
        <div class="flex items-center gap-3 border-b border-neutral-100 px-4 py-3">
          <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-primary-100 text-base font-semibold text-primary-800">
            <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" alt="" class="h-full w-full object-cover" />
            <template v-else>{{ auth.user?.name.charAt(0) ?? '?' }}</template>
          </span>
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-neutral-900">{{ auth.user?.name }}</p>
            <p v-if="auth.user?.email" class="truncate text-xs text-neutral-500">{{ auth.user.email }}</p>
          </div>
        </div>

        <!-- A student has no admin permissions at all — this shortcut only
             makes sense for a staff/admin account that happens to be
             signed in while browsing the public site. -->
        <RouterLink
          v-if="!auth.hasRole('student')"
          to="/admin"
          class="block w-full px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
          @click="menuOpen = false"
        >
          {{ t('common.adminPanel') }}
        </RouterLink>

        <button
          v-if="auth.hasRole('student')"
          type="button"
          class="block w-full px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
          @click="
            menuOpen = false;
            askForPermissionOpen = true
          "
        >
          {{ t('leaveRequest.askForPermission') }}
        </button>
        <button
          type="button"
          class="block w-full px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
          @click="
            menuOpen = false;
            editProfileOpen = true
          "
        >
          {{ t('common.editProfile') }}
        </button>
        <button
          type="button"
          class="block w-full px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
          @click="
            menuOpen = false;
            changePasswordOpen = true
          "
        >
          {{ t('common.changePassword') }}
        </button>
        <button
          type="button"
          class="block w-full border-t border-neutral-100 px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
          @click="handleLogout"
        >
          {{ t('common.signOut') }}
        </button>
      </div>
    </Transition>

    <EditProfileModal v-model="editProfileOpen" />
    <ChangePasswordModal v-model="changePasswordOpen" />
    <AskForPermissionModal v-if="auth.hasRole('student')" v-model="askForPermissionOpen" />
  </div>
</template>
