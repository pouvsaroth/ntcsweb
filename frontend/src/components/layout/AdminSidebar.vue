<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import ChangePasswordModal from '@/components/layout/ChangePasswordModal.vue'
import EditProfileModal from '@/components/layout/EditProfileModal.vue'
import { adminNav } from '@/router/adminNav'
import { useAdminUiStore } from '@/stores/adminUi'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()

defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: [] }>()

const auth = useAuthStore()
const adminUi = useAdminUiStore()
const route = useRoute()
const router = useRouter()

/** Shown under the name in the sidebar's profile card — the first role is enough context; a user rarely holds more than one in practice. */
const primaryRoleName = computed(() => auth.user?.roles?.[0]?.name ?? null)

const accountMenuOpen = ref(false)
const editProfileOpen = ref(false)
const changePasswordOpen = ref(false)

async function handleLogout() {
  await auth.logout()
  await router.push('/login')
}

const visibleGroups = computed(() =>
  adminNav
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => {
        if (item.superAdminOnly && !auth.isSuperAdmin) return false
        if (item.permission && !auth.can(item.permission)) return false
        return true
      }),
    }))
    .filter((group) => group.items.length > 0),
)

const STORAGE_KEY = 'ntcsweb.admin.sidebar.expanded'

function loadStoredExpanded(): string[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? (JSON.parse(raw) as string[]) : []
  } catch {
    return []
  }
}

function groupContainingCurrentRoute(): string | undefined {
  return adminNav.find((group) => group.items.some((item) => item.to === route.path))?.labelKey
}

// A returning visitor's manual choices win; a first-time visitor instead
// gets the group containing wherever they landed pre-expanded, so the
// current page is never hidden inside a collapsed section on first load.
const stored = loadStoredExpanded()
const expanded = reactive<Record<string, boolean>>(
  Object.fromEntries(
    adminNav.map((group) => [
      group.labelKey,
      stored.length > 0 ? stored.includes(group.labelKey) : group.labelKey === groupContainingCurrentRoute(),
    ]),
  ),
)

function persist(): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(Object.keys(expanded).filter((key) => expanded[key])))
  } catch {
    // Best-effort — a private window or full storage just means the choice
    // doesn't survive a reload, not a broken sidebar.
  }
}

function toggleGroup(labelKey: string): void {
  expanded[labelKey] = !expanded[labelKey]
  persist()
}

// Navigating to a page (e.g. via a dashboard shortcut, not the sidebar
// itself) should reveal its group without collapsing whatever else the user
// already had open.
watch(
  () => route.path,
  () => {
    const key = groupContainingCurrentRoute()
    if (key && !expanded[key]) {
      expanded[key] = true
      persist()
    }
  },
)
</script>

<template>
  <!-- Mobile overlay -->
  <div v-if="open" class="fixed inset-0 z-30 bg-neutral-900/50 lg:hidden" @click="emit('close')" />

  <aside
    class="fixed inset-y-0 left-0 z-40 w-64 transform overflow-y-auto border-r border-neutral-200 bg-white transition-[transform,width] lg:translate-x-0"
    :class="[open ? 'translate-x-0' : '-translate-x-full', adminUi.sidebarCollapsed ? 'lg:w-16' : 'lg:w-64']"
  >
    <!-- A profile card, not a static app logo — the signed-in user's own
         picture, name, and role. Collapses down to just the small avatar
         (matching the old logo badge's footprint) when the sidebar is
         collapsed, same as every nav label below it. Click opens the same
         account menu (Edit Profile / Change Password / Sign out) that used
         to live in AdminHeader.vue. -->
    <div class="relative border-b border-neutral-200">
      <button
        type="button"
        class="flex w-full flex-col items-center gap-3 px-5 py-6 text-center hover:bg-neutral-50"
        :class="adminUi.sidebarCollapsed ? 'lg:h-16 lg:flex-row lg:justify-center lg:gap-0 lg:px-0 lg:py-0' : ''"
        :aria-expanded="accountMenuOpen"
        @click="accountMenuOpen = !accountMenuOpen"
      >
        <span
          class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-primary-100 text-xl font-semibold text-primary-800"
          :class="adminUi.sidebarCollapsed ? 'lg:h-8 lg:w-8 lg:text-sm' : ''"
        >
          <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" alt="" class="h-full w-full object-cover" />
          <template v-else>{{ auth.user?.name.charAt(0) ?? '?' }}</template>
        </span>
        <div :class="adminUi.sidebarCollapsed ? 'lg:hidden' : ''">
          <p class="font-bold text-neutral-900">{{ auth.user?.name }}</p>
          <p v-if="primaryRoleName" class="text-sm text-neutral-500">{{ primaryRoleName }}</p>
        </div>
      </button>

      <!-- Click-outside-to-close overlay — see LanguageSwitcher.vue. -->
      <div v-if="accountMenuOpen" class="fixed inset-0 z-40" @click="accountMenuOpen = false" />

      <Transition
        enter-active-class="transition ease-out duration-100"
        enter-from-class="opacity-0 scale-95"
        enter-to-class="opacity-100 scale-100"
      >
        <div
          v-if="accountMenuOpen"
          class="absolute z-50 inset-x-3 top-full mt-2 w-64 rounded-lg border border-neutral-200 bg-white py-1 shadow-lg"
          :class="adminUi.sidebarCollapsed ? 'lg:inset-x-auto lg:left-full lg:top-0 lg:ml-2 lg:mt-0' : ''"
        >
          <div class="flex items-center gap-3 border-b border-neutral-100 px-4 py-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-primary-100 text-base font-semibold text-primary-800">
              <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" alt="" class="h-full w-full object-cover" />
              <template v-else>{{ auth.user?.name.charAt(0) ?? '?' }}</template>
            </span>
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-neutral-900">{{ auth.user?.name }}</p>
              <p v-if="auth.user?.email" class="truncate text-xs text-neutral-500">{{ auth.user.email }}</p>
              <p v-if="auth.user?.phone" class="truncate text-xs text-neutral-500">{{ auth.user.phone }}</p>
            </div>
          </div>

          <button
            type="button"
            class="block w-full px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
            @click="
              accountMenuOpen = false;
              editProfileOpen = true
            "
          >
            {{ t('common.editProfile') }}
          </button>
          <button
            type="button"
            class="block w-full px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50"
            @click="
              accountMenuOpen = false;
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
    </div>

    <!-- Desktop-only whole-sidebar collapse — mobile uses the overlay
         drawer (the `open` prop) instead, toggled from AdminHeader. -->
    <button
      type="button"
      class="hidden w-full items-center justify-center border-b border-neutral-100 py-2 text-neutral-400 hover:bg-neutral-50 hover:text-neutral-600 lg:flex"
      :aria-label="t(adminUi.sidebarCollapsed ? 'common.expandSidebar' : 'common.collapseSidebar')"
      @click="adminUi.toggleSidebarCollapsed()"
    >
      <svg
        class="h-4 w-4 transition-transform duration-200"
        :class="adminUi.sidebarCollapsed ? 'rotate-180' : ''"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        stroke-width="2"
      >
        <path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
      </svg>
    </button>

    <nav class="space-y-1 px-3 py-5" :class="adminUi.sidebarCollapsed ? 'lg:hidden' : ''">
      <div v-for="group in visibleGroups" :key="group.labelKey" class="border-b border-neutral-100 pb-1 last:border-0">
        <button
          type="button"
          class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-base font-bold text-primary-800 hover:bg-primary-50"
          :aria-expanded="expanded[group.labelKey]"
          @click="toggleGroup(group.labelKey)"
        >
          {{ t(group.labelKey) }}
          <svg
            class="h-4 w-4 shrink-0 transition-transform duration-200"
            :class="expanded[group.labelKey] ? 'rotate-180' : ''"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2.5"
          >
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
          </svg>
        </button>

        <div
          class="grid transition-[grid-template-rows] duration-200 ease-in-out"
          :class="expanded[group.labelKey] ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
        >
          <div class="overflow-hidden">
            <div class="mt-1 space-y-0.5 pb-2">
              <RouterLink
                v-for="item in group.items"
                :key="item.to"
                :to="item.to"
                class="block rounded-lg px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
                active-class="bg-primary-50 text-primary-800"
                @click="emit('close')"
              >
                {{ t(item.labelKey) }}
              </RouterLink>
            </div>
          </div>
        </div>
      </div>
    </nav>
  </aside>

  <EditProfileModal v-model="editProfileOpen" />
  <ChangePasswordModal v-model="changePasswordOpen" />
</template>
