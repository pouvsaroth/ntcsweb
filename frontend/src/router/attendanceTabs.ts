/**
 * The tabs of HRM > Attendance & Time, in display order — shared by the tab
 * bar (AttendanceLayout.vue), the `/admin/time-attendance` redirect to the
 * first tab this account can open, and the sidebar link's permission check.
 */
export interface AttendanceTab {
  to: string
  labelKey: string
  permission: string
}

export const attendanceTabs: AttendanceTab[] = [
  { to: '/admin/time-attendance/employees', labelKey: 'admin.timeAttendance.tabs.employees', permission: 'staff-attendance.view' },
  { to: '/admin/time-attendance/check-ins', labelKey: 'admin.timeAttendance.tabs.checkIns', permission: 'staff-attendance.view' },
  { to: '/admin/time-attendance/late', labelKey: 'admin.timeAttendance.tabs.late', permission: 'staff-attendance.view' },
  { to: '/admin/time-attendance/early-leave', labelKey: 'admin.timeAttendance.tabs.earlyLeave', permission: 'staff-attendance.view' },
  { to: '/admin/time-attendance/absence', labelKey: 'admin.timeAttendance.tabs.absence', permission: 'staff-attendance.view' },
  { to: '/admin/time-attendance/overtime', labelKey: 'admin.timeAttendance.tabs.overtime', permission: 'staff-attendance.view' },
  { to: '/admin/time-attendance/shifts', labelKey: 'admin.timeAttendance.tabs.shifts', permission: 'staff-attendance.view' },
  { to: '/admin/time-attendance/work-schedules', labelKey: 'admin.timeAttendance.tabs.workSchedules', permission: 'staff-attendance.view' },
  { to: '/admin/time-attendance/holidays', labelKey: 'admin.timeAttendance.tabs.holidays', permission: 'staff-attendance.view' },
  { to: '/admin/time-attendance/corrections', labelKey: 'admin.timeAttendance.tabs.corrections', permission: 'staff-attendance.view' },
  { to: '/admin/time-attendance/approval', labelKey: 'admin.timeAttendance.tabs.approval', permission: 'staff-attendance.view' },
]
