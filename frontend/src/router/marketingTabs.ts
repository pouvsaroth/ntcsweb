/**
 * The tabs of Marketing, in display order — shared by the tab bar
 * (MarketingLayout.vue), the `/admin/marketing` redirect to the first tab
 * this account can open, and the sidebar link's permission check.
 */
export interface MarketingTab {
  to: string
  labelKey: string
  permission: string
}

export const marketingTabs: MarketingTab[] = [
  { to: '/admin/marketing/campaigns', labelKey: 'marketing.tabs.campaigns', permission: 'campaigns.view' },
  { to: '/admin/marketing/report', labelKey: 'marketing.tabs.report', permission: 'campaigns.view' },
  { to: '/admin/marketing/referrals', labelKey: 'marketing.tabs.referrals', permission: 'referrals.view' },
  { to: '/admin/marketing/broadcast', labelKey: 'marketing.tabs.broadcast', permission: 'broadcasts.view' },
]
