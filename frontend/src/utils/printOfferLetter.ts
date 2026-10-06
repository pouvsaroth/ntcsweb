import { i18n } from '@/i18n'
import type { OfferLetter } from '@/services/offerLetters'
import type { PublicSiteInfo } from '@/stores/site'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Opens the offer letter as a printable page in a new tab and starts the
 * browser's print dialog — "Save as PDF" there gives a PDF to email. Same
 * approach as printExamApplicationForm(): one record, printed on demand.
 * Written in the language the app is showing.
 */
export function printOfferLetter(offer: OfferLetter, school: PublicSiteInfo): void {
  const t = i18n.global.t
  const applicant = offer.applicant
  const name = applicant?.name ?? ''
  const row = (label: string, value: string | null | undefined) =>
    value ? `<tr><th>${escapeHtml(label)}</th><td>${escapeHtml(value)}</td></tr>` : ''

  const html = `<!DOCTYPE html>
<html lang="${escapeHtml(String(i18n.global.locale.value))}">
<head>
<meta charset="utf-8">
<title>${escapeHtml(offer.reference)} — ${escapeHtml(name)}</title>
<style>
  body { font-family: 'Khmer OS', 'Noto Sans Khmer', Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #111827; margin: 40px; }
  header { display: flex; align-items: center; gap: 16px; border-bottom: 2px solid #1f2937; padding-bottom: 12px; margin-bottom: 24px; }
  header img { width: 64px; height: 64px; object-fit: contain; }
  header h1 { font-size: 20px; margin: 0; }
  header p { margin: 0; color: #4b5563; font-size: 12px; }
  .meta { display: flex; justify-content: space-between; color: #4b5563; font-size: 13px; margin-bottom: 20px; }
  h2 { font-size: 18px; margin: 0 0 16px; text-align: center; letter-spacing: 0.05em; }
  table { border-collapse: collapse; width: 100%; margin: 16px 0; }
  th { text-align: left; width: 35%; color: #4b5563; font-weight: normal; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  td { font-weight: 600; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; white-space: pre-line; }
  .section { white-space: pre-line; margin: 12px 0; }
  .signatures { display: flex; justify-content: space-between; margin-top: 64px; }
  .signatures div { width: 40%; border-top: 1px solid #111827; padding-top: 6px; text-align: center; font-size: 13px; }
  @media print { body { margin: 0.6in; } }
</style>
</head>
<body>
  <header>
    ${school.logo ? `<img src="${escapeHtml(school.logo)}" alt="">` : ''}
    <div>
      <h1>${escapeHtml(school.name)}</h1>
      ${school.name_en ? `<p>${escapeHtml(school.name_en)}</p>` : ''}
      <p>${escapeHtml([school.address, school.phone, school.email].filter(Boolean).join(' · '))}</p>
    </div>
  </header>

  <div class="meta">
    <span>${escapeHtml(offer.reference)}</span>
    <span>${escapeHtml(formatDate(offer.sent_at ?? new Date()))}</span>
  </div>

  <h2>${escapeHtml(t('admin.recruitment.offers.letterTitle'))}</h2>

  <p>${escapeHtml(t('admin.recruitment.offers.letterDear', { name }))}</p>
  <p>${escapeHtml(t('admin.recruitment.offers.letterIntro', { position: offer.position_title, school: school.name }))}</p>

  <table>
    ${row(t('admin.recruitment.offers.position'), offer.position_title)}
    ${row(t('admin.recruitment.manpower.department'), offer.department ?? null)}
    ${row(t('admin.recruitment.manpower.employmentType'), t(`admin.recruitment.employmentTypes.${offer.employment_type}`))}
    ${row(t('admin.recruitment.offers.salary'), `${formatMoney(offer.salary, offer.salary_currency)} ${t('admin.recruitment.offers.perMonth')}`)}
    ${row(t('admin.recruitment.offers.startDate'), formatDate(offer.start_date))}
    ${row(t('admin.recruitment.offers.probation'), offer.probation_months ? t('admin.recruitment.offers.probationMonths', { months: offer.probation_months }) : null)}
    ${row(t('admin.recruitment.offers.benefits'), offer.benefits)}
  </table>

  ${offer.terms ? `<div class="section">${escapeHtml(offer.terms)}</div>` : ''}

  <p>${escapeHtml(
    offer.expires_on
      ? t('admin.recruitment.offers.letterReplyBy', { date: formatDate(offer.expires_on) })
      : t('admin.recruitment.offers.letterReply'),
  )}</p>

  <div class="signatures">
    <div>${escapeHtml(t('admin.recruitment.offers.signSchool'))}</div>
    <div>${escapeHtml(t('admin.recruitment.offers.signCandidate', { name }))}</div>
  </div>
</body>
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
