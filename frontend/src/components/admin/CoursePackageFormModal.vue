<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { academicProgramsService, type AcademicProgram } from '@/services/academicPrograms'
import { type Book, booksService } from '@/services/books'
import { coursePackagesService, type CoursePackage } from '@/services/coursePackages'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  coursePackage?: CoursePackage | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.coursePackage != null)

const form = reactive({
  code: '',
  name: '',
  academic_program_id: null as number | null,
  description: '',
  fee_monthly: null as number | null,
  fee_term: null as number | null,
  fee_video: null as number | null,
  fee_monthly_online: null as number | null,
  fee_term_online: null as number | null,
  currency: 'USD' as 'USD' | 'KHR',
  duration: '',
  is_active: true,
  show_on_website: false,
  show_in_popular: false,
  book_ids: [] as number[],
})

const hasAnyFee = computed(
  () =>
    form.fee_monthly !== null ||
    form.fee_term !== null ||
    form.fee_video !== null ||
    form.fee_monthly_online !== null ||
    form.fee_term_online !== null,
)

const currencyOptions = computed(() => [
  { value: 'USD', label: t('admin.coursePackages.currencyUsd') },
  { value: 'KHR', label: t('admin.coursePackages.currencyKhr') },
])

const programs = ref<AcademicProgram[]>([])
const books = ref<Book[]>([])
const thumbnailFile = ref<File | null>(null)
const thumbnailPreview = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const programOptions = computed(() => programs.value.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` })))

// Only books tagged to the chosen academic program make sense in this
// package's menu — mirrors the server's own "package must belong to the
// class's program" rule (EnrollmentService::assertEnrollable()).
const availableBooks = computed(() =>
  form.academic_program_id === null ? [] : books.value.filter((b) => b.academic_program?.id === form.academic_program_id),
)

function toggleBook(bookId: number, checked: boolean) {
  if (checked) {
    if (!form.book_ids.includes(bookId)) form.book_ids.push(bookId)
  } else {
    form.book_ids = form.book_ids.filter((id) => id !== bookId)
  }
}

onMounted(async () => {
  ;[programs.value, books.value] = await Promise.all([academicProgramsService.listAll(), booksService.listAll()])
})

// Changing the program invalidates whatever books were picked for the
// previous one — the menu only makes sense within one program. Skipped
// while the form is first populated for editing (the watch below sets both
// academic_program_id and book_ids together).
let hydrating = false

watch(
  () => form.academic_program_id,
  () => {
    if (hydrating) return
    form.book_ids = form.book_ids.filter((id) => availableBooks.value.some((b) => b.id === id))
  },
)

watch(
  () => [props.modelValue, props.coursePackage] as const,
  ([open]) => {
    if (!open) return

    hydrating = true
    form.code = props.coursePackage?.code ?? ''
    form.name = props.coursePackage?.name ?? ''
    form.academic_program_id = props.coursePackage?.academic_program_id ?? null
    form.description = props.coursePackage?.description ?? ''
    form.fee_monthly = props.coursePackage?.fee_monthly ?? null
    form.fee_term = props.coursePackage?.fee_term ?? null
    form.fee_video = props.coursePackage?.fee_video ?? null
    form.fee_monthly_online = props.coursePackage?.fee_monthly_online ?? null
    form.fee_term_online = props.coursePackage?.fee_term_online ?? null
    form.currency = props.coursePackage?.currency ?? 'USD'
    form.duration = props.coursePackage?.duration ?? ''
    form.is_active = props.coursePackage?.is_active ?? true
    form.show_on_website = props.coursePackage?.show_on_website ?? false
    form.show_in_popular = props.coursePackage?.show_in_popular ?? false
    form.book_ids = props.coursePackage?.books?.map((b) => b.id) ?? []
    thumbnailFile.value = null
    thumbnailPreview.value = props.coursePackage?.thumbnail_url ?? null
    errors.value = {}
    generalError.value = null
    hydrating = false
  },
  { immediate: true },
)

function onThumbnailChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  thumbnailFile.value = file
  thumbnailPreview.value = URL.createObjectURL(file)
}

async function submit() {
  if (form.academic_program_id === null || !hasAnyFee.value) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const input = { ...form, academic_program_id: form.academic_program_id, thumbnail: thumbnailFile.value ?? undefined }

    if (isEditing.value) {
      await coursePackagesService.update(props.coursePackage!.id, input)
    } else {
      await coursePackagesService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.coursePackages.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.coursePackages.editTitle') : t('admin.coursePackages.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <BaseAlert v-if="isEditing" variant="info">{{ t('admin.coursePackages.priceChangeWarning') }}</BaseAlert>

      <BaseInput v-model="form.code" required :label="t('admin.coursePackages.code')" :error="errors.code?.[0]" />
      <BaseInput v-model="form.name" required :label="t('admin.coursePackages.name')" :error="errors.name?.[0]" />

      <BaseSelect
        :model-value="form.academic_program_id !== null ? String(form.academic_program_id) : ''"
        :options="programOptions"
        required
        :placeholder="t('admin.coursePackages.selectProgram')"
        :label="t('admin.coursePackages.program')"
        :error="errors.academic_program_id?.[0]"
        @update:model-value="form.academic_program_id = $event ? Number($event) : null"
      />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">
          {{ t('admin.coursePackages.fees') }} <span class="text-danger-600">*</span>
        </label>
        <p class="mb-2 text-xs text-neutral-500">{{ t('admin.coursePackages.feesHint') }}</p>
        <div class="grid grid-cols-2 gap-4">
          <BaseInput
            :model-value="form.fee_monthly !== null ? String(form.fee_monthly) : ''"
            type="number"
            step="0.01"
            :label="t('admin.coursePackages.feeMonthly')"
            :error="errors.fee_monthly?.[0]"
            @update:model-value="form.fee_monthly = $event ? Number($event) : null"
          />
          <BaseInput
            :model-value="form.fee_term !== null ? String(form.fee_term) : ''"
            type="number"
            step="0.01"
            :label="t('admin.coursePackages.feeTerm')"
            :error="errors.fee_term?.[0]"
            @update:model-value="form.fee_term = $event ? Number($event) : null"
          />
          <BaseInput
            :model-value="form.fee_video !== null ? String(form.fee_video) : ''"
            type="number"
            step="0.01"
            :label="t('admin.coursePackages.feeVideo')"
            :error="errors.fee_video?.[0]"
            @update:model-value="form.fee_video = $event ? Number($event) : null"
          />
          <BaseInput
            :model-value="form.fee_monthly_online !== null ? String(form.fee_monthly_online) : ''"
            type="number"
            step="0.01"
            :label="t('admin.coursePackages.feeMonthlyOnline')"
            :error="errors.fee_monthly_online?.[0]"
            @update:model-value="form.fee_monthly_online = $event ? Number($event) : null"
          />
          <BaseInput
            :model-value="form.fee_term_online !== null ? String(form.fee_term_online) : ''"
            type="number"
            step="0.01"
            :label="t('admin.coursePackages.feeTermOnline')"
            :error="errors.fee_term_online?.[0]"
            @update:model-value="form.fee_term_online = $event ? Number($event) : null"
          />
        </div>
        <p v-if="!hasAnyFee" class="mt-1.5 text-sm text-danger-600">{{ t('admin.coursePackages.atLeastOneFeeRequired') }}</p>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <BaseSelect
          v-model="form.currency"
          :options="currencyOptions"
          required
          :label="t('admin.coursePackages.currency')"
          :error="errors.currency?.[0]"
        />
        <BaseInput v-model="form.duration" :label="t('admin.coursePackages.duration')" :error="errors.duration?.[0]" />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.coursePackages.thumbnail') }}</label>
        <p class="mb-2 text-xs text-neutral-500">{{ t('admin.coursePackages.thumbnailHint') }}</p>

        <div
          v-if="thumbnailPreview"
          class="mb-3 aspect-video w-full max-w-xs overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100"
        >
          <img :src="thumbnailPreview" alt="" class="h-full w-full object-cover" />
        </div>

        <input
          type="file"
          accept="image/jpeg,image/png,image/webp,image/gif"
          class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
          @change="onThumbnailChange"
        />
        <p v-if="errors.thumbnail?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.thumbnail[0] }}</p>
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.coursePackages.description') }}</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">
          {{ t('admin.coursePackages.books') }} <span class="text-danger-600">*</span>
        </label>
        <p class="mb-2 text-xs text-neutral-500">{{ t('admin.coursePackages.booksHint') }}</p>
        <p v-if="form.academic_program_id === null" class="text-sm text-neutral-500">{{ t('admin.coursePackages.pickProgramFirst') }}</p>
        <p v-else-if="availableBooks.length === 0" class="text-sm text-neutral-500">{{ t('admin.coursePackages.noBooksAvailable') }}</p>
        <div v-else class="grid max-h-56 grid-cols-2 gap-2 overflow-y-auto pr-1">
          <label v-for="book in availableBooks" :key="book.id" class="flex items-center gap-2 rounded-lg border border-neutral-200 p-2.5 text-sm">
            <input
              type="checkbox"
              :checked="form.book_ids.includes(book.id)"
              class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              @change="toggleBook(book.id, ($event.target as HTMLInputElement).checked)"
            />
            <span class="flex-1 text-neutral-700">{{ book.title }}</span>
          </label>
        </div>
        <p v-if="errors.book_ids?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.book_ids[0] }}</p>
      </div>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.coursePackages.statusActive') }}
      </label>

      <div>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.show_on_website" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.coursePackages.showOnWebsite') }}
        </label>
        <p class="mt-1 text-xs text-neutral-500">{{ t('admin.coursePackages.showOnWebsiteHint') }}</p>
      </div>

      <div>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.show_in_popular" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.coursePackages.showInPopular') }}
        </label>
        <p class="mt-1 text-xs text-neutral-500">{{ t('admin.coursePackages.showInPopularHint') }}</p>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="form.academic_program_id === null || !hasAnyFee || form.book_ids.length === 0" @click="submit">
        {{ t('common.save') }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
