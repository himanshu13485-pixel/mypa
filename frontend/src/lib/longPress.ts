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
