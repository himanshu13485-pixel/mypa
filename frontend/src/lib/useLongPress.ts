import { useCallback, useEffect, useRef } from 'react'
import {
  LONG_PRESS_MS, SWIPE_REPLY_PX, isDoubleTap, movedTooFar, swipeOffset, type Tap,
} from './longPress'

/**
 * Press and hold, the way a messaging app is expected to behave.
 *
 * Called once, and returns a factory: `bind(fn)` gives the props to spread
 * onto one pressable element. That shape rather than one hook per element,
 * because the elements here are rows inside a `.map()` — a hook cannot be
 * called in a loop, and lifting the whole message row into its own component
 * to make it legal would be a large refactor for a small gesture.
 *
 * Sharing one set of refs across every row is not a compromise: a press is a
 * finger, there is only ever one in flight, and a second pointer landing
 * simply takes over the first — which is what a phone does anyway.
 *
 * Pointer events rather than touch events: one set of handlers covers a
 * finger, a stylus and a mouse, and they are what the Android WebView reports
 * for all three.
 *
 * Three things have to be right or the gesture makes the app worse:
 *
 *   A scroll must not fire it. A finger flicking the thread upward is a
 *   stationary finger for the first fraction of a second, so the timer is
 *   cancelled the moment the pointer travels past the platform slop.
 *
 *   The timer must not outlive the element. A row unmounting mid-press — the
 *   conversation switched, the message deleted under the finger — would
 *   otherwise fire into something that is gone.
 *
 *   The browser's own long-press must be suppressed, but only once ours has
 *   fired. Eating every contextmenu would remove the selection callout for
 *   good; eating only the one our own press provoked leaves an ordinary
 *   tap-and-hold on a link behaving normally.
 */
export function useLongPress() {
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const origin = useRef<{ x: number; y: number } | null>(null)
  /** Set when our own press fired, so the context menu it provokes is eaten. */
  const fired = useRef(false)
  /** The last clean tap, for telling a double tap from two single ones. */
  const lastTap = useRef<Tap | null>(null)
  /** How far the bubble under the finger has been dragged, if it is a swipe. */
  const swiped = useRef(0)
  /** Once any movement has happened, the lift at the end is not a tap. */
  const moved = useRef(false)

  const clear = useCallback(() => {
    if (timer.current) clearTimeout(timer.current)
    timer.current = null
    origin.current = null
  }, [])

  /** Put a swiped bubble back where it lives, gently. */
  const settle = (el: HTMLElement) => {
    el.style.transition = 'transform 160ms ease-out'
    el.style.transform = ''
    window.setTimeout(() => { el.style.transition = '' }, 170)
  }

  // A press still in flight when the list goes away must not land on nothing.
  useEffect(() => clear, [clear])

  /*
   * Two more gestures, on the same handlers.
   *
   * A double tap and a swipe to the right are what every messenger has
   * taught people to try on a bubble, and they share the press's problem:
   * each one looks like the start of something else for the first few
   * pixels. So they live here, where the one set of handlers can decide -
   * a stationary finger that stays is a long press, one that lifts quickly
   * twice is a double tap, and one that travels sideways is a swipe - rather
   * than three handlers on one element each deciding for themselves.
   */
  return useCallback(
    (onLongPress: () => void, extra: { onDoubleTap?: () => void; onSwipeRight?: () => void } = {}) => ({
      onPointerDown: (e: React.PointerEvent) => {
        // Primary button only. A mouse user has hover and does not need this.
        if (e.button !== 0) return
        fired.current = false
        moved.current = false
        swiped.current = 0
        origin.current = { x: e.clientX, y: e.clientY }
        timer.current = setTimeout(() => {
          fired.current = true
          onLongPress()
        }, LONG_PRESS_MS)
      },
      onPointerMove: (e: React.PointerEvent) => {
        if (!origin.current) return
        const dx = e.clientX - origin.current.x
        const dy = e.clientY - origin.current.y

        if (extra.onSwipeRight) {
          const offset = swipeOffset(dx, dy)
          if (offset !== null) {
            if (timer.current) clearTimeout(timer.current)
            timer.current = null
            moved.current = true
            swiped.current = offset
            ;(e.currentTarget as HTMLElement).style.transform = `translateX(${offset}px)`

            return
          }
        }

        if (movedTooFar(origin.current, { x: e.clientX, y: e.clientY })) {
          moved.current = true
          clear()
        }
      },
      onPointerUp: (e: React.PointerEvent) => {
        const el = e.currentTarget as HTMLElement
        const wasSwipe = swiped.current
        const clean = !fired.current && !moved.current

        clear()

        if (wasSwipe) {
          settle(el)
          swiped.current = 0
          if (wasSwipe >= SWIPE_REPLY_PX) extra.onSwipeRight?.()

          return
        }

        // Only a clean tap - no hold, no travel - can be half of a double tap.
        if (!clean || !extra.onDoubleTap) return
        const tap = { t: e.timeStamp, x: e.clientX, y: e.clientY }
        if (isDoubleTap(lastTap.current, tap)) {
          lastTap.current = null
          extra.onDoubleTap()
        } else {
          lastTap.current = tap
        }
      },
      onPointerCancel: (e: React.PointerEvent) => {
        if (swiped.current) settle(e.currentTarget as HTMLElement)
        swiped.current = 0
        clear()
      },
      onPointerLeave: (e: React.PointerEvent) => {
        if (swiped.current) settle(e.currentTarget as HTMLElement)
        swiped.current = 0
        clear()
      },
      onContextMenu: (e: React.MouseEvent) => {
        if (fired.current) e.preventDefault()
      },
    }),
    [clear],
  )
}
