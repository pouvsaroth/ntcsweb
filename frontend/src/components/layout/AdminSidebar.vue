<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import ChangePasswordModal from '@/components/layout/ChangePasswordModal.vue'
import EditProfileModal from '@/components/layout/EditProfileModal.vue'
import { adminNav, isNavItemVisible } from '@/router/adminNav'
import { useAdminUiStore } from '@/stores/adminUi'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'

const { t } = useI18n()

defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: [] }>()

const auth = useAuthStore()
const adminUi = useAdminUiStore()
const site = useSiteStore()
const route = useRoute()
const router = useRouter()

/** Shown under the name in the sidebar's profile card — the first role is enough context; a user rarely holds more than one in practice. */
const primaryRoleName = computed(() => auth.user?.roles?.[0]?.name ?? null)

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
      // An external document link (`urlKey`) is filtered out entirely when
      // the school hasn't uploaded that file yet — same as the old
      // PublicUserMenu.vue rendering, just moved here.
      items: group.items.filter((item) => isNavItemVisible(item, auth) && (!item.urlKey || site.info.documents[item.urlKey])),
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
  <div v-if="open" class="fixed inset-0 z-50 bg-neutral-900/50 lg:hidden" @click="emit('close')" />

  <aside
    class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col transform border-r border-neutral-200 bg-white transition-[transform,width] lg:translate-x-0"
    :class="[open ? 'translate-x-0' : '-translate-x-full', adminUi.sidebarCollapsed ? 'lg:w-16' : 'lg:w-64']"
  >
    <!-- A profile card, not a static app logo — the signed-in user's own
         picture, name, and role. Collapses down to just the small avatar
         (matching the old logo badge's footprint) when the sidebar is
         collapsed, same as every nav label below it. No longer a dropdown
         trigger — Edit Profile/Change Password now live as plain items in
         the nav below, and Sign Out is its own sticky footer. -->
    <div
      class="flex shrink-0 flex-col items-center gap-3 border-b border-neutral-200 px-5 py-6 text-center"
      :class="adminUi.sidebarCollapsed ? 'lg:h-16 lg:flex-row lg:justify-center lg:gap-0 lg:px-0 lg:py-0' : ''"
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
    </div>

    <!-- Desktop-only whole-sidebar collapse — mobile uses the overlay
         drawer (the `open` prop) instead, toggled from AdminHeader. -->
    <button
      type="button"
      class="hidden w-full shrink-0 items-center justify-center border-b border-neutral-100 py-2 text-neutral-400 hover:bg-neutral-50 hover:text-neutral-600 lg:flex"
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

    <!-- The only scrolling region — the profile card above and the Sign
         Out button below stay put regardless of how long this list gets. -->
    <div class="flex-1 overflow-y-auto">
      <nav class="space-y-1 px-3 py-5" :class="adminUi.sidebarCollapsed ? 'lg:hidden' : ''">
        <div class="border-b border-neutral-100 pb-1">
          <button
            type="button"
            class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
            @click="
              editProfileOpen = true;
              emit('close')
            "
          >
            {{ t('common.editProfile') }}
          </button>
          <button
            type="button"
            class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
            @click="
              changePasswordOpen = true;
              emit('close')
            "
          >
            {{ t('common.changePassword') }}
          </button>
        </div>

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
                <template v-for="item in group.items" :key="item.to ?? item.urlKey">
                  <a
                    v-if="item.urlKey"
                    :href="site.info.documents[item.urlKey] ?? undefined"
                    target="_blank"
                    rel="noopener"
                    class="block rounded-lg px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
                  >
                    {{ t(item.labelKey) }}
                  </a>
                  <RouterLink
                    v-else
                    :to="item.to!"
                    class="block rounded-lg px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
                    active-class="bg-primary-50 text-primary-800"
                    @click="emit('close')"
                  >
                    {{ t(item.labelKey) }}
                  </RouterLink>
                </template>
              </div>
            </div>
          </div>
        </div>
      </nav>
    </div>

    <!-- Sticky Sign Out — a footer outside the scrolling region above, so
         it's always reachable without hunting for it in a long nav list. -->
    <div class="shrink-0 border-t border-neutral-200 p-3">
      <button
        type="button"
        class="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-danger-600 hover:bg-danger-50"
        :class="adminUi.sidebarCollapsed ? 'lg:px-0' : ''"
        @click="handleLogout"
      >
        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6-9H6a2 2 0 00-2 2v14a2 2 0 002 2h7" />
        </svg>
        <span :class="adminUi.sidebarCollapsed ? 'lg:hidden' : ''">{{ t('common.signOut') }}</span>
      </button>
    </div>
  </aside>

  <EditProfileModal v-model="editProfileOpen" />
  <ChangePasswordModal v-model="changePasswordOpen" />
</template>
