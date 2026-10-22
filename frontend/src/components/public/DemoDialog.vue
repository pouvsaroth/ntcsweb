<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { demoService, forgetDemo, saveDemo, savedDemo, type DemoForm, type DemoRole, type SavedDemo } from '@/services/demo'
import { ApiRequestError } from '@/types/api'

/**
 * "Try the demo" (docs/backoffice/stage-12-demo.md): name, optional phone
 * and email, a role — then the browser goes to the system, signed in.
 * A demo started earlier in this browser can be continued instead.
 */
const open = defineModel<boolean>({ required: true })

const { t, locale } = useI18n()

const roles: { value: DemoRole; icon: string }[] = [
  { value: 'director', icon: '🏫' },
  { value: 'teacher', icon: '👩‍🏫' },
  { value: 'student', icon: '🎓' },
]

const blank = (): DemoForm => ({ name: '', phone: '', email: '', role: 'director', locale: locale.value, website: '' })
const form = ref<DemoForm>(blank())
const saved = ref<SavedDemo | null>(null)
const startingNew = ref(false)
const busy = ref(false)
const error = ref<ApiRequestError | null>(null)
const nameMissing = ref(false)

watch(open, (isOpen) => {
  if (!isOpen) return
  form.value = blank()
  saved.value = savedDemo()
  startingNew.value = false
  error.value = null
  nameMissing.value = false
})

const showContinue = computed(() => saved.value !== null && !startingNew.value)
const fieldError = (f: string) => (f === 'name' && nameMissing.value ? t('demo.nameRequired') : error.value?.fieldError(f))
const generalError = computed(() => {
  if (!error.value) return null
  const fields = ['name', 'phone', 'email', 'role']
  return fields.some((f) => error.value?.fieldError(f)) ? null : (error.value.fieldError('demo') ?? error.value.message)
})

function go(url: string): void {
  window.location.href = url
}

async function submit(): Promise<void> {
  nameMissing.value = form.value.name.trim() === ''
  if (nameMissing.value) return
  busy.value = true
  error.value = null
  try {
    const demo = await demoService.start({ ...form.value, locale: locale.value })
    saveDemo(demo)
    go(demo.sign_in_url)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('demo.failed'), 0)
    busy.value = false
  }
}

async function resume(): Promise<void> {
  if (!saved.value) return
  busy.value = true
  error.value = null
  try {
    const demo = await demoService.resume(saved.value.token)
    go(demo.sign_in_url)
  } catch (e) {
    // Ended or gone: forget it and offer a new one.
    forgetDemo()
    saved.value = null
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('demo.failed'), 0)
    busy.value = false
  }
}
</script>

<template>
  <BaseModal v-model="open" :title="t('demo.title')" size="md">
    <!-- Continue the demo started earlier in this browser. -->
    <div v-if="showContinue && saved" class="space-y-4">
      <p class="text-sm text-neutral-600">{{ t('demo.continueText', { name: saved.name, role: t(`demo.role.${saved.role}`) }) }}</p>
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <BaseButton class="w-full" :loading="busy" @click="resume">{{ t('demo.continue') }}</BaseButton>
      <button type="button" class="w-full text-center text-sm text-secondary-700 hover:underline" :disabled="busy" @click="startingNew = true">
        {{ t('demo.startNew') }}
      </button>
    </div>

    <form v-else class="space-y-4" novalidate @submit.prevent="submit">
      <p class="text-sm text-neutral-600">{{ t('demo.intro') }}</p>
      <!-- Honeypot: hidden from people, filled only by bots. -->
      <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" class="absolute -left-[9999px] h-0 w-0 opacity-0" aria-hidden="true" />

      <BaseInput v-model="form.name" :label="t('demo.name')" :error="fieldError('name')" autocomplete="name" required />
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.phone" type="tel" :label="t('demo.phone')" :hint="t('demo.optional')" :error="fieldError('phone')" autocomplete="tel" />
        <BaseInput v-model="form.email" type="email" :label="t('demo.email')" :hint="t('demo.optional')" :error="fieldError('email')" autocomplete="email" />
      </div>

      <fieldset>
        <legend class="mb-2 block text-sm font-medium text-neutral-700">{{ t('demo.roleLabel') }}</legend>
        <div class="grid grid-cols-3 gap-2">
          <label
            v-for="r in roles"
            :key="r.value"
            class="flex cursor-pointer flex-col items-center gap-1 rounded-xl border p-3 text-center text-sm font-medium transition"
            :class="form.role === r.value ? 'border-primary-500 bg-primary-50 text-secondary-900 ring-2 ring-primary-500' : 'border-neutral-200 text-neutral-700 hover:bg-neutral-50'"
          >
            <input v-model="form.role" type="radio" name="role" :value="r.value" class="sr-only" />
            <span class="text-2xl" aria-hidden="true">{{ r.icon }}</span>
            <span>{{ t(`demo.role.${r.value}`) }}</span>
          </label>
        </div>
        <p class="mt-2 text-xs text-neutral-500">{{ t(`demo.roleHint.${form.role}`) }}</p>
        <p v-if="fieldError('role')" class="mt-1 text-sm text-danger-600">{{ fieldError('role') }}</p>
      </fieldset>

      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <BaseButton type="submit" class="w-full" :loading="busy">{{ t('demo.start') }}</BaseButton>
      <p class="text-center text-xs text-neutral-500">{{ t('demo.note') }}</p>
    </form>
  </BaseModal>
</template>
