import { useLayoutEffect, useRef } from 'react'
import { useLocation, useNavigationType } from 'react-router-dom'

/**
 * Where a navigation should land, given how it happened.
 *
 * Pulled out as a plain function because it is the whole policy and the rest
 * of the hook is wiring. PUSH and REPLACE are somebody asking for something
 * new, and new things start at the top; POP is the back button, where the
 * right answer is wherever they were. A POP with nothing remembered — a
 * reload, or a link pasted into a fresh tab — is a first visit as far as
 * anyone can tell, so it starts at the top too.
 */
export function scrollTargetFor(navigationType: string, remembered: number | undefined): number {
  return navigationType === 'POP' ? remembered ?? 0 : 0
}

/**
 * Remember where each page was left, and put the next one where it belongs.
 *
 * The app scrolls inside <main>, not the window, and <main> is never unmounted
 * — the same element serves every page. So its scrollTop simply carried over:
 * scroll halfway down a long task list, open Notes, and Notes opened halfway
 * down, at a position that meant nothing there. The router's own scroll
 * restoration does not help, because it only knows about the document.
 *
 * useLayoutEffect rather than useEffect, so the position is set in the same
 * frame the new page paints. In an effect the browser paints the old scroll
 * position first and the correction is visible as a jump.
 */
export function useScrollRestoration(container: React.RefObject<HTMLElement | null>) {
  const { key } = useLocation()
  const navigationType = useNavigationType()
  const positions = useRef(new Map<string, number>())

  useLayoutEffect(() => {
    const node = container.current
    if (!node) return

    // Read out of the ref here rather than in the cleanup: by the time a
    // cleanup runs the ref may point at something else, and the map this
    // entry belongs to is the one that existed on the way in.
    const remembered = positions.current

    node.scrollTo({
      top: scrollTargetFor(navigationType, remembered.get(key)),
      behavior: 'instant' as ScrollBehavior,
    })

    return () => {
      remembered.set(key, node.scrollTop)
    }
  }, [container, key, navigationType])
}
