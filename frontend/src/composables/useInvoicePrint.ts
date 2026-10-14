import { useI18n } from 'vue-i18n'

import { useTaskProgress } from '@/composables/useTaskProgress'
import { invoicesService } from '@/services/invoices'
import { useImagePreviewStore } from '@/stores/imagePreview'
import { printImage } from '@/utils/printImage'

/**
 * "Save and Print" / "Download Invoice": fetches the invoice as an A5 image
 * and opens the browser's print preview with it (see utils/printImage),
 * showing a progress bar while the server renders the image. Pair `progress`
 * with a <TaskProgressOverlay>. printInvoice() throws on failure, so the
 * caller decides where the error message goes.
 *
 * "Save and Image" / "Image": showInvoiceImage() fetches the same image but
 * opens it in the app-wide preview dialog (see stores/imagePreview.ts)
 * instead, where it can be shared to Telegram, Facebook, etc., downloaded or
 * copied. That dialog lives in AdminLayout, so the caller can navigate away
 * right after without closing it. Also throws on failure.
 */
export function useInvoicePrint() {
  const { t } = useI18n()
  const progress = useTaskProgress()
  const imagePreview = useImagePreviewStore()

  async function fetchImage(invoiceId: number): Promise<Blob> {
    progress.creep(t('admin.invoices.progressCreatingImage'), 85)
    const image = await invoicesService.getImage(invoiceId, (fraction) =>
      progress.set(t('admin.invoices.progressDownloading'), 85 + fraction * 15),
    )
    await progress.done()
    return image
  }

  async function printInvoice(invoiceId: number, invoiceNumber: string): Promise<void> {
    try {
      await printImage(await fetchImage(invoiceId), invoiceNumber)
    } finally {
      progress.reset()
    }
  }

  async function showInvoiceImage(invoiceId: number, invoiceNumber: string): Promise<void> {
    try {
      const blob = await fetchImage(invoiceId)
      imagePreview.show({ title: invoiceNumber, fileName: `${invoiceNumber}.png`, blob })
    } finally {
      progress.reset()
    }
  }

  return { progress, printInvoice, showInvoiceImage }
}
