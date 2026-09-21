/**
 * How long a press has to last before it counts as a long press.
 *
 * WhatsApp, Telegram and the Android platform itself all sit around half a
 * second. Shorter and an ordinary tap that lingers — which is most taps made
 * with a thumb while walking — starts opening menus nobody asked for; longer
 * and the gesture feels broken, because the person has already decided it
 * didn't work and lifted their finger.
 */
export const LONG_PRESS_MS = 450

/**
 * How far a finger may drift before the press is a scroll instead.
 *
 * This is the whole reason a naive long-press implementation ruins a message
 * list: a finger resting on a bubble to flick the thread upward is, for the
 * first fifty milliseconds, indistinguishable from a press. Without a
 * movement threshold every scroll that begins slowly opens a menu, which is
 * far more annoying than no long-press at all.
 *
 * Ten pixels is roughly the platform slop value — big enough to forgive the
 * wobble in a stationary thumb, small enough that a deliberate scroll is
 * already past it before the timer fires.
 */
export const MOVE_CANCEL_PX = 10

/** Has the finger moved far enough that this is a scroll, not a press? */
export function movedTooFar(
  from: { x: number; y: number },
  to: { x: number; y: number },
  slop = MOVE_CANCEL_PX,
): boolean {
  return Math.abs(to.x - from.x) > slop || Math.abs(to.y - from.y) > slop
}

/**
 * How close together two taps must land to be one double tap.
 *
 * Three hundred milliseconds is what the platforms use; the distance keeps
 * two quick taps on two different messages from counting as one gesture.
 */
export const DOUBLE_TAP_MS = 300
export const DOUBLE_TAP_PX = 24

export type Tap = { t: number; x: number; y: number }

/** Is this tap the second half of a double tap? */
export function isDoubleTap(previous: Tap | null, now: Tap): boolean {
  if (!previous) return false

  return now.t - previous.t <= DOUBLE_TAP_MS
    && Math.abs(now.x - previous.x) <= DOUBLE_TAP_PX
    && Math.abs(now.y - previous.y) <= DOUBLE_TAP_PX
}

/**
 * How far a bubble has to be dragged to the right to become a reply.
 *
 * Far enough that nobody does it by accident while scrolling, near enough
 * that a thumb reaches it without a second go - and the bubble stops
 * following a little past it, so the answer to "is that enough?" is visible.
 */
export const SWIPE_REPLY_PX = 64
export const SWIPE_MAX_PX = 88

/**
 * How far to draw a bubble being swiped, or null when this is not a swipe.
 *
 * Only rightwards, and only when the finger is travelling mostly sideways:
 * a thumb scrolling the thread drifts a few pixels left and right the whole
 * time, and every one of those drifts reading as a swipe would make the
 * thread shudder under it.
 */
export function swipeOffset(dx: number, dy: number): number | null {
  if (dx <= MOVE_CANCEL_PX) return null
  if (Math.abs(dy) * 1.5 >= dx) return null

  return Math.min(dx, SWIPE_MAX_PX)
}
