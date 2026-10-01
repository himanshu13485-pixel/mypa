import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { Download, FileText, Image as ImageIcon, X } from 'lucide-react'
import { clsx } from 'clsx'
import { chat } from '../api/endpoints'
import { attachmentHeaders } from '../lib/chatUnlock'
import { fileSize, shortName } from '../lib/attachmentLabels'
import { useOpenedAttachments } from '../lib/attachmentsOpened'
import { saveFile } from '../lib/download'
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

/**
 * A picture opened full size.
 *
 * It used to be `window.open` onto the blob, which hands the file to the
 * browser as a bare document - so a phone screenshot four thousand pixels
 * wide arrived four thousand pixels wide, and you were left panning around a
 * corner of it. Inside the Android app it was worse than that: Capacitor
 * keeps blob: URLs in the shell on purpose, so the tap opened a blank page.
 *
 * Shown in the app instead, fitted to whatever screen it is on, with the way
 * out and the way to save it both on screen.
 */
function ImageViewer({ name, url, busy, onDownload, onClose }: {
  name: string
  url: string | null
  busy: boolean
  onDownload: () => void
  onClose: () => void
}) {
  // Escape closes it, as it closes everything else in the app.
  useEffect(() => {
    const key = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', key)

    return () => window.removeEventListener('keydown', key)
  }, [onClose])

  /*
   * Taken out to the body.
   *
   * This is rendered from inside a message bubble, and a bubble sits under
   * ancestors that are transformed and clipped - either of which turns a
   * fixed overlay back into a box inside the bubble. Which is how a
   * full-screen viewer ends up three inches wide.
   */
  return createPortal(
    <div
      className="fixed inset-0 z-50 flex flex-col bg-black/90"
      role="dialog"
      aria-modal="true"
      aria-label={name}
    >
      <div className="flex items-center gap-2 p-3 text-white">
        <span className="min-w-0 flex-1 truncate text-sm">{shortName(name)}</span>
        <button
          type="button"
          onClick={onDownload}
          disabled={busy}
          className="tap rounded-lg p-2 hover:bg-white/10 disabled:opacity-50"
          aria-label="Download"
          title="Download"
        >
          <Download className="size-5" />
        </button>
        <button
          type="button"
          onClick={onClose}
          className="tap rounded-lg p-2 hover:bg-white/10"
          aria-label="Close"
          title="Close"
        >
          <X className="size-5" />
        </button>
      </div>
      {/* A tap anywhere off the picture closes it, which is the gesture
          every photo viewer on a phone answers to. */}
      <button
        type="button"
        onClick={onClose}
        className="flex min-h-0 flex-1 cursor-default items-center justify-center p-3"
        aria-label="Close"
      >
        {url ? (
          <img
            src={url}
            alt={name}
            /* Fitted rather than natural size: whatever the picture is, it
               belongs inside this screen. */
            className="max-h-full max-w-full object-contain"
            onClick={(e) => e.stopPropagation()}
          />
        ) : (
          <span className="text-sm text-white/70">Loading…</span>
        )}
      </button>
    </div>,
    document.body,
  )
}



/**
 * Fetch an attachment as a blob URL, and let it go when the bubble does.
 *
 * `thumb` asks the server for the small copy, which is what a bubble wants:
 * a phone photo is megabytes and thousands of pixels wide, and spending all
 * of that to draw something a couple of hundred pixels across is what left
 * three pictures in a row sitting on "Loading…".
 *
 * A failure is reported rather than swallowed. It used to be ignored with a
 * note saying the chip below still offered a download - but there is no chip
 * below in the image case, so a picture that would not load said "Loading…"
 * for as long as the tab stayed open, with no way to reach the file at all.
 */
function useAttachmentUrl(
  conversationUuid: string,
  attachmentId: number,
  enabled: boolean,
  { thumb = false, onFail }: { thumb?: boolean; onFail?: () => void } = {},
) {
  const [url, setUrl] = useState<string | null>(null)

  useEffect(() => {
    if (!enabled) return

    let revoked: string | null = null
    let cancelled = false

    fetch(chat.attachmentUrl(conversationUuid, attachmentId) + (thumb ? '?thumb=1' : ''), {
      // With the chat password's proof, or a locked chat's photos stay blank.
      headers: attachmentHeaders(),
    })
      .then((r) => (r.ok ? r.blob() : Promise.reject(new Error('unavailable'))))
      .then((blob) => {
        if (cancelled) return
        revoked = URL.createObjectURL(blob)
        setUrl(revoked)
      })
      .catch(() => { if (!cancelled) onFail?.() })

    return () => {
      cancelled = true
      // A thread of photos would otherwise hold every one of them in memory
      // for as long as the tab is open.
      if (revoked) URL.revokeObjectURL(revoked)
    }
    // onFail is a setter from useState, stable for the life of the component.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [conversationUuid, attachmentId, enabled, thumb])

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
  /* Open full size, over the thread. */
  const [viewing, setViewing] = useState(false)
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
  // The bubble gets the small copy; the full one is fetched only when
  // somebody actually opens the picture.
  const url = useAttachmentUrl(conversationUuid, attachment.id, isImage && show, {
    thumb: true,
    onFail: () => setBrokenImage(true),
  })
  const fullUrl = useAttachmentUrl(conversationUuid, attachment.id, isImage && viewing)

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
      <>
        <button
          type="button"
          onClick={() => setViewing(true)}
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
        {viewing && (
          <ImageViewer
            name={attachment.name}
            url={fullUrl}
            busy={busy}
            onDownload={download}
            onClose={() => setViewing(false)}
          />
        )}
      </>
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
