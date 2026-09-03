<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService } from '@/services/publicContent'
import { useSiteStore } from '@/stores/site'
import { ApiRequestError } from '@/types/api'

const site = useSiteStore()
const { t } = useI18n()

const form = reactive({ name: '', email: '', subject: '', message: '' })
const errors = ref<Record<string, string[]>>({})
const submitting = ref(false)
const status = ref<'idle' | 'success' | 'error'>('idle')

/** The phone field can hold multiple numbers (e.g. one per carrier), one per line. */
const phoneLines = computed(() => (site.info.phone ?? '').split('\n').map((line) => line.trim()).filter(Boolean))

// Pinned to the school's actual pin (resolved from https://maps.app.goo.gl/mHJskseND1TH38zL6),
// not derived from the address text — a short link can't be embedded directly.
const mapSrc = 'https://www.google.com/maps?q=11.666201,104.812662&z=17&output=embed'

const telegramHandle = 'sarothpouv'
const telegramUrl = `https://t.me/${telegramHandle}`
const telegramQrSrc = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(telegramUrl)}`

async function submit() {
  submitting.value = true
  status.value = 'idle'
  errors.value = {}

  try {
    await publicContentService.submitContactMessage(form)
    status.value = 'success'
    Object.assign(form, { name: '', email: '', subject: '', message: '' })
  } catch (error) {
    status.value = 'error'
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div>
    <SectionContainer>
      <div class="grid gap-10 lg:grid-cols-2">
        <div>
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('contact.mapTitle') }}</h2>
          <div class="mt-4 overflow-hidden rounded-[--radius-card] border border-neutral-200 bg-neutral-100">
            <iframe
              :src="mapSrc"
              class="h-64 w-full sm:h-80"
              style="border: 0"
              loading="lazy"
              referrerpolicy="no-referrer-when-downgrade"
              :title="t('contact.mapTitle')"
            />
          </div>

          <h2 class="mt-10 text-lg font-semibold text-neutral-900">{{ t('contact.schoolInfo') }}</h2>
          <dl class="mt-4 space-y-3 text-sm text-neutral-600">
            <div v-if="site.info.address">
              <dt class="font-medium text-neutral-800">{{ t('contact.address') }}</dt>
              <dd>{{ site.info.address }}</dd>
            </div>
            <div v-if="phoneLines.length > 0">
              <dt class="font-medium text-neutral-800">{{ t('contact.phone') }}</dt>
              <dd v-for="line in phoneLines" :key="line">{{ line }}</dd>
            </div>
            <div v-if="site.info.email">
              <dt class="font-medium text-neutral-800">{{ t('contact.email') }}</dt>
              <dd>{{ site.info.email }}</dd>
            </div>
          </dl>

          <a :href="telegramUrl" target="_blank" rel="noopener" class="mt-6 inline-flex flex-col items-start gap-2">
            <span class="text-sm font-medium text-neutral-800">{{ t('contact.telegram') }}</span>
            <img :src="telegramQrSrc" :alt="`Telegram @${telegramHandle}`" width="140" height="140" class="rounded-[--radius-card] border border-neutral-200" />
            <span class="text-xs text-neutral-500">@{{ telegramHandle }}</span>
          </a>
        </div>

        <div>
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('contact.keepInTouch') }}</h2>
          <form class="mt-4 space-y-4" @submit.prevent="submit">
            <BaseAlert v-if="status === 'success'" variant="success">
              {{ t('contact.successMessage') }}
            </BaseAlert>
            <BaseAlert v-else-if="status === 'error' && Object.keys(errors).length === 0" variant="danger">
              {{ t('contact.errorMessage') }}
            </BaseAlert>

            <BaseInput v-model="form.name" :label="t('contact.formName')" required :error="errors.name?.[0]" />
            <BaseInput v-model="form.email" type="email" :label="t('contact.formEmail')" required :error="errors.email?.[0]" />
            <BaseInput v-model="form.subject" :label="t('contact.formSubject')" required :error="errors.subject?.[0]" />

            <div>
              <label for="message" class="mb-1.5 block text-sm font-medium text-neutral-700">
                {{ t('contact.formMessage') }} <span class="text-danger-600">*</span>
              </label>
              <textarea
                id="message"
                v-model="form.message"
                rows="5"
                required
                class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
              />
              <p v-if="errors.message?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.message[0] }}</p>
            </div>

            <BaseButton type="submit" :loading="submitting" block>{{ t('contact.submit') }}</BaseButton>
          </form>
        </div>
      </div>
    </SectionContainer>
  </div>
</template>
