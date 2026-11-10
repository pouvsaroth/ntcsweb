/**
 * Settings > Roles can hide single tabs from a role ("Show" box on each tab
 * row). A hidden tab is stored on the role as a `hide-tab.*` permission —
 * a mark, not a grant, so roles that never had one keep every tab. The tab
 * bar, the menu's "first tab" redirect and the router guard all check it.
 * It only hides the tab: the data behind it still follows the menu's own
 * permissions on the server.
 *
 * The backend lists the same slugs in App\Support\Authorization\TabVisibility.
 */
import { attendanceRecordTabs, attendanceTabs } from '@/router/attendanceTabs'
import { borrowingsTabs } from '@/router/borrowingsTabs'
import { examinationTabs } from '@/router/examinationTabs'
import { frontOfficeTabs } from '@/router/frontOfficeTabs'
import { leaveTabs } from '@/router/leaveTabs'
import { lessonTabs } from '@/router/lessonTabs'
import { organizationTabs } from '@/router/organizationTabs'
import { payrollTabs } from '@/router/payrollTabs'
import { performanceTabs } from '@/router/performanceTabs'
import { professionalDevelopmentTabs } from '@/router/professionalDevelopmentTabs'
import { recruitmentTabs } from '@/router/recruitmentTabs'
import { schoolSetupTabs } from '@/router/schoolSetupTabs'
import { teacherTabs } from '@/router/teacherTabs'
import { timetableTabs } from '@/router/timetableTabs'

export { hideTabSlug } from '@/router/hideTabSlug'

/**
 * Every tab bar's tab paths, one list per page. Programs, Study Building,
 * Invoices, Attendance and Help & Support draw their own tab bars (components/admin/*Tabs.vue);
 * their paths are repeated here.
 */
export const TAB_BARS: string[][] = [
  attendanceTabs,
  attendanceRecordTabs,
  borrowingsTabs,
  examinationTabs,
  frontOfficeTabs,
  leaveTabs,
  lessonTabs,
  organizationTabs,
  payrollTabs,
  performanceTabs,
  professionalDevelopmentTabs,
  recruitmentTabs,
  schoolSetupTabs,
  teacherTabs,
  timetableTabs,
].map((tabs) => tabs.map((tab) => tab.to))
  .concat([
    ['/admin/academic-programs', '/admin/academic-years', '/admin/course-packages', '/admin/books', '/admin/book-categories'],
    ['/admin/buildings', '/admin/classrooms'],
    ['/admin/invoices', '/admin/invoices/monthly', '/admin/invoices/discount-codes'],
    ['/admin/attendance', '/admin/attendance/summary', '/admin/attendance/sheet'],
    ['/admin/help/articles', '/admin/help/faq', '/admin/help/tickets', '/admin/help/contact'],
  ])

/** A hidden tab's next tab on the same page that isn't hidden (null: all hidden). */
export function nextShownTab(path: string, isHidden: (to: string) => boolean): string | null {
  const bar = TAB_BARS.find((tabs) => tabs.includes(path))
  return bar?.find((to) => !isHidden(to)) ?? null
}
