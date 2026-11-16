import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import type { CardFieldType, CardFor, TextFieldType } from '@/utils/staffCard'

/**
 * HRM > Staff cards and Students > Student cards share one list, designer
 * and print page; the route's `meta.cardFor` says which kind of card it is —
 * its own URLs, permissions (staff-cards.* / student-cards.*) and field names.
 */
export function useCardKind() {
  const route = useRoute()
  const { t, te } = useI18n()

  const cardFor = computed<CardFor>(() => (route.meta.cardFor === 'student' ? 'student' : 'staff'))
  const isStudent = computed(() => cardFor.value === 'student')
  const base = computed(() => (isStudent.value ? '/admin/student-cards' : '/admin/staff-cards'))
  const routePrefix = computed(() => (isStudent.value ? 'admin.studentCards' : 'admin.staffCards'))
  const permission = (action: 'view' | 'create' | 'update' | 'delete') => `${isStudent.value ? 'student' : 'staff'}-cards.${action}`

  /** A field's name; a student card names a few differently (e.g. Name, not Name (English)). */
  function fieldLabel(type: CardFieldType): string {
    const studentKey = `admin.staffCards.studentFieldTypes.${type}`
    return isStudent.value && te(studentKey) ? t(studentKey) : t(`admin.staffCards.fieldTypes.${type}`)
  }

  /** Made-up details to design with before picking a real person. */
  const sample = computed<Partial<Record<TextFieldType, string>>>(() =>
    isStudent.value
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
        },
  )

  return { cardFor, isStudent, base, routePrefix, permission, fieldLabel, sample }
}
