import { useEffect, useRef, useState } from 'react'

/**
 * An email's HTML, shown safely.
 *
 * A sandboxed frame - no scripts, no forms, no top-window navigation - with
 * a content policy written into the document itself, so the page can load
 * nothing it was not given. Remote images are the exception people choose:
 * blocked until they press "Show images", because a remote image in mail is
 * as often a tracking pixel as a picture.
 *
 * Same-origin is allowed only so the frame can be measured and grown to fit
 * its mail; without scripts, it grants the mail nothing.
 */
export default function MailFrame({ html, text, allowImages, onBlockedImages }: {
  html: string | null
  text: string | null
  allowImages: boolean
  /** Told when the mail holds remote images that were held back. */
  onBlockedImages?: (blocked: boolean) => void
}) {
  const frame = useRef<HTMLIFrameElement>(null)
  const [height, setHeight] = useState(120)

  const body = html && html.trim() !== ''
    ? html
    : `<div style="white-space:pre-wrap">${(text ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')}</div>`

  const hasRemote = /<img[^>]+src=["']?https?:/i.test(body) || /url\(\s*['"]?https?:/i.test(body)

  useEffect(() => {
    onBlockedImages?.(hasRemote && !allowImages)
  }, [hasRemote, allowImages, onBlockedImages])

  // blob: too - a picture sent inside the message is fetched through the
  // signed-in API and handed to the frame as a blob, never as a remote URL,
  // so it shows even while remote images are held back.
  const images = allowImages ? 'https: http: data: blob: cid:' : 'data: blob: cid:'
  const doc = `<!doctype html><html><head><meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src ${images}; style-src 'unsafe-inline'; font-src data:; media-src 'none'">
<base target="_blank">
<style>
  html,body{margin:0;padding:0;background:#fff;color:#0f172a}
  body{font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;padding:4px 2px;overflow-wrap:anywhere}
  img{max-width:100%;height:auto}
  table{max-width:100%}
  a{color:#2b4de4}
  blockquote{margin:0 0 0 4px;padding-left:12px;border-left:3px solid #cbd5e1;color:#475569}
  pre{white-space:pre-wrap}
</style></head><body>${body}</body></html>`

  // Grown to fit, and again when late images or fonts change its height.
  const measure = () => {
    const d = frame.current?.contentDocument
    if (!d) return
    const h = Math.max(d.documentElement.scrollHeight, d.body?.scrollHeight ?? 0)
    setHeight(Math.min(Math.max(h + 8, 60), 20000))
  }

  useEffect(() => {
    const el = frame.current
    if (!el) return
    let observer: ResizeObserver | null = null
    const onLoad = () => {
      measure()
      const d = el.contentDocument
      if (d?.body && 'ResizeObserver' in window) {
        observer = new ResizeObserver(measure)
        observer.observe(d.body)
      }
    }
    el.addEventListener('load', onLoad)
    return () => {
      el.removeEventListener('load', onLoad)
      observer?.disconnect()
    }
  }, [doc])

  return (
    <iframe
      ref={frame}
      title="Email"
      sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
      srcDoc={doc}
      style={{ height }}
      className="w-full rounded-lg border-0 bg-white"
    />
  )
}
