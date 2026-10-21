/**
 * The tabs of Academic > Lesson plans (docs/academics/lesson-plans.md), in
 * display order — shared by the tab bar (LessonsLayout.vue), the
 * `/admin/lessons` redirect and the sidebar link's permission check. A tab is
 * shown to anyone holding one of its permissions.
 */
export interface LessonTab {
  to: string
  labelKey: string
  permission: string[]
}

const anyLesson = ['lesson-plans.view', 'lesson-plans.write', 'lesson-plans.review']

export const lessonTabs: LessonTab[] = [
  { to: '/admin/lessons/plans', labelKey: 'lessonPlans.tabs.plans', permission: anyLesson },
  { to: '/admin/lessons/week', labelKey: 'lessonPlans.tabs.week', permission: anyLesson },
  { to: '/admin/lessons/log', labelKey: 'lessonPlans.tabs.log', permission: ['lesson-plans.view', 'lesson-plans.write'] },
  { to: '/admin/lessons/review', labelKey: 'lessonPlans.tabs.review', permission: ['lesson-plans.review'] },
  { to: '/admin/lessons/progress', labelKey: 'lessonPlans.tabs.progress', permission: ['lesson-plans.view', 'lesson-plans.write'] },
  { to: '/admin/lessons/delays', labelKey: 'lessonPlans.tabs.delays', permission: ['lesson-plans.view', 'lesson-plans.write'] },
]
