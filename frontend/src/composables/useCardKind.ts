import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { certificateValues, sampleCertificate } from '@/utils/certificate'
import type { CardFieldType, CardFor, TextFieldType } from '@/utils/staffCard'

/** Each kind's own URLs, route names and permission prefix. */
const KINDS: Record<CardFor, { base: string; routePrefix: string; permission: string }> = {
  staff: { base: '/admin/staff-cards', routePrefix: 'admin.staffCards', permission: 'staff-cards' },
  student: { base: '/admin/student-cards', routePrefix: 'admin.studentCards', permission: 'student-cards' },
  certificate: { base: '/admin/exams/certificate-designs', routePrefix: 'admin.certificateDesigns', permission: 'certificate-designs' },
}

/** A design's own pages, by its kind. */
export const cardBase = (cardFor: CardFor): string => KINDS[cardFor].base

/**
 * HRM > Staff cards, Students > Student cards and Examination > Certificate
 * designs share one list, designer and print page; the route's
 * `meta.cardFor` says which kind it is — its own URLs, permissions
 * (staff-cards.* / student-cards.* / certificate-designs.*) and field names.
 */
export function useCardKind() {
  const route = useRoute()
  const { t, te } = useI18n()

  const cardFor = computed<CardFor>(() => (route.meta.cardFor === 'student' || route.meta.cardFor === 'certificate' ? route.meta.cardFor : 'staff'))
  const isStudent = computed(() => cardFor.value === 'student')
  const isCertificate = computed(() => cardFor.value === 'certificate')
  const base = computed(() => KINDS[cardFor.value].base)
  const routePrefix = computed(() => KINDS[cardFor.value].routePrefix)
  const permission = (action: 'view' | 'create' | 'update' | 'delete') => `${KINDS[cardFor.value].permission}.${action}`

  /** A field's name; student cards and certificates name some differently (e.g. Name, not Name (English)). */
  function fieldLabel(type: CardFieldType): string {
    const own = isCertificate.value ? `admin.staffCards.certificateFieldTypes.${type}` : isStudent.value ? `admin.staffCards.studentFieldTypes.${type}` : null
    return own && te(own) ? t(own) : t(`admin.staffCards.fieldTypes.${type}`)
  }

  /** A label's key: certificates say "certificate" / "page" where cards say "card" (admin.staffCards.certificateText). */
  function textKey(name: string): string {
    const own = `admin.staffCards.certificateText.${name}`
    return isCertificate.value && te(own) ? own : `admin.staffCards.${name}`
  }

  /** Made-up details to design with before picking a real person. */
  const sample = computed<Partial<Record<TextFieldType, string>>>(() => {
    if (isCertificate.value) {
      const values = certificateValues(sampleCertificate())
      return Object.fromEntries(Object.entries(values).filter(([, v]) => typeof v === 'string')) as Partial<Record<TextFieldType, string>>
    }
    return isStudent.value
      ? {
          full_name: 'សុខ ដារា',
          english_name: 'Sok Dara',
          student_code: 'STU0001',
          class_name: 'English 1A',
          course: t('admin.staffCards.studentSample.course'),
          program: t('admin.staffCards.studentSample.program'),
          academic_year: '2026-2027',
          phone: '012 345 678',
          guardian_phone: '012 987 654',
          gender: t('admin.staffCards.genders.male'),
          date_of_birth: '15-04-2012',
          enrollment_date: '01-09-2026',
        }
      : {
          full_name: 'Sok Dara',
          other_name: 'សុខ ដារា',
          employee_code: 'ST0001',
          position: t('admin.staffCards.samplePosition'),
          department: t('admin.staffCards.sampleDepartment'),
          phone: '012 345 678',
          email: 'dara@example.com',
          gender: t('admin.staffCards.genders.male'),
          date_of_birth: '15-04-1995',
          hire_date: '01-09-2024',
        }
  })

  return { cardFor, isStudent, isCertificate, base, routePrefix, permission, fieldLabel, sample, textKey }
}
