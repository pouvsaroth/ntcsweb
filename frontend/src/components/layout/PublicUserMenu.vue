<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'

import ChangePasswordModal from '@/components/layout/ChangePasswordModal.vue'
import EditProfileModal from '@/components/layout/EditProfileModal.vue'
import { documentLinks, documentsAndFormLinks } from '@/router/publicNav'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'

/**
 * The public site's "you're logged in" indicator — a student who registered
 * and was approved logs in and stays right here (see Login.vue's redirect),
 * so the site needs its own visible confirmation of who's signed in, same
 * idea as AdminSidebar's account card but sized for a header instead of a
 * sidebar. Instantiated twice in PublicHeader.vue (desktop, mobile), same
 * pattern as LanguageSwitcher — `compact` shows a narrower label where
 * there's less room for it.
 */
const props = withDefaults(defineProps<{ compact?: boolean; align?: 'left' | 'right' }>(), {
  compact: false,
  align: 'right',
})

const { t } = useI18n()
const auth = useAuthStore()
const site = useSiteStore()
const router = useRouter()

// Name is the normal case; email/phone are the fallback for an account
// created without a name (e.g. a student self-registered with just a phone).
const identityLabel = computed(() => auth.user?.name || auth.user?.email || auth.user?.phone || '')

const menuOpen = ref(false)
const editProfileOpen = ref(false)
const changePasswordOpen = ref(false)

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
      <span class="truncate text-sm font-medium text-neutral-700" :class="compact ? 'max-w-[6rem]' : 'max-w-[8rem]'">{{ identityLabel }}</span>
    </button>

    <!-- Click-outside-to-close overlay — see LanguageSwitcher.vue. -->
    <div v-if="menuOpen" class="fixed inset-0 z-40" @click="menuOpen = false" />

    <Transition enter-active-class="transition ease-out duration-100" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
      <div
        v-if="menuOpen"
        class="absolute z-50 mt-2 w-64 rounded-lg border border-neutral-200 bg-white py-1 shadow-lg"
        :class="props.align === 'left' ? 'left-0' : 'right-0'"
      >
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

        <RouterLink
          v-if="auth.hasRole('student')"
          to="/admin/my-scores"
          class="block w-full px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
          @click="menuOpen = false"
        >
          {{ t('studentNav.score') }}
        </RouterLink>
        <RouterLink
          v-if="auth.hasRole('student')"
          to="/admin/my-attendance"
          class="block w-full px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
          @click="menuOpen = false"
        >
          {{ t('studentNav.attendant') }}
        </RouterLink>

        <!-- Moved here from the public header's standalone "Document and
             Form" menu — those links only ever mattered to a signed-in
             student, so they now live in the student's own profile menu
             instead of a top-level nav item everyone saw. -->
        <template v-if="auth.hasRole('student')">
          <p class="mt-1 border-t border-neutral-100 px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide text-neutral-400">
            {{ t('nav.documentsAndForm.formGroup') }}
          </p>
          <RouterLink
            v-for="item in documentsAndFormLinks"
            :key="item.to + item.labelKey"
            :to="item.to"
            class="block w-full px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
            @click="menuOpen = false"
          >
            {{ t(item.labelKey) }}
          </RouterLink>

          <p class="mt-1 border-t border-neutral-100 px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide text-neutral-400">
            {{ t('nav.documentsAndForm.documentGroup') }}
          </p>
          <template v-for="doc in documentLinks" :key="doc.labelKey">
            <a
              v-if="site.info.documents[doc.urlKey]"
              :href="site.info.documents[doc.urlKey] ?? undefined"
              target="_blank"
              rel="noopener"
              class="block w-full px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
              @click="menuOpen = false"
            >
              {{ t(doc.labelKey) }}
            </a>
          </template>
        </template>

        <button
          type="button"
          class="block w-full border-t border-neutral-100 px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
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
  </div>
</template>
