<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type Program } from '@/services/publicContent'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const programs = ref<Program[]>([])
const form = reactive({ name: '', phone: '', email: '', program_id: '', message: '' })
const errors = ref<Record<string, string[]>>({})
const submitting = ref(false)
const status = ref<'idle' | 'success' | 'error'>('idle')

const programOptions = computed(() => programs.value.map((program) => ({ value: String(program.id), label: program.title })))

onMounted(async () => {
  const result = await publicContentService.getPrograms()
  programs.value = result.data
})

async function submit() {
  submitting.value = true
  status.value = 'idle'
  errors.value = {}

  try {
    await publicContentService.submitEnrollmentInquiry(form)
    status.value = 'success'
    Object.assign(form, { name: '', phone: '', email: '', program_id: '', message: '' })
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
    <PageHero :title="t('register.title')" :subtitle="t('register.subtitle')" />
    <SectionContainer>
      <form class="mx-auto max-w-xl space-y-4" @submit.prevent="submit">
        <BaseAlert v-if="status === 'success'" variant="success">{{ t('register.successMessage') }}</BaseAlert>
        <BaseAlert v-else-if="status === 'error' && Object.keys(errors).length === 0" variant="danger">
          {{ t('register.errorMessage') }}
        </BaseAlert>

        <BaseInput v-model="form.name" :label="t('register.formName')" required :error="errors.name?.[0]" />
        <BaseInput v-model="form.phone" :label="t('register.formPhone')" required :error="errors.phone?.[0]" />
        <BaseInput v-model="form.email" type="email" :label="t('register.formEmail')" :error="errors.email?.[0]" />
        <BaseSelect
          v-model="form.program_id"
          :options="programOptions"
          :label="t('register.formProgram')"
          :placeholder="t('register.formProgramPlaceholder')"
          :error="errors.program_id?.[0]"
        />

        <div>
          <label for="message" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('register.formMessage') }}</label>
          <textarea
            id="message"
            v-model="form.message"
            rows="4"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          />
          <p v-if="errors.message?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.message[0] }}</p>
        </div>

        <BaseButton type="submit" :loading="submitting" block>{{ t('register.submit') }}</BaseButton>
      </form>
    </SectionContainer>
  </div>
</template>
