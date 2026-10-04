/**
 * Opens the browser's print preview for an image (e.g. an invoice PNG from
 * the API) without saving a file first. The image is placed in a hidden
 * iframe sized to one page, so the preview shows just the image — none of
 * the admin page around it. Resolves once the print dialog has closed
 * (printed or cancelled), so the caller can safely navigate away after.
 */
export function printImage(blob: Blob, title: string, pageSize = 'A5'): Promise<void> {
  const blobUrl = URL.createObjectURL(blob)
  const iframe = document.createElement('iframe')
  iframe.setAttribute('aria-hidden', 'true')
  iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden'
  document.body.appendChild(iframe)

  const cleanup = () => {
    iframe.remove()
    URL.revokeObjectURL(blobUrl)
  }

  return new Promise<void>((resolve, reject) => {
    const doc = iframe.contentDocument
    const win = iframe.contentWindow
    if (!doc || !win) {
      cleanup()
      reject(new Error('Print preview is not available in this browser.'))
      return
    }

    doc.open()
    doc.write(`<!doctype html><html><head><meta charset="utf-8"><title></title>
<style>
  @page { size: ${pageSize}; margin: 0; }
  /* Exactly one page: the image is scaled to fit inside the page box rather
     than filling its width — an image of the page's own proportions at full
     width rounds a fraction past the bottom edge and spills a blank 2nd page. */
  html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; }
  img { display: block; width: 100%; height: 100%; object-fit: contain; object-position: top center; break-inside: avoid; }
</style></head><body><img alt=""></body></html>`)
    doc.close()
    // Set via the DOM rather than the template so a title can't inject markup.
    doc.title = title

    const img = doc.querySelector('img')!
    img.onerror = () => {
      cleanup()
      reject(new Error('The image could not be loaded for printing.'))
    }
    img.onload = () => {
      let finished = false
      const finish = () => {
        if (finished) return
        finished = true
        // Let the dialog fully release the iframe before removing it.
        setTimeout(cleanup, 500)
        resolve()
      }
      win.addEventListener('afterprint', finish, { once: true })
      win.focus()
      win.print()
      // Browsers that open the dialog without blocking and never fire
      // afterprint still resolve, just later.
      setTimeout(finish, 60_000)
    }
    img.src = blobUrl
  })
}
