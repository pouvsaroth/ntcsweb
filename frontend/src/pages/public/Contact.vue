<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import PageHero from '@/components/public/PageHero.vue'
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
    <PageHero :title="t('contact.title')" :subtitle="t('contact.subtitle')" />
    <SectionContainer>
      <div class="mx-auto grid max-w-4xl gap-10 lg:grid-cols-2">
        <div>
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('contact.schoolInfo') }}</h2>
          <dl class="mt-4 space-y-3 text-sm text-neutral-600">
            <div v-if="site.info.address"><dt class="font-medium text-neutral-800">{{ t('contact.address') }}</dt><dd>{{ site.info.address }}</dd></div>
            <div v-if="site.info.phone"><dt class="font-medium text-neutral-800">{{ t('contact.phone') }}</dt><dd>{{ site.info.phone }}</dd></div>
            <div v-if="site.info.email"><dt class="font-medium text-neutral-800">{{ t('contact.email') }}</dt><dd>{{ site.info.email }}</dd></div>
          </dl>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
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
    </SectionContainer>
  </div>
</template>
