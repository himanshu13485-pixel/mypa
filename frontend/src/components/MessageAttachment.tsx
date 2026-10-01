import { useEffect, useState } from 'react'
import { Download, FileText, Image as ImageIcon } from 'lucide-react'
import { clsx } from 'clsx'
import { chat } from '../api/endpoints'
import { attachmentHeaders } from '../lib/chatUnlock'
import { fileSize, shortName } from '../lib/attachmentLabels'
import { useOpenedAttachments } from '../lib/attachmentsOpened'
import { saveFile } from '../lib/download'
import { inNativeShell } from '../lib/nativeBridge'
import { useToast } from './Toast'

/**
 * What an attachment looks like in a thread.
 *
 * Everything that was not audio used to render as an underlined filename, so
 * a photo arrived as `Screenshot_2026-09-02-13-33-10-96_8d795430627e417c…jpg`
 * — a line of noise you had to download to find out what it was, four of them
 * stacked into a wall of blue text. A picture should look like the picture.
 *
 * Attachments are fetched with the auth header rather than linked directly,
 * which is why this cannot simply be an <img src>: the endpoint refuses an
 * unauthenticated request, as it should.
 */

const IMAGE = /^image\//



/** Fetch an attachment as a blob URL, and let it go when the bubble does. */
function useAttachmentUrl(conversationUuid: string, attachmentId: number, enabled: boolean) {
  const [url, setUrl] = useState<string | null>(null)

  useEffect(() => {
    if (!enabled) return

    let revoked: string | null = null
    let cancelled = false

    fetch(chat.attachmentUrl(conversationUuid, attachmentId), {
      // With the chat password's proof, or a locked chat's photos stay blank.
      headers: attachmentHeaders(),
    })
      .then((r) => (r.ok ? r.blob() : Promise.reject(new Error('unavailable'))))
      .then((blob) => {
        if (cancelled) return
        revoked = URL.createObjectURL(blob)
        setUrl(revoked)
      })
      .catch(() => { /* the chip below still offers a download */ })

    return () => {
      cancelled = true
      // A thread of photos would otherwise hold every one of them in memory
      // for as long as the tab is open.
      if (revoked) URL.revokeObjectURL(revoked)
    }
  }, [conversationUuid, attachmentId, enabled])

  return url
}

export default function MessageAttachment({
  conversationUuid,
  attachment,
  own,
}: {
  conversationUuid: string
  attachment: { id: number; name: string; mime_type?: string | null; size?: number | null }
  own: boolean
}) {
  const [brokenImage, setBrokenImage] = useState(false)
  // A second tap while the first is still fetching would download it twice.
  const [busy, setBusy] = useState(false)
  const { toastError } = useToast()

  /*
   * Treated as an image only while it behaves like one.
   *
   * A file can claim image/png and not decode — a truncated upload, a format
   * this browser will not take. Rendering that as an <img> puts the alt text
   * on screen, which is the filename, which is the wall of unreadable text
   * this component exists to get rid of. It falls back to the chip instead,
   * which at least downloads.
   */
  const isImage = IMAGE.test(attachment.mime_type ?? '') && !brokenImage

  /*
   * Whoever sent it already has it.
   *
   * They chose the file off their own disk a moment ago, so their side of
   * the thread shows it at once. Everybody else asks first - a thread of
   * screenshots should not spend somebody's data before they have said they
   * want to look at them.
   */
  const markOpened = useOpenedAttachments((state) => state.markOpened)
  const taken = useOpenedAttachments((state) => state.ids.has(attachment.id))
  const show = own || taken
  const url = useAttachmentUrl(conversationUuid, attachment.id, isImage && show)

  const reveal = () => markOpened(attachment.id)

  /*
   * Taking the file down.
   *
   * Marked as taken only once it has been, and not before: the old order
   * marked it the moment the chip was tapped, so a download that failed -
   * which, inside the Android app, was every one of them - still counted as
   * having the file, and the person could forward something they had never
   * seen. Which is the whole of what the mark is for.
   */
  const download = async () => {
    if (busy) return
    setBusy(true)
    try {
      await saveFile(
        attachment.name,
        async () => {
          const res = await fetch(chat.attachmentUrl(conversationUuid, attachment.id), {
            headers: attachmentHeaders(),
          })
          // Without this a refusal is saved as the file: an error page, under
          // the right name, which looks like a corrupt download.
          if (!res.ok) throw new Error(String(res.status))

          return res.blob()
        },
        async () => (await chat.attachmentLink(conversationUuid, attachment.id)).url,
      )
      markOpened(attachment.id)
    } catch {
      toastError('That file would not download. Try again in a moment.')
    } finally {
      setBusy(false)
    }
  }

  if (isImage) {
    /*
     * Not yet asked for.
     *
     * The name and the size, and a tap to see it - which is how every
     * messenger behaves on a metered connection, and what somebody on a
     * train would choose if anybody asked them.
     */
    if (!show) {
      return (
        <button
          type="button"
          onClick={reveal}
          title={`Show ${attachment.name}`}
          className={clsx(
            'mt-1 flex h-24 w-40 flex-col items-center justify-center gap-1 rounded-lg text-xs',
            own ? 'bg-white/10 hover:bg-white/20' : 'bg-black/5 hover:bg-black/10 dark:bg-white/5 dark:hover:bg-white/10',
          )}
        >
          <ImageIcon className="size-5 opacity-70" />
          <span className="font-medium">Tap to view</span>
          {!!attachment.size && <span className="opacity-60">{fileSize(attachment.size)}</span>}
        </button>
      )
    }

    return (
      <button
        type="button"
        /*
         * Full size, or - in the app - saved.
         *
         * A new window onto a blob URL is another thing a WebView will not
         * do: Capacitor keeps blob: inside the shell on purpose, so the tap
         * opened a blank page over the thread. Saving it is what somebody
         * tapping a photo in the app actually gets from their phone.
         */
        onClick={() => (inNativeShell() ? void download() : url && window.open(url, '_blank', 'noopener'))}
        className="mt-1 block overflow-hidden rounded-lg"
        title={attachment.name}
      >
        {url ? (
          <img
            src={url}
            alt=""
            onError={() => setBrokenImage(true)}
            /* Capped so a tall screenshot cannot take over the thread, and
               sized in the bubble rather than at natural size. */
            className="max-h-64 w-auto max-w-full rounded-lg object-cover"
          />
        ) : (
          <span className="flex h-24 w-40 items-center justify-center rounded-lg bg-black/10 text-xs opacity-70">
            Loading…
          </span>
        )}
      </button>
    )
  }

  return (
    <button
      type="button"
      onClick={download}
      className={clsx(
        'mt-1 flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs',
        own ? 'bg-white/10 hover:bg-white/20' : 'bg-black/5 hover:bg-black/10 dark:bg-white/5 dark:hover:bg-white/10',
      )}
    >
      <FileText className="size-4 shrink-0 opacity-70" />
      <span className="min-w-0 flex-1">
        <span className="block truncate font-medium">{shortName(attachment.name)}</span>
        {!!attachment.size && (
          <span className="block opacity-60">{fileSize(attachment.size)}</span>
        )}
      </span>
      <Download className="size-3.5 shrink-0 opacity-60" />
    </button>
  )
}
