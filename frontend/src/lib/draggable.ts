import { useCallback, useEffect, useRef, useState } from 'react'
import type { CSSProperties, PointerEvent as ReactPointerEvent, RefObject } from 'react'

/**
 * Moving the little windows that float over the app.
 *
 * Both the minimised meeting and the call pill dock themselves into the
 * bottom-right corner, and whatever is underneath that corner — a row of a
 * table, a button, the last message in a thread — was simply unreachable
 * until the window went away. A window that floats over your work has to be
 * something you can push aside, so this makes them draggable by their own
 * chrome, remembers where each one was put, and keeps them on the screen.
 *
 * Shared rather than written twice: two corner windows that moved in two
 * slightly different ways would be two different bugs waiting to happen.
 */

const STORE_PREFIX = 'mypa-window-pos-'

/** Top-left of the window, in viewport pixels. */
interface Point {
  x: number
  y: number
}

function loadPoint(key: string): Point | null {
  try {
    const raw = localStorage.getItem(STORE_PREFIX + key)
    if (!raw) return null
    const saved = JSON.parse(raw) as Partial<Point> | null
    if (typeof saved?.x !== 'number' || typeof saved?.y !== 'number') return null
    if (!Number.isFinite(saved.x) || !Number.isFinite(saved.y)) return null

    return { x: saved.x, y: saved.y }
  } catch {
    return null
  }
}

function savePoint(key: string, point: Point) {
  try {
    localStorage.setItem(STORE_PREFIX + key, JSON.stringify(point))
  } catch {
    /* private mode — the window just goes back to its corner next time */
  }
}

/**
 * Keeps the whole window on the screen that exists now.
 *
 * A position remembered on a desktop is off the edge of a phone, and a window
 * you cannot see is a window you cannot put back, so the remembered point is
 * only ever a suggestion: it is clamped on every move and again whenever the
 * viewport changes size.
 */
function clampPoint(point: Point, width: number, height: number): Point {
  return {
    x: Math.min(Math.max(point.x, 0), Math.max(0, window.innerWidth - width)),
    y: Math.min(Math.max(point.y, 0), Math.max(0, window.innerHeight - height)),
  }
}

/** What a drag must never start on, so the controls inside still work. */
const CONTROLS = 'button, a, input, select, textarea, [role="button"], [data-no-drag]'

/**
 * Past this many pixels the gesture is a drag rather than a press.
 *
 * Without it, the shakiest tap on the window would move it a pixel and then
 * swallow its own click — see the click guard in endDrag.
 */
const DRAG_THRESHOLD = 4

/** Held for the length of a touch drag. See the comment where it is bound. */
function blockTouchScroll(e: TouchEvent) {
  e.preventDefault()
}

interface Drag {
  id: number
  /** Where in the window the pointer took hold of it. */
  dx: number
  dy: number
  width: number
  height: number
  startX: number
  startY: number
  moved: boolean
  touch: boolean
}

/**
 * Makes a floating window draggable by its own chrome.
 *
 * `ref` is the window itself — the same element the returned props go on, and
 * the one measured when the viewport changes. `key` names the slot the
 * position is remembered under, per browser. While `enabled` is false nothing
 * is applied at all, which is how the call panel goes back to being a
 * centred window or a fullscreen one without carrying a corner position into
 * either of them.
 */
export function useDraggableWindow(ref: RefObject<HTMLElement | null>, key: string, enabled: boolean) {
  const [pos, setPos] = useState<Point | null>(() => loadPoint(key))
  const dragRef = useRef<Drag | null>(null)

  useEffect(() => {
    if (pos) savePoint(key, pos)
  }, [key, pos])

  /* A phone rotated, a desktop window pulled narrow, or simply a position
     remembered on a bigger screen than this one: whatever the window was
     reaching before, it has to be reachable now. Hence once as it appears as
     well as on every resize — the remembered point is the only one nobody has
     had a chance to clamp yet. */
  useEffect(() => {
    if (!enabled) return
    const reclamp = () => {
      const el = ref.current
      if (!el) return
      setPos((cur) => (cur ? clampPoint(cur, el.offsetWidth, el.offsetHeight) : cur))
    }
    reclamp()
    window.addEventListener('resize', reclamp)

    return () => window.removeEventListener('resize', reclamp)
  }, [enabled, ref])

  // A gesture cut short by an unmount must not leave the page unscrollable.
  useEffect(() => () => document.removeEventListener('touchmove', blockTouchScroll), [])

  const onPointerDown = useCallback((e: ReactPointerEvent<HTMLElement>) => {
    // A second finger while one is already moving the window would fight it.
    if (!enabled || dragRef.current || (e.pointerType === 'mouse' && e.button !== 0)) return
    const hit = (e.target as HTMLElement).closest(CONTROLS)
    // The window's big tappable areas opt back in, so the window can be taken
    // hold of anywhere that is not an actual control.
    if (hit && !hit.hasAttribute('data-drag-handle')) return

    const el = e.currentTarget
    const box = el.getBoundingClientRect()
    dragRef.current = {
      id: e.pointerId,
      dx: e.clientX - box.left,
      dy: e.clientY - box.top,
      width: box.width,
      height: box.height,
      startX: e.clientX,
      startY: e.clientY,
      moved: false,
      touch: e.pointerType === 'touch',
    }
    /* Without the capture a pointer leaving the window stops reporting, and
       the gesture would never be told it had ended — so a browser that refuses
       it does not get a half-started drag. */
    try {
      el.setPointerCapture(e.pointerId)
    } catch {
      dragRef.current = null
      return
    }

    /*
     * A finger moving the window must not also scroll the page under it.
     *
     * Bound for the gesture rather than declared as touch-action on the
     * window: touch-action is read down the whole ancestor chain, so turning
     * it off on the window would take their own scrolling away from the
     * filmstrip and the device pickers that live inside it.
     */
    if (dragRef.current.touch) {
      document.addEventListener('touchmove', blockTouchScroll, { passive: false })
    }
  }, [enabled])

  const onPointerMove = useCallback((e: ReactPointerEvent<HTMLElement>) => {
    const drag = dragRef.current
    if (!drag || drag.id !== e.pointerId) return
    if (!drag.moved) {
      if (Math.abs(e.clientX - drag.startX) + Math.abs(e.clientY - drag.startY) < DRAG_THRESHOLD) return
      drag.moved = true
    }
    setPos(clampPoint({ x: e.clientX - drag.dx, y: e.clientY - drag.dy }, drag.width, drag.height))
  }, [])

  const endDrag = useCallback((e: ReactPointerEvent<HTMLElement>) => {
    const drag = dragRef.current
    if (!drag || drag.id !== e.pointerId) return
    dragRef.current = null
    if (drag.touch) document.removeEventListener('touchmove', blockTouchScroll)
    if (e.currentTarget.hasPointerCapture(e.pointerId)) e.currentTarget.releasePointerCapture(e.pointerId)
    if (!drag.moved) return

    /* Letting go of a window over one of its own buttons should not press it.
       The click that closes this gesture is swallowed; if none comes, the
       guard is dropped again on the next turn of the loop. */
    const swallow = (click: MouseEvent) => {
      click.stopPropagation()
      click.preventDefault()
    }
    window.addEventListener('click', swallow, { capture: true, once: true })
    window.setTimeout(() => window.removeEventListener('click', swallow, true), 0)
  }, [])

  /* Until it has been moved the window keeps the corner its own classes put it
     in; once it has, the inline pair has to unset the other two edges, or the
     Tailwind bottom/right would go on pinning it to the corner it just left. */
  const style: CSSProperties | undefined = enabled && pos
    ? { left: pos.x, top: pos.y, right: 'auto', bottom: 'auto' }
    : undefined

  return {
    /** Spread onto the window itself, which is also what `ref` points at. */
    dragProps: {
      style,
      onPointerDown,
      onPointerMove,
      onPointerUp: endDrag,
      onPointerCancel: endDrag,
      /* The belt to the other two's braces: losing the capture is the one way
         a gesture ends without either of them, and a drag still believed to be
         running holds the page's own scrolling hostage. */
      onLostPointerCapture: endDrag,
    },
  }
}
