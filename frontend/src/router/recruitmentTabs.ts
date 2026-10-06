/**
 * The tabs of HRM > Recruitment, in display order — shared by the tab bar
 * (RecruitmentLayout.vue), the `/admin/recruitment` redirect to the first
 * tab this account can open, and the sidebar link's permission check.
 */
export interface RecruitmentTab {
  to: string
  labelKey: string
  permission: string
}

export const recruitmentTabs: RecruitmentTab[] = [
  { to: '/admin/recruitment/manpower-requests', labelKey: 'admin.recruitment.tabs.manpowerRequests', permission: 'recruitment.view' },
  { to: '/admin/recruitment/job-positions', labelKey: 'admin.recruitment.tabs.jobPositions', permission: 'recruitment.view' },
  { to: '/admin/recruitment/job-postings', labelKey: 'admin.recruitment.tabs.jobPostings', permission: 'recruitment.view' },
  { to: '/admin/recruitment/applicants', labelKey: 'admin.recruitment.tabs.applicants', permission: 'recruitment.view' },
  { to: '/admin/recruitment/cvs', labelKey: 'admin.recruitment.tabs.cvs', permission: 'recruitment.view' },
  { to: '/admin/recruitment/interviews', labelKey: 'admin.recruitment.tabs.interviews', permission: 'recruitment.view' },
  { to: '/admin/recruitment/evaluations', labelKey: 'admin.recruitment.tabs.evaluations', permission: 'recruitment.view' },
  { to: '/admin/recruitment/selection', labelKey: 'admin.recruitment.tabs.selection', permission: 'recruitment.view' },
  { to: '/admin/recruitment/offers', labelKey: 'admin.recruitment.tabs.offers', permission: 'recruitment.view' },
  { to: '/admin/recruitment/pipeline', labelKey: 'admin.recruitment.tabs.pipeline', permission: 'recruitment.view' },
]
