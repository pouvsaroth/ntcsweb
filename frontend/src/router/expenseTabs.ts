/**
 * The tabs of Accounting > Expenses, in display order — shared by the tab
 * bar (ExpensesLayout.vue), the list's redirect for someone who can only
 * open Expense for, and the sidebar link's permission check.
 */
export interface ExpenseTab {
  to: string
  labelKey: string
  permission: string
}

export const expenseTabs: ExpenseTab[] = [
  { to: '/admin/expenses', labelKey: 'adminNav.items.expenses', permission: 'expense.view' },
  // Who an expense is for (Newtech, Flexi, Family...).
  { to: '/admin/expenses/expense-for', labelKey: 'adminNav.items.expenseFor', permission: 'expense-for.view' },
]
