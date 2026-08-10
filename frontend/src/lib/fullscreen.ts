/**
 * Fullscreen, for browsers that have it and the ones that do not.
 *
 * Every call site used to be `ref.current?.requestFullscreen().catch(() => undefined)`,
 * which looks defensive and is not. The `?.` only guards the ref being null;
 * on a browser without the API the element is perfectly real and simply has no
 * such method, so the call throws a TypeError before any promise exists and
 * the .catch never runs. The button did nothing and reported an error every
 * time it was pressed.
 *
 * That browser is every iPhone. iOS Safari implements fullscreen only on video
 * elements, via webkitEnterFullscreen — and using it here would be worse than
 * nothing: it fullscreens the picture alone, leaving mute, camera, chat and
 * leave outside the fullscreen element, which is the exact bug the meeting
 * room was fixed for once already. So there is no fallback. The button is not
 * offered, and landscape immersive mode is what a phone gets instead.
 */

interface WebkitDocument extends Document {
  webkitFullscreenElement?: Element | null
  webkitExitFullscreen?: () => Promise<void> | void
}

interface WebkitElement extends HTMLElement {
  webkitRequestFullscreen?: () => Promise<void> | void
}

/** Can this browser put an arbitrary element fullscreen? */
export function fullscreenSupported(): boolean {
  if (typeof document === 'undefined') return false
  const el = document.documentElement as WebkitElement

  // fullscreenEnabled is false inside an iframe that was not allowed it, which
  // is a correct "no" — asking would be refused.
  const enabled = document.fullscreenEnabled ?? true

  return enabled && (typeof el.requestFullscreen === 'function'
    || typeof el.webkitRequestFullscreen === 'function')
}

/** The element currently fullscreen, if any. */
export function fullscreenElement(): Element | null {
  const doc = document as WebkitDocument
  return doc.fullscreenElement ?? doc.webkitFullscreenElement ?? null
}

/**
 * Go fullscreen, or don't. Never throws, and reports whether it worked so a
 * caller can say something rather than appear to have ignored the click.
 */
export async function enterFullscreen(el: HTMLElement | null): Promise<boolean> {
  if (!el) return false
  const target = el as WebkitElement
  const request = target.requestFullscreen ?? target.webkitRequestFullscreen
  if (typeof request !== 'function') return false

  try {
    await request.call(target)
    return true
  } catch {
    // Refused — a permissions policy, or no user gesture behind it.
    return false
  }
}

/** Leave fullscreen. Safe to call when not in it. */
export async function exitFullscreen(): Promise<void> {
  const doc = document as WebkitDocument
  const exit = doc.exitFullscreen ?? doc.webkitExitFullscreen
  if (typeof exit !== 'function') return

  try {
    await exit.call(doc)
  } catch {
    /* already out, or refused — either way there is nothing to do */
  }
}

/**
 * Subscribe to fullscreen changes, both spellings. Returns the unsubscribe.
 */
export function onFullscreenChange(handler: () => void): () => void {
  document.addEventListener('fullscreenchange', handler)
  document.addEventListener('webkitfullscreenchange', handler)

  return () => {
    document.removeEventListener('fullscreenchange', handler)
    document.removeEventListener('webkitfullscreenchange', handler)
  }
}
