<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import StaffCardCanvas from '@/components/admin/StaffCardCanvas.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseMultiSelect from '@/components/ui/BaseMultiSelect.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { useCardKind } from '@/composables/useCardKind'
import { classesService, type SchoolClass } from '@/services/classes'
import { staffService, type Staff } from '@/services/staff'
import { staffCardDesignsService, type CardStudent, type StaffCardDesign } from '@/services/staffCardDesigns'
import { useAuthStore } from '@/stores/auth'
import { useImagePreviewStore } from '@/stores/imagePreview'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { genderLabel } from '@/utils/gender'
import { printImages } from '@/utils/printImage'
import { staffCardValues, studentCardValues, type CardValues } from '@/utils/staffCard'
import { autoSheet, renderCardImage, renderSheets, sheetLayout, type CardSide, type SheetSize } from '@/utils/staffCardRender'

/**
 * HRM > Staff cards / Students > Student cards > print: pick people and an
 * expiry date, then get each card as a PNG, or every card laid out on A4 / A5
 * sheet images to print and cut (backs mirrored for double-sided printing).
 * Staff are picked by department; students by class or a search.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const imagePreview = useImagePreviewStore()
const { isStudent, base, permission } = useCardKind()

const design = ref<StaffCardDesign | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

/** One person to print a card for, staff or student. */
interface Person {
  id: number
  code: string
  name: string
  staff?: Staff
  student?: CardStudent
}

// ---- Staff: everyone at once, filtered by department ---------------------------
const staff = ref<Staff[]>([])
const canSeeStaff = computed(() => auth.can('staff.view'))
const department = ref('')
const departments = computed(() => [...new Set(staff.value.map((s) => s.department?.name).filter((n): n is string => !!n))].sort())
const departmentOptions = computed(() => [{ value: '', label: t('admin.staffCards.print.allDepartments') }, ...departments.value.map((d) => ({ value: d, label: d }))])

// ---- Students: loaded by class or search; picked ones are kept across searches ---------
const classes = ref<SchoolClass[]>([])
const classId = ref('')
const classOptions = computed(() => [{ value: '', label: t('admin.staffCards.print.allClasses') }, ...classes.value.map((c) => ({ value: String(c.id), label: c.name }))])
const search = ref('')
const students = ref<CardStudent[]>([])
const knownStudents = new Map<number, CardStudent>()
const loadingStudents = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | undefined
async function loadStudents() {
  loadingStudents.value = true
  try {
    students.value = await staffCardDesignsService.students({ class_id: classId.value ? Number(classId.value) : undefined, search: search.value.trim() || undefined })
    students.value.forEach((s) => knownStudents.set(s.id, s))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.staffCards.loadFailed')
  } finally {
    loadingStudents.value = false
  }
}
watch(classId, () => void loadStudents())
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void loadStudents(), 350)
})

// ---- Who is shown / picked -----------------------------------------------------------
const toPerson = {
  staff: (s: Staff): Person => ({ id: s.id, code: s.employee_code, name: s.full_name, staff: s }),
  student: (s: CardStudent): Person => ({ id: s.id, code: s.student_code, name: s.full_name, student: s }),
}
const shown = computed<Person[]>(() =>
  isStudent.value
    ? students.value.map(toPerson.student)
    : (department.value ? staff.value.filter((s) => s.department?.name === department.value) : staff.value).map(toPerson.staff),
)
const selectedIds = ref<string[]>([])
const selected = computed<Person[]>(() => {
  if (isStudent.value) {
    // Keep each student's latest details; ones picked under another class stay picked.
    void students.value
    return selectedIds.value.map((id) => knownStudents.get(Number(id))).filter((s): s is CardStudent => !!s).map(toPerson.student)
  }
  const ids = new Set(selectedIds.value)
  return staff.value.filter((s) => ids.has(String(s.id))).map(toPerson.staff)
})
// The picker lists who is shown now plus anyone already picked.
const personOptions = computed(() => {
  const seen = new Set<number>()
  return [...selected.value, ...shown.value]
    .filter((p) => (seen.has(p.id) ? false : (seen.add(p.id), true)))
    .map((p) => ({ value: String(p.id), label: `${p.code} · ${p.name}` }))
})
function selectShown() {
  selectedIds.value = [...new Set([...selectedIds.value, ...shown.value.map((p) => String(p.id))])]
}

const nextYear = new Date()
nextYear.setFullYear(nextYear.getFullYear() + 1)
const pad = (n: number) => String(n).padStart(2, '0')
const expiry = ref(`${nextYear.getFullYear()}-${pad(nextYear.getMonth() + 1)}-${pad(nextYear.getDate())}`)

// A side with no photo and no fields would print as a blank page, so it's left out.
const hasSide = (side: CardSide) => !!design.value && (design.value[`${side}_fields`].length > 0 || !!design.value[`${side}_background_url`])
const twoSided = computed(() => hasSide('front') && hasSide('back'))
const sidesChoice = ref<'both' | 'front' | 'back'>('both')
const sides = computed<CardSide[]>(() => {
  if (!twoSided.value) return [hasSide('back') && !hasSide('front') ? 'back' : 'front']
  return sidesChoice.value === 'both' ? ['front', 'back'] : [sidesChoice.value]
})
const sideOptions = computed(() => (['both', 'front', 'back'] as const).map((v) => ({ value: v, label: t(`admin.staffCards.print.sides.${v}`) })))

const sheetChoice = ref<'auto' | SheetSize>('auto')
const sheet = computed<SheetSize>(() => (sheetChoice.value === 'auto' && design.value ? autoSheet(design.value.width_mm, design.value.height_mm) : sheetChoice.value === 'auto' ? 'A4' : sheetChoice.value))
const layout = computed(() => (design.value ? sheetLayout(sheet.value, design.value.width_mm, design.value.height_mm) : null))
const sheetOptions = computed(() => [
  { value: 'auto', label: t('admin.staffCards.print.sheetAuto') },
  { value: 'A4', label: 'A4' },
  { value: 'A5', label: 'A5' },
])
const pageCount = computed(() => (layout.value?.perPage ? Math.ceil(selected.value.length / layout.value.perPage) * sides.value.length : 0))

// ---- Card values ----------------------------------------------------------------------
function valuesFor(p: Person): CardValues {
  const common = {
    logoUrl: design.value?.school_logo_url ?? null,
    signatureUrl: design.value?.signature_url ?? null,
    expiry: expiry.value ? formatDate(expiry.value) : null,
    genderLabel: (g: string) => genderLabel(g),
    sample: {},
  }
  return p.student ? studentCardValues({ ...common, student: p.student }) : staffCardValues({ ...common, staff: p.staff ?? null })
}
const PREVIEW_LIMIT = 24
const previewPeople = computed(() => selected.value.slice(0, PREVIEW_LIMIT))
const previewScale = computed(() => (design.value ? Math.min(220 / design.value.width_mm, 160 / design.value.height_mm) : 2))

// ---- Output ------------------------------------------------------------------------
const busy = ref<string | null>(null)
const progress = ref<string | null>(null)
const filePrefix = computed(() => (isStudent.value ? 'student-card' : 'staff-card'))
const fileBase = (p: Person) => `${filePrefix.value}-${p.code}`.replace(/[^\w.-]+/g, '-')

async function cardImage(p: Person) {
  if (!design.value) return
  busy.value = `card-${p.id}`
  error.value = null
  try {
    const blob = await renderCardImage(design.value, valuesFor(p), sides.value)
    imagePreview.show({ title: `${p.code} · ${p.name}`, fileName: `${fileBase(p)}.png`, blob })
  } catch {
    error.value = t('admin.staffCards.print.imageFailed')
  } finally {
    busy.value = null
  }
}

interface SheetImage {
  url: string
  blob: Blob
  name: string
}
const sheets = ref<SheetImage[]>([])
const sheetPageSize = ref('A4 portrait')
function clearSheets() {
  sheets.value.forEach((s) => URL.revokeObjectURL(s.url))
  sheets.value = []
}
onBeforeUnmount(clearSheets)
// Anything changed: the old sheets no longer match.
watch([selectedIds, expiry, sidesChoice, sheetChoice], clearSheets)

async function makeSheets() {
  if (!design.value || !selected.value.length) return
  busy.value = 'sheets'
  error.value = null
  clearSheets()
  try {
    const { blobs, layout: used } = await renderSheets(design.value, selected.value.map(valuesFor), sides.value, sheet.value, (done, total) => {
      progress.value = t('admin.staffCards.print.progress', { done, total })
    })
    const date = new Date().toISOString().slice(0, 10)
    sheetPageSize.value = used.pageSize
    sheets.value = blobs.map((blob, i) => ({ blob, url: URL.createObjectURL(blob), name: `${filePrefix.value}s-${date}-${pad(i + 1)}.png` }))
  } catch (e) {
    error.value = e instanceof Error && e.message === 'card-too-big' ? t('admin.staffCards.print.tooBig', { size: sheet.value }) : t('admin.staffCards.print.imageFailed')
  } finally {
    busy.value = null
    progress.value = null
  }
}

function download(image: SheetImage) {
  const a = document.createElement('a')
  a.href = image.url
  a.download = image.name
  a.click()
}
async function downloadAll() {
  for (const image of sheets.value) {
    download(image)
    await new Promise((r) => setTimeout(r, 400))
  }
}
async function printSheets() {
  try {
    await printImages(
      sheets.value.map((s) => s.blob),
      design.value?.name ?? 'Cards',
      sheetPageSize.value,
    )
  } catch (e) {
    error.value = e instanceof Error ? e.message : t('admin.staffCards.print.imageFailed')
  }
}

onMounted(async () => {
  try {
    design.value = await staffCardDesignsService.get(Number(route.params.id))
    // A design opened under the other kind of card: go to its own page.
    if (design.value.card_for !== (isStudent.value ? 'student' : 'staff')) {
      await router.replace(`${design.value.card_for === 'student' ? '/admin/student-cards' : '/admin/staff-cards'}/${design.value.id}/print`)
      return
    }
    if (isStudent.value) {
      if (auth.can('classes.view')) classes.value = await classesService.listAll().catch(() => [])
      await loadStudents()
    } else if (canSeeStaff.value) {
      staff.value = await staffService.listAll()
    }
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.staffCards.loadFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <RouterLink :to="base" class="text-sm font-medium text-primary-700 hover:underline">← {{ t('admin.staffCards.backToList') }}</RouterLink>
      <RouterLink v-if="design && auth.can(permission('update'))" :to="`${base}/${design.id}/edit`" class="text-sm font-medium text-primary-700 hover:underline">{{ t('admin.staffCards.design') }}</RouterLink>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-16"><BaseSpinner /></div>
    <template v-else-if="design">
      <h2 class="mb-1 text-lg font-semibold text-neutral-900">{{ design.name }}</h2>
      <p class="mb-4 text-sm text-neutral-500">
        {{ t('admin.staffCards.sizeLine', { w: design.width_mm, h: design.height_mm }) }} · {{ isStudent ? t('admin.staffCards.print.studentSubtitle') : t('admin.staffCards.print.subtitle') }}
      </p>

      <BaseAlert v-if="!isStudent && !canSeeStaff" variant="warning" class="mb-4">{{ t('admin.staffCards.print.noStaffPermission') }}</BaseAlert>

      <!-- Who, expiry, sides, sheet -->
      <div class="mb-4 grid grid-cols-[minmax(0,1fr)] gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] md:grid-cols-2 xl:grid-cols-[14rem_minmax(0,1fr)_11rem_11rem_10rem] xl:items-end">
        <div v-if="isStudent" class="space-y-2">
          <BaseSelect v-if="classes.length" v-model="classId" :options="classOptions" :label="t('admin.staffCards.studentFieldTypes.class_name')" />
          <input
            v-model="search"
            type="search"
            :placeholder="t('admin.staffCards.print.searchStudents')"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          />
        </div>
        <BaseSelect v-else v-model="department" :options="departmentOptions" :label="t('admin.staffCards.fieldTypes.department')" />
        <div>
          <BaseMultiSelect
            v-model="selectedIds"
            :options="personOptions"
            :label="isStudent ? t('admin.staffCards.print.students') : t('admin.staffCards.print.staff')"
            :placeholder="isStudent ? t('admin.staffCards.print.pickStudents') : t('admin.staffCards.print.pickStaff')"
          />
          <div class="mt-1 flex items-center gap-3 text-xs">
            <button type="button" class="font-medium text-primary-700 hover:underline" @click="selectShown">{{ t('admin.staffCards.print.selectAll', { n: shown.length }) }}</button>
            <button v-if="selectedIds.length" type="button" class="font-medium text-neutral-500 hover:underline" @click="selectedIds = []">{{ t('admin.staffCards.print.clear') }}</button>
            <BaseSpinner v-if="loadingStudents" class="h-3.5 w-3.5" />
          </div>
        </div>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">{{ t('admin.staffCards.fieldTypes.expiry_date') }}</span>
          <DateInput v-model="expiry" />
        </label>
        <BaseSelect v-if="twoSided" v-model="sidesChoice" :options="sideOptions" :label="t('admin.staffCards.print.sidesLabel')" />
        <p v-else class="self-end pb-2 text-xs text-neutral-500">{{ t('admin.staffCards.print.oneSided') }}</p>
        <BaseSelect v-model="sheetChoice" :options="sheetOptions" :label="t('admin.staffCards.print.sheet')" />
      </div>

      <!-- Sheets -->
      <div class="mb-4 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="text-sm">
            <p class="font-medium text-neutral-900">{{ t('admin.staffCards.print.sheetsTitle') }}</p>
            <p v-if="layout && layout.perPage" class="text-neutral-500">
              {{ t('admin.staffCards.print.layoutLine', { size: layout.size, orientation: t(`admin.staffCards.print.${layout.landscape ? 'landscape' : 'portrait'}`), n: layout.perPage }) }}
              <template v-if="selected.length"> · {{ t('admin.staffCards.print.pages', { cards: selected.length, pages: pageCount }) }}</template>
            </p>
            <p v-else class="text-danger-600">{{ t('admin.staffCards.print.tooBig', { size: sheet }) }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <span v-if="progress" class="text-xs text-neutral-500">{{ progress }}</span>
            <BaseButton :disabled="!selected.length || !layout?.perPage" :loading="busy === 'sheets'" @click="makeSheets">{{ t('admin.staffCards.print.makeSheets') }}</BaseButton>
          </div>
        </div>
        <p v-if="sides.length === 2" class="mt-2 text-xs text-neutral-500">{{ t('admin.staffCards.print.duplexHint') }}</p>

        <div v-if="sheets.length" class="mt-4">
          <div class="mb-3 flex flex-wrap gap-2">
            <BaseButton variant="outline" size="sm" @click="downloadAll">{{ t('admin.staffCards.print.downloadAll', { n: sheets.length }) }}</BaseButton>
            <BaseButton variant="outline" size="sm" @click="printSheets">{{ t('admin.staffCards.print.print') }}</BaseButton>
          </div>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <div v-for="(image, i) in sheets" :key="image.url" class="rounded-lg border border-neutral-200 p-2">
              <img :src="image.url" alt="" class="w-full border border-neutral-100 bg-white" />
              <div class="mt-1.5 flex items-center justify-between gap-2 text-xs">
                <span class="text-neutral-500">{{ t('admin.staffCards.print.page', { n: i + 1 }) }}</span>
                <button type="button" class="font-medium text-primary-700 hover:underline" @click="download(image)">{{ t('admin.staffCards.print.download') }}</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Each card -->
      <p v-if="!selected.length" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ isStudent ? t('admin.staffCards.print.noStudentsSelected') : t('admin.staffCards.print.noneSelected') }}
      </p>
      <div v-else class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
        <div v-for="p in previewPeople" :key="p.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex flex-wrap justify-center gap-2 rounded-lg bg-neutral-100 p-2">
            <StaffCardCanvas
              v-for="side in sides"
              :key="side"
              :width-mm="design.width_mm"
              :height-mm="design.height_mm"
              :background="design[`${side}_background_url`]"
              :fields="design[`${side}_fields`]"
              :values="valuesFor(p)"
              :scale="previewScale"
            />
          </div>
          <div class="mt-2 flex items-center justify-between gap-2">
            <p class="min-w-0 truncate text-sm font-medium text-neutral-900">{{ p.code }} · {{ p.name }}</p>
            <BaseButton size="sm" variant="outline" :loading="busy === `card-${p.id}`" @click="cardImage(p)">{{ t('admin.staffCards.print.cardImage') }}</BaseButton>
          </div>
        </div>
      </div>
      <p v-if="selected.length > PREVIEW_LIMIT" class="mt-3 text-center text-xs text-neutral-500">{{ t('admin.staffCards.print.moreNotShown', { n: selected.length - PREVIEW_LIMIT }) }}</p>
    </template>
  </div>
</template>
