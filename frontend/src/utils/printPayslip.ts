import { i18n } from '@/i18n'
import { payAmountLabel, type Payslip } from '@/services/payroll'
import type { PublicSiteInfo } from '@/stores/site'
import { formatDate } from '@/utils/date'
import { payslipLineLabel } from '@/utils/payslipLines'

/**
 * Opens one or more payslips as a printable page in a new tab and starts the
 * browser's print dialog ("Save as PDF" there gives a PDF to send) — same
 * approach as printOfferLetter(). Each payslip starts on its own page.
 * Written in the language the app is showing.
 */
export function printPayslips(payslips: Payslip[], school: PublicSiteInfo): void {
  const t = i18n.global.t
  const money = (slip: Payslip, amount: number) => escapeHtml(payAmountLabel(amount, 'fixed', slip.currency))

  const page = (slip: Payslip) => {
    const rows = (lines: Payslip['lines']['earnings']) =>
      lines.map((l) => `<tr><td>${escapeHtml(payslipLineLabel(l, t))}</td><td class="num">${money(slip, l.amount)}</td></tr>`).join('')
    const totalEarnings = slip.lines.earnings.reduce((sum, l) => sum + l.amount, 0)
    const totalDeductions = slip.lines.deductions.reduce((sum, l) => sum + l.amount, 0)
    const bank = slip.payment_method === 'bank'
      ? [slip.bank_name, slip.bank_account_number, slip.bank_account_name].filter(Boolean).join(' · ')
      : t('admin.payroll.salaries.cash')

    return `<section class="slip">
  <header>
    ${school.logo ? `<img src="${escapeHtml(school.logo)}" alt="">` : ''}
    <div>
      <h1>${escapeHtml(school.name)}</h1>
      <p>${escapeHtml([school.address, school.phone].filter(Boolean).join(' · '))}</p>
    </div>
    <div class="title">
      <h2>${escapeHtml(t('admin.payroll.payslip.title'))}</h2>
      <p>${escapeHtml(slip.run ? `${formatDate(slip.run.period_start)} – ${formatDate(slip.run.period_end)}` : '')}</p>
    </div>
  </header>
  <table class="info">
    <tr><th>${escapeHtml(t('admin.payroll.staff'))}</th><td>${escapeHtml(slip.staff?.name ?? '')} (${escapeHtml(slip.staff?.employee_code ?? '')})</td>
        <th>${escapeHtml(t('admin.payroll.payslip.reference'))}</th><td>${escapeHtml(slip.run?.reference ?? '')}</td></tr>
    <tr><th>${escapeHtml(t('admin.payroll.salaries.position'))}</th><td>${escapeHtml([slip.staff?.position, slip.staff?.department].filter(Boolean).join(' · '))}</td>
        <th>${escapeHtml(t('admin.payroll.payslip.payDate'))}</th><td>${escapeHtml(slip.run ? formatDate(slip.run.pay_date) : '')}</td></tr>
    <tr><th>${escapeHtml(t('admin.payroll.salaries.payment'))}</th><td colspan="3">${escapeHtml(bank)}</td></tr>
  </table>
  <div class="cols">
    <table class="lines"><thead><tr><th>${escapeHtml(t('admin.payroll.payslip.earnings'))}</th><th class="num">${escapeHtml(t('admin.payroll.amount'))}</th></tr></thead>
      <tbody>${rows(slip.lines.earnings)}</tbody>
      <tfoot><tr><td>${escapeHtml(t('admin.payroll.payslip.gross'))}</td><td class="num">${money(slip, totalEarnings)}</td></tr></tfoot></table>
    <table class="lines"><thead><tr><th>${escapeHtml(t('admin.payroll.payslip.deductions'))}</th><th class="num">${escapeHtml(t('admin.payroll.amount'))}</th></tr></thead>
      <tbody>${rows(slip.lines.deductions) || `<tr><td colspan="2">—</td></tr>`}</tbody>
      <tfoot><tr><td>${escapeHtml(t('admin.payroll.payslip.totalDeductions'))}</td><td class="num">${money(slip, totalDeductions)}</td></tr></tfoot></table>
  </div>
  <p class="net">${escapeHtml(t('admin.payroll.payslip.net'))}: <strong>${money(slip, slip.net_pay)}</strong></p>
  ${slip.lines.employer.length ? `<p class="employer">${escapeHtml(t('admin.payroll.payslip.employerPaid'))}: ${slip.lines.employer.map((l) => `${escapeHtml(payslipLineLabel(l, t))} ${money(slip, l.amount)}`).join(' · ')}</p>` : ''}
  <div class="signatures"><div>${escapeHtml(t('admin.payroll.payslip.signSchool'))}</div><div>${escapeHtml(t('admin.payroll.payslip.signStaff'))}</div></div>
</section>`
  }

  const html = `<!DOCTYPE html>
<html lang="${escapeHtml(String(i18n.global.locale.value))}">
<head>
<meta charset="utf-8">
<title>${escapeHtml(t('admin.payroll.payslip.title'))}${payslips.length === 1 && payslips[0]?.staff ? ` — ${escapeHtml(payslips[0].staff.name)}` : ''}</title>
<style>
  body { font-family: 'Khmer OS', 'Noto Sans Khmer', Arial, sans-serif; font-size: 13px; line-height: 1.5; color: #111827; margin: 32px; }
  .slip { page-break-after: always; }
  .slip:last-child { page-break-after: auto; }
  header { display: flex; align-items: center; gap: 14px; border-bottom: 2px solid #1f2937; padding-bottom: 10px; margin-bottom: 16px; }
  header img { width: 56px; height: 56px; object-fit: contain; }
  header h1 { font-size: 18px; margin: 0; }
  header p { margin: 0; color: #4b5563; font-size: 12px; }
  header .title { margin-left: auto; text-align: right; }
  header h2 { font-size: 18px; margin: 0; letter-spacing: 0.05em; }
  table { border-collapse: collapse; width: 100%; }
  .info th { text-align: left; color: #4b5563; font-weight: normal; padding: 4px 6px; width: 16%; }
  .info td { font-weight: 600; padding: 4px 6px; }
  .cols { display: flex; gap: 16px; margin-top: 16px; }
  .lines th, .lines td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; }
  .lines thead th { background: #f3f4f6; }
  .lines tfoot td { font-weight: 700; border-top: 2px solid #9ca3af; }
  .num { text-align: right !important; white-space: nowrap; }
  .net { font-size: 16px; text-align: right; margin: 16px 0 4px; }
  .employer { color: #4b5563; font-size: 12px; text-align: right; margin: 0; }
  .signatures { display: flex; justify-content: space-between; margin-top: 56px; }
  .signatures div { width: 40%; border-top: 1px solid #111827; padding-top: 6px; text-align: center; font-size: 12px; }
  @media print { body { margin: 0.5in; } }
</style>
</head>
<body>${payslips.map(page).join('')}</body>
</html>`

  const printWindow = window.open('', '_blank')
  if (!printWindow) return

  printWindow.document.write(html)
  printWindow.document.close()
  printWindow.focus()
  printWindow.onload = () => printWindow.print()
}

function escapeHtml(value: string): string {
  const div = document.createElement('div')
  div.textContent = value
  return div.innerHTML
}
