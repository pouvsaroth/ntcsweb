# Exam fee payment (student applies online)

When a student applies for an exam (eApprovals → My Request → Exam
Application), they pick how to pay the exam fee.

## Owner decisions (2026-10-27)

| Question | Answer |
|---|---|
| Choice | **Cash** or **QR code** (required; QR only when the school has set its KHQR code in School Settings) |
| QR | The school's own KHQR with the fee amount — the same code as student registration |
| Paid? | Found automatically through Bakong, like the plan sign-up (NewTech's Bakong token, back office → Billing → Settings) |
| Pay later | The QR screen has **Pay later**; each unpaid row has a **Pay** button that opens the QR again |
| Approvals | Shows **Paid (QR)**, **Not paid (QR)** or **Not paid (Cash)** |
| Cash | Stays **Not paid (Cash)** until the school takes the money (Print) |

## As built

- School migration `2026_10_27_120000_add_payment_to_exam_applications_table`:
  `payment_method` (cash | qr), the current `khqr` + `khqr_md5` + `khqr_expires_at`,
  `khqr_checked_at`, and `paid_at`, `paid_amount`, `paid_currency`, `bakong_hash`.
- `ExamFeePayment`: `qrFor()` makes a one-time code (`Khqr::forPayment`, valid
  15 minutes, reused while valid); `check()` asks Bakong by MD5 at most every
  5 seconds until 10 minutes after expiry, and accepts it only for the right
  amount and currency; `checkWaiting()` runs on the student's list and the
  Approvals queue.
- API (student): `POST my-exam-applications` takes `payment_method`;
  `GET my-exam-applications/fee` returns `qr_available`;
  `POST my-exam-applications/{id}/qr`; `GET my-exam-applications/{id}/payment`.
- Print (Examination grid): a fee already paid by QR is recorded as a QR payment.
- Console: payment choice on the apply form, `ExamFeeQrModal` (amount, QR,
  waits, Pay later), Pay button + status badge on My Request and My Exam
  Application rows, Paid / Not paid in the Approvals list and detail.
- Tests: `tests/Feature/Academic/ExamFeePaymentTest.php` (3).

## Open point

Bakong is asked with NewTech's token while the money goes to the school's
own account. Check once on the live site with a small real payment that
Bakong reports it; if not, the school confirms by its bank app as before.
