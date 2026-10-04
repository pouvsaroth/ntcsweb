import { useI18n } from 'vue-i18n'

import { useTaskProgress } from '@/composables/useTaskProgress'
import { invoicesService } from '@/services/invoices'
import { printImage } from '@/utils/printImage'

/**
 * "Save and Print" / "Download Invoice": fetches the invoice as an A5 image
 * and opens the browser's print preview with it (see utils/printImage),
 * showing a progress bar while the server renders the image. Pair `progress`
 * with a <TaskProgressOverlay>. printInvoice() throws on failure, so the
 * caller decides where the error message goes.
 */
export function useInvoicePrint() {
  const { t } = useI18n()
  const progress = useTaskProgress()

  async function printInvoice(invoiceId: number, invoiceNumber: string): Promise<void> {
    try {
      progress.creep(t('admin.invoices.progressCreatingImage'), 85)
      const image = await invoicesService.getImage(invoiceId, (fraction) =>
        progress.set(t('admin.invoices.progressDownloading'), 85 + fraction * 15),
      )
      await progress.done()
      await printImage(image, invoiceNumber)
    } finally {
      progress.reset()
    }
  }

  return { progress, printInvoice }
}
