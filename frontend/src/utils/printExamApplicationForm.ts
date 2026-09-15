import { formatMoney } from '@/utils/currency'
import type { ExamApplication } from '@/services/examApplications'

/**
 * Opens a new browser tab with a printable "Application Form" document for
 * one exam application and triggers the browser's print dialog on it —
 * deliberately a plain `window.open` + written HTML document rather than an
 * in-page `@media print` stylesheet, since this only ever needs to print
 * one specific record on demand, not the whole grid page. Matches the
 * layout of the paper form this feature was modeled on: Enrollment Code up
 * top, Student Information and Examination Information as two labeled
 * sections, fee/payment details recorded just now at the bottom.
 */
export function printExamApplicationForm(
  application: ExamApplication,
  fee: { fee: number; currency: 'USD' | 'KHR'; paymentMethod: string; printDate: string },
): void {
  const row = (label: string, value: string | null | undefined) =>
    `<div class="row"><span class="label">${escapeHtml(label)}</span><span class="value">${escapeHtml(value ?? '—')}</span></div>`

  const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>${escapeHtml(application.student.name)} — Application Form</title>
<style>
  body { font-family: 'Khmer OS', Arial, sans-serif; font-size: 13px; color: #111827; margin: 24px; }
  h1 { font-size: 18px; margin: 0 0 4px; }
  h2 { font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; color: #92400e; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; margin: 20px 0 8px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; }
  .row { display: flex; padding: 3px 0; border-bottom: 1px dotted #e5e7eb; }
  .label { width: 45%; color: #6b7280; }
  .value { flex: 1; font-weight: 600; }
  .photo { float: right; width: 90px; height: 110px; object-fit: cover; border: 1px solid #d1d5db; margin-top: -40px; }
  @media print { body { margin: 0.5in; } }
</style>
</head>
<body>
  ${application.student.photo_url ? `<img class="photo" src="${escapeHtml(application.student.photo_url)}" alt="">` : ''}
  <h1>Exam Application Form</h1>
  ${row('Enrollment Code', application.enrollment_code)}

  <h2>Student Information</h2>
  <div class="grid">
    ${row('Student Code', application.student.student_code)}
    ${row('Full Name', application.student.name)}
    ${row('Other Name', application.student.english_name)}
    ${row('Sex', application.student.gender)}
    ${row('Birth Date', application.student.date_of_birth)}
    ${row('Phone', application.student.phone)}
    ${row('Address', application.student.address)}
    ${row('', '')}
  </div>

  <h2>Examination Information</h2>
  <div class="grid">
    ${row('Course', application.enrollment.course_package?.name)}
    ${row('Book', application.book?.title)}
    ${row('Room No', application.classroom?.name)}
    ${row('Table No', application.table?.name ?? application.table_no)}
    ${row('Exam Date', application.exam_date)}
    ${row('Remark', application.remark)}
    ${row('Time In', application.exam_time?.slice(0, 5))}
    ${row('Time Out', application.exam_time_out?.slice(0, 5))}
  </div>

  <h2>Fee</h2>
  <div class="grid">
    ${row('Fee', formatMoney(fee.fee, fee.currency))}
    ${row('Payment Method', fee.paymentMethod)}
    ${row('Print Date', fee.printDate)}
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
