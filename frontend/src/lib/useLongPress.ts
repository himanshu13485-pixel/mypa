import { useCallback, useEffect, useRef } from 'react'
import { LONG_PRESS_MS, movedTooFar } from './longPress'

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

  const clear = useCallback(() => {
    if (timer.current) clearTimeout(timer.current)
    timer.current = null
    origin.current = null
  }, [])

  // A press still in flight when the list goes away must not land on nothing.
  useEffect(() => clear, [clear])

  return useCallback(
    (onLongPress: () => void) => ({
      onPointerDown: (e: React.PointerEvent) => {
        // Primary button only. A mouse user has hover and does not need this.
        if (e.button !== 0) return
        fired.current = false
        origin.current = { x: e.clientX, y: e.clientY }
        timer.current = setTimeout(() => {
          fired.current = true
          onLongPress()
        }, LONG_PRESS_MS)
      },
      onPointerMove: (e: React.PointerEvent) => {
        if (!origin.current) return
        if (movedTooFar(origin.current, { x: e.clientX, y: e.clientY })) clear()
      },
      onPointerUp: clear,
      onPointerCancel: clear,
      onPointerLeave: clear,
      onContextMenu: (e: React.MouseEvent) => {
        if (fired.current) e.preventDefault()
      },
    }),
    [clear],
  )
}
