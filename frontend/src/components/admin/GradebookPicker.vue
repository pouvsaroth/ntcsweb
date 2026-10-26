<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseSelect from '@/components/ui/BaseSelect.vue'
import { academicYearsService, type AcademicYear } from '@/services/academicYears'
import { gradebookService, type GradebookClass, type GradebookPick } from '@/services/gradebook'
import { academicTermsService, type AcademicTerm } from '@/services/schoolSetup'

/**
 * Year → term → class (→ subject) for the gradebook tabs
 * (docs/academics/gradebook.md). The choice lives in the address
 * (?year=&term=&class=&subject=), so Grade Validation can link straight into
 * Grade Entry and a reload keeps the place. Classes are only those this
 * account may open (GET /gradebook/classes).
 */
const props = withDefaults(defineProps<{ withSubject?: boolean; classOptional?: boolean; noTerm?: boolean }>(), { withSubject: false, classOptional: false, noTerm: false })

const emit = defineEmits<{ change: [pick: GradebookPick]; ready: [classes: GradebookClass[]] }>()

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const years = ref<AcademicYear[]>([])
const terms = ref<AcademicTerm[]>([])
const classes = ref<GradebookClass[]>([])
const year = ref('')
const term = ref('')
const klass = ref('')
const subject = ref('')

const q = (key: string) => (typeof route.query[key] === 'string' ? (route.query[key] as string) : '')

const yearOptions = computed(() => years.value.map((y) => ({ value: String(y.id), label: y.is_current ? `${y.name} (${t('gradebook.current')})` : y.name })))
const termOptions = computed(() => [
  ...(terms.value.length ? [] : [{ value: '', label: t('gradebook.picker.noTerms') }]),
  ...terms.value.map((tm) => ({ value: String(tm.id), label: tm.name })),
])
const classOptions = computed(() => [
  ...(props.classOptional ? [{ value: '', label: t('gradebook.allClasses') }] : classes.value.length ? [] : [{ value: '', label: t('gradebook.picker.noClasses') }]),
  ...classes.value.map((c) => ({ value: String(c.id), label: c.grade_level ? `${c.name} · ${c.grade_level.name}` : c.name })),
])
const selectedClass = computed(() => classes.value.find((c) => String(c.id) === klass.value) ?? null)
const subjectOptions = computed(() => [
  ...((selectedClass.value?.subjects.length ?? 0) ? [] : [{ value: '', label: t('gradebook.picker.noSubjects') }]),
  ...(selectedClass.value?.subjects ?? []).map((s) => ({ value: String(s.id), label: s.can_enter ? s.title : `${s.title} (${t('gradebook.picker.viewOnly')})` })),
])

function publish() {
  const query = { ...route.query, year: year.value || undefined, term: term.value || undefined, class: klass.value || undefined, subject: (props.withSubject && subject.value) || undefined }
  void router.replace({ query })
  emit('change', {
    yearId: year.value ? Number(year.value) : null,
    termId: term.value ? Number(term.value) : null,
    classId: klass.value ? Number(klass.value) : null,
    subjectId: subject.value ? Number(subject.value) : null,
    klass: selectedClass.value,
  })
}

async function loadYear(keep: { term?: string; klass?: string; subject?: string } = {}) {
  const yearId = year.value ? Number(year.value) : null
  ;[terms.value, classes.value] = await Promise.all([
    yearId ? academicTermsService.list(yearId).catch(() => []) : Promise.resolve([]),
    gradebookService.classes(yearId).catch(() => []),
  ])
  emit('ready', classes.value)
  term.value = terms.value.some((tm) => String(tm.id) === keep.term) ? keep.term! : terms.value[0] ? String(terms.value[0].id) : ''
  klass.value = classes.value.some((c) => String(c.id) === keep.klass) ? keep.klass! : props.classOptional ? '' : classes.value[0] ? String(classes.value[0].id) : ''
  pickSubject(keep.subject)
}

function pickSubject(wanted?: string) {
  const list = selectedClass.value?.subjects ?? []
  subject.value = list.some((s) => String(s.id) === wanted) ? wanted! : String(list.find((s) => s.can_enter)?.id ?? list[0]?.id ?? '')
}

async function onYear(v: string) {
  year.value = v
  await loadYear()
  publish()
}
function onTerm(v: string) {
  term.value = v
  publish()
}
function onClass(v: string) {
  klass.value = v
  pickSubject(subject.value)
  publish()
}
function onSubject(v: string) {
  subject.value = v
  publish()
}

watch(
  () => route.query,
  () => {
    // Followed a link inside the tab (e.g. Validation → Entry keeps the same component when only the query changes).
    if (q('class') && q('class') !== klass.value && classes.value.some((c) => String(c.id) === q('class'))) {
      klass.value = q('class')
      pickSubject(q('subject'))
      publish()
    }
  },
)

onMounted(async () => {
  years.value = await academicYearsService.listAll().catch(() => [])
  const wanted = q('year')
  year.value = years.value.some((y) => String(y.id) === wanted) ? wanted : String((years.value.find((y) => y.is_current) ?? years.value[0])?.id ?? '')
  await loadYear({ term: q('term'), klass: q('class'), subject: q('subject') })
  publish()
})
</script>

<template>
  <div class="grid gap-2 sm:grid-cols-2" :class="withSubject ? 'lg:grid-cols-4' : noTerm ? '' : 'lg:grid-cols-3'">
    <BaseSelect :model-value="year" :options="yearOptions" :label="t('gradebook.academicYear')" @update:model-value="onYear" />
    <BaseSelect v-if="!noTerm" :model-value="term" :options="termOptions" :label="t('gradebook.term')" @update:model-value="onTerm" />
    <BaseSelect :model-value="klass" :options="classOptions" :label="t('gradebook.class')" @update:model-value="onClass" />
    <BaseSelect v-if="withSubject" :model-value="subject" :options="subjectOptions" :label="t('gradebook.subject')" @update:model-value="onSubject" />
  </div>
</template>
