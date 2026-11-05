/**
 * The tabs of Accounting > Borrowings, in display order — shared by the tab
 * bar (BorrowingsLayout.vue), the `/admin/borrowings` redirect and the
 * sidebar link's permission check.
 */
export interface BorrowingsTab {
  to: string
  labelKey: string
  permission: string
}

export const borrowingsTabs: BorrowingsTab[] = [
  { to: '/admin/borrowings/loans', labelKey: 'admin.borrowings.tabs.loans', permission: 'borrowings.view' },
  { to: '/admin/borrowings/repayments', labelKey: 'admin.borrowings.tabs.repayments', permission: 'borrowings.view' },
  { to: '/admin/borrowings/lenders', labelKey: 'admin.borrowings.tabs.lenders', permission: 'borrowings.view' },
  { to: '/admin/borrowings/reports', labelKey: 'admin.borrowings.tabs.reports', permission: 'borrowings.view' },
]
