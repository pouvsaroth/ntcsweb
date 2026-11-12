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
]
