<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import WebcamCaptureModal from '@/components/ui/WebcamCaptureModal.vue'
import { LOCALE_NAMES, SUPPORTED_LOCALES, setLocale, type Locale } from '@/i18n'
import { swapOrganizationText } from '@/i18n/orgWords'
import { authService } from '@/services/auth'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'
import { ApiRequestError } from '@/types/api'
import {
  ACCENT_PRESETS,
  SCHOOL_ACCENT,
  accentColor,
  clockFormat,
  themeMode,
  type ClockFormat,
  type ThemeMode,
  type UserPreferences,
} from '@/utils/appearance'

/**
 * Your own account, in cards: who you are (Profile), how the app looks
 * for you (Preferences) and your password (Security) — each with what it
 * is on the left and its fields on the right. Everyone can open it, students included: it only ever touches
 * the signed-in person (/auth/me, /auth/change-password).
 */
const { t, locale } = useI18n()
const auth = useAuthStore()
const site = useSiteStore()

const roleName = computed(() => {
  const name = auth.user?.roles?.[0]?.name ?? null
  const org = auth.tenantOrganization
  return name && org && org.code !== 'school' ? swapOrganizationText(name, 'en', org.words) : name
})
const initial = computed(() => (auth.user?.name ?? '?').trim().charAt(0).toUpperCase() || '?')

// --- Profile -------------------------------------------------------------
const profile = reactive({ name: auth.user?.name ?? '', phone: auth.user?.phone ?? '' })
const avatarFile = ref<File | null>(null)
const avatarPreview = ref<string | null>(auth.user?.avatar_url ?? null)
const profileErrors = ref<Record<string, string[]>>({})
const profileError = ref<string | null>(null)
const profileSaved = ref(false)
const savingProfile = ref(false)
const uploadInput = ref<HTMLInputElement | null>(null)
const webcamOpen = ref(false)

const profileDirty = computed(
  () => avatarFile.value !== null || profile.name.trim() !== (auth.user?.name ?? '') || profile.phone.trim() !== (auth.user?.phone ?? ''),
)

function onAvatarChange(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (file) setAvatar(file)
  input.value = '' // so picking the same file again still fires
}

function setAvatar(file: File) {
  avatarFile.value = file
  avatarPreview.value = URL.createObjectURL(file)
  profileSaved.value = false
}

async function saveProfile() {
  savingProfile.value = true
  profileErrors.value = {}
  profileError.value = null
  profileSaved.value = false
  try {
    await auth.updateProfile({ name: profile.name.trim(), phone: profile.phone.trim(), avatar: avatarFile.value })
    avatarFile.value = null
    avatarPreview.value = auth.user?.avatar_url ?? avatarPreview.value
    profileSaved.value = true
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) profileErrors.value = error.errors
    else profileError.value = error instanceof ApiRequestError ? error.message : t('common.editProfileFailed')
  } finally {
    savingProfile.value = false
  }
}

// --- Preferences ---------------------------------------------------------
// Each one applies at once and saves on its own (utils/appearance.ts).
const themes: { mode: ThemeMode; key: string }[] = [
  { mode: 'system', key: 'myAccount.themeSystem' },
  { mode: 'light', key: 'myAccount.themeLight' },
  { mode: 'dark', key: 'myAccount.themeDark' },
]
const accents: { hex: string | null; key: string; swatch: string }[] = [
  { hex: null, key: 'school', swatch: SCHOOL_ACCENT },
  ...ACCENT_PRESETS.map((a) => ({ hex: a.hex, key: a.key, swatch: a.hex })),
]
const clocks: { value: ClockFormat; sample: string; key: string }[] = [
  { value: '24', sample: '14:30', key: 'myAccount.clock24' },
  { value: '12', sample: '02:30 PM', key: 'myAccount.clock12' },
]
const prefsError = ref(false)

async function savePreference(prefs: UserPreferences) {
  prefsError.value = false
  try {
    await auth.updatePreferences(prefs)
  } catch {
    // Still applied here; only the account did not keep it.
    prefsError.value = true
  }
}

function chooseLanguage(code: Locale) {
  setLocale(code)
  void savePreference({ locale: code })
}

// --- Security ------------------------------------------------------------
const password = reactive({ current_password: '', password: '', password_confirmation: '' })
const passwordErrors = ref<Record<string, string[]>>({})
const passwordError = ref<string | null>(null)
const passwordSaved = ref(false)
const savingPassword = ref(false)

async function changePassword() {
  savingPassword.value = true
  passwordErrors.value = {}
  passwordError.value = null
  passwordSaved.value = false
  try {
    await authService.changePassword(password)
    Object.assign(password, { current_password: '', password: '', password_confirmation: '' })
    passwordSaved.value = true
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) passwordErrors.value = error.errors
    else passwordError.value = error instanceof ApiRequestError ? error.message : t('common.changePasswordFailed')
  } finally {
    savingPassword.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-5xl">
    <!-- Who you are -->
    <header class="mb-6 flex items-center gap-4">
      <span class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-primary-100 text-2xl font-semibold text-primary-800">
        <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" alt="" class="h-full w-full object-cover" />
        <template v-else>{{ initial }}</template>
      </span>
      <div class="min-w-0">
        <h1 class="truncate text-xl font-semibold text-neutral-900">{{ auth.user?.name }}</h1>
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-neutral-500">
          <span v-if="auth.user?.email" class="truncate">{{ auth.user.email }}</span>
          <span v-if="roleName" class="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-800">{{ roleName }}</span>
          <span v-if="site.resolved && site.info.name" class="truncate">{{ t('myAccount.ofOrg', { org: site.info.name }) }}</span>
        </p>
      </div>
    </header>

    <div class="space-y-4">
      <!-- Profile -->
      <section class="grid gap-4 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] sm:p-6 lg:grid-cols-3 lg:gap-8" aria-labelledby="acct-profile">
        <div>
          <h2 id="acct-profile" class="text-base font-semibold text-neutral-900">{{ t('myAccount.profileTitle') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('myAccount.profileLede') }}</p>
        </div>
        <form class="space-y-4 lg:col-span-2" novalidate @submit.prevent="saveProfile">
          <BaseAlert v-if="profileError" variant="danger">{{ profileError }}</BaseAlert>

          <div class="flex flex-wrap items-center gap-4">
            <span class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-full border border-neutral-200 bg-primary-100 text-2xl font-semibold text-primary-800">
              <img v-if="avatarPreview" :src="avatarPreview" alt="" class="h-full w-full object-cover" />
              <template v-else>{{ profile.name.trim().charAt(0).toUpperCase() || '?' }}</template>
            </span>
            <div>
              <p class="mb-2 text-sm font-medium text-neutral-700">{{ t('common.profilePicture') }}</p>
              <div class="flex flex-wrap gap-2">
                <BaseButton variant="outline" size="sm" @click="uploadInput?.click()">{{ t('common.uploadPhoto') }}</BaseButton>
                <BaseButton variant="outline" size="sm" @click="webcamOpen = true">{{ t('common.takePhoto') }}</BaseButton>
              </div>
              <p v-if="profileErrors.avatar?.[0]" class="mt-1.5 text-sm text-danger-600">{{ profileErrors.avatar[0] }}</p>
            </div>
            <input ref="uploadInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" @change="onAvatarChange" />
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <BaseInput v-model="profile.name" required autocomplete="name" :label="t('common.name')" :error="profileErrors.name?.[0]" @update:model-value="profileSaved = false" />
            <BaseInput v-model="profile.phone" required type="tel" autocomplete="tel" :label="t('common.phone')" :error="profileErrors.phone?.[0]" @update:model-value="profileSaved = false" />
          </div>

          <div v-if="auth.user?.email">
            <BaseInput :model-value="auth.user.email" disabled :label="t('myAccount.signInEmail')" :hint="t('myAccount.emailFixed')" />
          </div>

          <div class="flex flex-wrap items-center justify-end gap-3">
            <p v-if="profileSaved" class="text-sm text-success-600" role="status">{{ t('myAccount.saved') }}</p>
            <BaseButton type="submit" :disabled="!profileDirty" :loading="savingProfile">{{ t('myAccount.saveProfile') }}</BaseButton>
          </div>
        </form>
      </section>

      <!-- Preferences -->
      <section class="grid gap-4 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] sm:p-6 lg:grid-cols-3 lg:gap-8" aria-labelledby="acct-prefs">
        <div>
          <h2 id="acct-prefs" class="text-base font-semibold text-neutral-900">{{ t('myAccount.prefsTitle') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('myAccount.prefsLede') }}</p>
        </div>
        <div class="space-y-5 lg:col-span-2">
          <BaseAlert v-if="prefsError" variant="danger">{{ t('myAccount.prefsNotSaved') }}</BaseAlert>

          <!-- Language -->
          <div>
            <p id="acct-lang" class="mb-2 text-sm font-medium text-neutral-700">{{ t('common.language') }}</p>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-5" role="radiogroup" aria-labelledby="acct-lang">
              <button
                v-for="code in SUPPORTED_LOCALES"
                :key="code"
                type="button"
                role="radio"
                :aria-checked="locale === code"
                class="rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
                :class="locale === code ? 'border-primary-500 bg-primary-50 text-primary-800' : 'border-neutral-200 text-neutral-700 hover:bg-neutral-50'"
                :lang="code"
                @click="chooseLanguage(code)"
              >
                {{ LOCALE_NAMES[code] }}
              </button>
            </div>
          </div>

          <!-- Theme -->
          <div>
            <p id="acct-theme" class="mb-2 text-sm font-medium text-neutral-700">{{ t('myAccount.theme') }}</p>
            <div class="inline-flex flex-wrap rounded-lg border border-neutral-200 p-0.5" role="radiogroup" aria-labelledby="acct-theme">
              <button
                v-for="m in themes"
                :key="m.mode"
                type="button"
                role="radio"
                :aria-checked="themeMode === m.mode"
                class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                :class="themeMode === m.mode ? 'bg-primary-500 text-secondary-900' : 'text-neutral-600 hover:bg-neutral-100'"
                @click="savePreference({ theme: m.mode })"
              >
                <svg v-if="m.mode === 'system'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                  <rect x="3" y="4" width="18" height="12" rx="2" />
                  <path stroke-linecap="round" d="M8 20h8M12 16v4" />
                </svg>
                <svg v-else-if="m.mode === 'light'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                  <circle cx="12" cy="12" r="4" />
                  <path stroke-linecap="round" d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
                </svg>
                <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linejoin="round" d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" />
                </svg>
                {{ t(m.key) }}
              </button>
            </div>
          </div>

          <!-- Accent colour -->
          <div>
            <p id="acct-accent" class="mb-2 text-sm font-medium text-neutral-700">{{ t('myAccount.accent') }}</p>
            <div class="flex flex-wrap gap-2.5" role="radiogroup" aria-labelledby="acct-accent">
              <button
                v-for="a in accents"
                :key="a.key"
                type="button"
                role="radio"
                :aria-checked="accentColor === a.hex"
                :title="t(`myAccount.accents.${a.key}`)"
                :aria-label="t(`myAccount.accents.${a.key}`)"
                class="flex h-9 w-9 items-center justify-center rounded-full ring-offset-2 transition"
                :class="accentColor === a.hex ? 'ring-2 ring-neutral-700' : 'hover:ring-2 hover:ring-neutral-300'"
                :style="{ backgroundColor: a.swatch }"
                @click="savePreference({ accent: a.hex })"
              >
                <svg v-if="accentColor === a.hex" class="h-4 w-4 text-secondary-900" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m5 12 5 5 9-10" />
                </svg>
              </button>
            </div>
            <p class="mt-1.5 text-xs text-neutral-500">{{ t('myAccount.accentHint') }}</p>
          </div>

          <!-- Clock -->
          <div>
            <p id="acct-clock" class="mb-2 text-sm font-medium text-neutral-700">{{ t('myAccount.clock') }}</p>
            <div class="grid max-w-sm grid-cols-2 gap-2" role="radiogroup" aria-labelledby="acct-clock">
              <button
                v-for="c in clocks"
                :key="c.value"
                type="button"
                role="radio"
                :aria-checked="clockFormat === c.value"
                class="rounded-lg border px-3 py-2 text-left transition-colors"
                :class="clockFormat === c.value ? 'border-primary-500 bg-primary-50' : 'border-neutral-200 hover:bg-neutral-50'"
                @click="savePreference({ clock: c.value })"
              >
                <span class="block text-sm font-semibold text-neutral-900">{{ c.sample }}</span>
                <span class="block text-xs text-neutral-500">{{ t(c.key) }}</span>
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- Security -->
      <section class="grid gap-4 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] sm:p-6 lg:grid-cols-3 lg:gap-8" aria-labelledby="acct-security">
        <div>
          <h2 id="acct-security" class="text-base font-semibold text-neutral-900">{{ t('myAccount.securityTitle') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('myAccount.securityLede') }}</p>
        </div>
        <form class="space-y-4 lg:col-span-2" novalidate autocomplete="on" @submit.prevent="changePassword">
          <!-- For password managers: which account this password belongs to. -->
          <input type="text" name="username" autocomplete="username" :value="auth.user?.email ?? auth.user?.phone ?? ''" hidden />
          <BaseAlert v-if="passwordError" variant="danger">{{ passwordError }}</BaseAlert>
          <BaseAlert v-if="passwordSaved" variant="success">{{ t('common.changePasswordSuccess') }}</BaseAlert>

          <BaseInput
            v-model="password.current_password"
            type="password"
            required
            autocomplete="current-password"
            :label="t('common.currentPassword')"
            :error="passwordErrors.current_password?.[0]"
          />
          <div class="grid gap-4 sm:grid-cols-2">
            <BaseInput v-model="password.password" type="password" required autocomplete="new-password" :label="t('common.newPassword')" :error="passwordErrors.password?.[0]" />
            <BaseInput v-model="password.password_confirmation" type="password" required autocomplete="new-password" :label="t('common.confirmNewPassword')" />
          </div>

          <div class="flex justify-end">
            <BaseButton
              type="submit"
              :disabled="!password.current_password || !password.password || !password.password_confirmation"
              :loading="savingPassword"
            >
              {{ t('common.changePassword') }}
            </BaseButton>
          </div>
        </form>
      </section>
    </div>

    <WebcamCaptureModal v-model="webcamOpen" @captured="setAvatar" />
  </div>
</template>
