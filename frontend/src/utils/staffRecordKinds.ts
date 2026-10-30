/**
 * The list-style tabs of the staff form (docs/hrm/staff-profile-tabs.md),
 * drawn by StaffRecordsTab.vue. Each field's label is
 * `admin.staff.records.<i18n>.fields.<key>`; a select's choices are
 * `admin.staff.records.options.<options>.<value>`. Must match
 * StaffRecordKinds.php on the server.
 */
export interface StaffRecordField {
  key: string
  type: 'text' | 'tel' | 'date' | 'select'
  required?: boolean
  /** Choice list name under admin.staff.records.options, for a select. */
  options?: keyof typeof STAFF_RECORD_OPTIONS
  /** Shown as a table column (and on the phone card). */
  inList?: boolean
  /** Takes the full form width. */
  wide?: boolean
}

export interface StaffRecordKind {
  /** The API path segment: staff/{id}/records/<kind>. */
  kind: string
  /** The i18n block under admin.staff.records. */
  i18n: string
  /** The field that heads a phone card. */
  titleField: string
  fields: StaffRecordField[]
}

export const STAFF_RECORD_OPTIONS = {
  contactRelationships: ['father', 'mother', 'spouse', 'sibling', 'child', 'relative', 'friend', 'other'],
  familyRelationships: ['spouse', 'child', 'father', 'mother', 'sibling', 'other'],
  genders: ['male', 'female', 'other'],
  languageLevels: ['basic', 'intermediate', 'advanced', 'fluent', 'native'],
  skillLevels: ['basic', 'intermediate', 'advanced', 'expert'],
} as const

export const EMERGENCY_CONTACTS: StaffRecordKind = {
  kind: 'emergency-contacts',
  i18n: 'emergencyContacts',
  titleField: 'name',
  fields: [
    { key: 'name', type: 'text', required: true, inList: true },
    { key: 'relationship', type: 'select', required: true, options: 'contactRelationships', inList: true },
    { key: 'phone', type: 'tel', required: true, inList: true },
    { key: 'alt_phone', type: 'tel', inList: true },
    { key: 'address', type: 'text', wide: true, inList: true },
    { key: 'note', type: 'text', wide: true },
  ],
}

export const FAMILY_MEMBERS: StaffRecordKind = {
  kind: 'family-members',
  i18n: 'familyMembers',
  titleField: 'name',
  fields: [
    { key: 'name', type: 'text', required: true, inList: true },
    { key: 'relationship', type: 'select', required: true, options: 'familyRelationships', inList: true },
    { key: 'gender', type: 'select', options: 'genders', inList: true },
    { key: 'date_of_birth', type: 'date', inList: true },
    { key: 'occupation', type: 'text', inList: true },
    { key: 'phone', type: 'tel', inList: true },
    { key: 'note', type: 'text', wide: true },
  ],
}

export const LANGUAGES: StaffRecordKind = {
  kind: 'languages',
  i18n: 'languages',
  titleField: 'language',
  fields: [
    { key: 'language', type: 'text', required: true, inList: true },
    { key: 'level', type: 'select', required: true, options: 'languageLevels', inList: true },
    { key: 'certificate', type: 'text', inList: true },
    { key: 'note', type: 'text', wide: true },
  ],
}

export const JOB_EXPERIENCES: StaffRecordKind = {
  kind: 'job-experiences',
  i18n: 'jobExperiences',
  titleField: 'company',
  fields: [
    { key: 'company', type: 'text', required: true, inList: true },
    { key: 'position', type: 'text', required: true, inList: true },
    { key: 'start_date', type: 'date', inList: true },
    { key: 'end_date', type: 'date', inList: true },
    { key: 'responsibilities', type: 'text', wide: true },
    { key: 'reason_for_leaving', type: 'text', wide: true, inList: true },
  ],
}

export const OTHER_SKILLS: StaffRecordKind = {
  kind: 'other-skills',
  i18n: 'otherSkills',
  titleField: 'skill',
  fields: [
    { key: 'skill', type: 'text', required: true, inList: true },
    { key: 'level', type: 'select', options: 'skillLevels', inList: true },
    { key: 'note', type: 'text', wide: true, inList: true },
  ],
}
