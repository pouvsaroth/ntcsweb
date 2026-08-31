import { apiDownload, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { Payment } from '@/services/payments'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type InvoiceStatusValue = 'DRAFT' | 'ISSUED' | 'PARTIALLY_PAID' | 'PAID' | 'OVERDUE' | 'CANCELLED' | 'VOID'

export const invoiceStatuses: InvoiceStatusValue[] = [
  'DRAFT',
  'ISSUED',
  'PARTIALLY_PAID',
  'PAID',
  'OVERDUE',
  'CANCELLED',
  'VOID',
]

/** Terminal states — a payment can never be recorded against one of these (mirrors InvoiceStatus::isClosed() on the backend). */
export function isInvoiceClosed(status: InvoiceStatusValue): boolean {
  return status === 'CANCELLED' || status === 'VOID'
}

export type NotificationChannelValue = 'EMAIL' | 'TELEGRAM' | 'MESSENGER'

export const notificationChannels: NotificationChannelValue[] = ['EMAIL', 'TELEGRAM', 'MESSENGER']

export interface InvoiceStudent {
  id: number
  student_code: string
  name: string
}

export interface InvoiceItem {
  id: number
  product_id: number
  product_name?: string | null
  product_variant_id: number | null
  variant_name?: string | null
  description: string | null
  quantity: number
  unit_price: number
  discount: number
  subtotal: number
  total: number
}

export interface Invoice {
  id: number
  invoice_number: string
  student_id: number
  student?: InvoiceStudent
  invoice_date: string | null
  due_date: string | null
  status: InvoiceStatusValue
  subtotal: number
  discount: number
  tax: number
  total: number
  paid_amount: number
  balance: number
  currency: string
  notes: string | null
  cancellation_reason: string | null
  /** Only present once the invoice has actually been cancelled/voided (backend omits it via whenLoaded() otherwise). */
  cancelled_by?: string | null
  cancelled_at: string | null
  created_by?: string | null
  items?: InvoiceItem[]
  payments?: Payment[]
  created_at: string
}

export interface NotificationLog {
  id: number
  invoice_id: number
  channel: NotificationChannelValue
  recipient: string
  type: string
  status: 'PENDING' | 'SENT' | 'FAILED'
  provider_message_id: string | null
  error_message: string | null
  sent_at: string | null
  sent_by?: string | null
  created_at: string
}

export interface InvoiceItemInput {
  product_id: number | null
  product_variant_id: number | null
  quantity: number
  /** Empty string means "use the product/variant's own price" — never sent when blank. */
  unit_price: string
  discount: string
  description: string
}

export interface InvoiceInput {
  student_id: number | null
  invoice_date: string
  due_date: string
  discount: string
  tax: string
  notes: string
  items: InvoiceItemInput[]
}

export interface SendInvoiceInput {
  channel: NotificationChannelValue
  recipient: string
}

export interface RecordPaymentInput {
  amount: string
  payment_method: string
  payment_date: string
  reference_number: string
  notes: string
}

function toInvoicePayload(input: InvoiceInput) {
  return {
    student_id: input.student_id,
    invoice_date: input.invoice_date || undefined,
    due_date: input.due_date || undefined,
    discount: input.discount.trim() ? Number(input.discount) : undefined,
    tax: input.tax.trim() ? Number(input.tax) : undefined,
    notes: input.notes || undefined,
    items: input.items.map((item) => ({
      product_id: item.product_id,
      product_variant_id: item.product_variant_id,
      quantity: item.quantity,
      unit_price: item.unit_price.trim() ? Number(item.unit_price) : undefined,
      discount: item.discount.trim() ? Number(item.discount) : undefined,
      description: item.description || undefined,
    })),
  }
}

export const invoicesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Invoice>> {
    const result = await apiGetWithMeta<Invoice[]>('/invoices', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<Invoice>(`/invoices/${id}`).then((r) => r.data),

  create: (input: InvoiceInput) => apiPost<Invoice>('/invoices', toInvoicePayload(input)),

  cancel: (id: number, reason: string) => apiPost<Invoice>(`/invoices/${id}/cancel`, { reason }),

  void: (id: number, reason: string) => apiPost<Invoice>(`/invoices/${id}/void`, { reason }),

  send: (id: number, input: SendInvoiceInput) => apiPost<void>(`/invoices/${id}/send`, input),

  notifications: (id: number) => apiGetWithMeta<NotificationLog[]>(`/invoices/${id}/notifications`).then((r) => r.data),

  recordPayment: (id: number, input: RecordPaymentInput) =>
    apiPost<Payment>(`/invoices/${id}/payments`, {
      amount: Number(input.amount) || 0,
      payment_method: input.payment_method,
      payment_date: input.payment_date || undefined,
      reference_number: input.reference_number || undefined,
      notes: input.notes || undefined,
    }),

  downloadPdf: (id: number, invoiceNumber: string) => apiDownload(`/invoices/${id}/pdf`, `${invoiceNumber}.pdf`),
}
