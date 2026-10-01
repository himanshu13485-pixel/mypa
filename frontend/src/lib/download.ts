import { inNativeShell } from './nativeBridge'

/**
 * Save a file the app holds a link to.
 *
 * Two different machines underneath. A browser is given the bytes and saves
 * them itself; the Android app cannot - a WebView has no downloading in it at
 * all, and a blob built in the page is dropped on the floor with no error
 * anywhere, which is why tapping a file in the app did nothing. There the URL
 * goes to the system downloader instead, which is also why `link` is a signed
 * address rather than the ordinary endpoint: that downloader is another
 * process and carries none of our headers.
 *
 * `fetchBlob` is only called when the bytes are actually wanted, so the app
 * never spends somebody's data building a blob it cannot use.
 */
export async function saveFile(
  filename: string,
  fetchBlob: () => Promise<Blob>,
  link: () => Promise<string>,
): Promise<void> {
  if (inNativeShell()) {
    // Navigating is how a WebView is told to download something: the page
    // stays where it is and the download listener in the shell takes it.
    window.location.href = await link()
    return
  }

  saveBlob(await fetchBlob(), filename)
}

/**
 * Hand a file fetched by the app to the browser as a download.
 *
 * The link has to be in the document for Firefox to follow it, and the object
 * URL has to outlive the click by a moment or some browsers save nothing.
 */
export function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
