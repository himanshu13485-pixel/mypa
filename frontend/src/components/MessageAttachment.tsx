import { useEffect, useState } from 'react'
import { Download, FileText } from 'lucide-react'
import { clsx } from 'clsx'
import { chat } from '../api/endpoints'
import { useAuthStore } from '../stores/auth'
import { fileSize, shortName } from '../lib/attachmentLabels'

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

    const token = useAuthStore.getState().token

    fetch(chat.attachmentUrl(conversationUuid, attachmentId), {
      headers: { Authorization: `Bearer ${token}` },
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
  const url = useAttachmentUrl(conversationUuid, attachment.id, isImage)

  const download = async () => {
    const token = useAuthStore.getState().token
    const res = await fetch(chat.attachmentUrl(conversationUuid, attachment.id), {
      headers: { Authorization: `Bearer ${token}` },
    })
    const blob = await res.blob()
    const href = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = href
    link.download = attachment.name
    link.click()
    URL.revokeObjectURL(href)
  }

  if (isImage) {
    return (
      <button
        type="button"
        onClick={() => url && window.open(url, '_blank', 'noopener')}
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
