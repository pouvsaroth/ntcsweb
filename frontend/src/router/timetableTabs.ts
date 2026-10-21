/**
 * The tabs of Academic > Timetable (docs/academics/timetable.md), in display
 * order — shared by the tab bar (TimetableLayout.vue), the `/admin/timetable`
 * redirect and the sidebar link's permission check.
 */
export interface TimetableTab {
  to: string
  labelKey: string
  permission: string
}

export const timetableTabs: TimetableTab[] = [
  { to: '/admin/timetable/class', labelKey: 'timetable.tabs.class', permission: 'timetable.view' },
  { to: '/admin/timetable/teacher', labelKey: 'timetable.tabs.teacher', permission: 'timetable.view' },
  { to: '/admin/timetable/room', labelKey: 'timetable.tabs.room', permission: 'timetable.view' },
  { to: '/admin/timetable/subjects', labelKey: 'timetable.tabs.subjects', permission: 'timetable.view' },
  { to: '/admin/timetable/conflicts', labelKey: 'timetable.tabs.conflicts', permission: 'timetable.view' },
  { to: '/admin/timetable/changes', labelKey: 'timetable.tabs.changes', permission: 'timetable.request' },
]
