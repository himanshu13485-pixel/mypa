import { describe, expect, it } from 'vitest'
import {
  DOUBLE_TAP_MS, MOVE_CANCEL_PX, SWIPE_MAX_PX, isDoubleTap, movedTooFar, swipeOffset,
} from './longPress'

describe('movedTooFar', () => {
  it('forgives the wobble in a thumb that is trying to hold still', () => {
    expect(movedTooFar({ x: 100, y: 200 }, { x: 103, y: 204 })).toBe(false)
  })

  it('treats a flick up the thread as a scroll, not a press', () => {
    // The case that makes a naive long-press unusable: every slow scroll
    // starts as a stationary finger.
    expect(movedTooFar({ x: 100, y: 200 }, { x: 100, y: 160 })).toBe(true)
  })

  it('counts sideways drift too', () => {
    expect(movedTooFar({ x: 100, y: 200 }, { x: 140, y: 200 })).toBe(true)
  })

  it('is exclusive at the threshold, so exactly-slop still counts as held', () => {
    const at = { x: 100 + MOVE_CANCEL_PX, y: 200 }
    expect(movedTooFar({ x: 100, y: 200 }, at)).toBe(false)
    expect(movedTooFar({ x: 100, y: 200 }, { x: at.x + 1, y: 200 })).toBe(true)
  })

  it('takes a slop of its own when asked', () => {
    expect(movedTooFar({ x: 0, y: 0 }, { x: 5, y: 0 }, 2)).toBe(true)
    expect(movedTooFar({ x: 0, y: 0 }, { x: 5, y: 0 }, 20)).toBe(false)
  })
})

describe('isDoubleTap', () => {
  const first = { t: 1000, x: 100, y: 200 }

  it('pairs two taps close together in time and place', () => {
    expect(isDoubleTap(first, { t: 1200, x: 104, y: 198 })).toBe(true)
  })

  it('lets a slow second tap be a tap of its own', () => {
    expect(isDoubleTap(first, { t: 1000 + DOUBLE_TAP_MS + 1, x: 100, y: 200 })).toBe(false)
  })

  it('does not pair taps on two different messages', () => {
    // Quick, but a message apart: two separate taps.
    expect(isDoubleTap(first, { t: 1150, x: 100, y: 280 })).toBe(false)
  })

  it('needs a first tap to be a second one', () => {
    expect(isDoubleTap(null, first)).toBe(false)
  })
})

describe('swipeOffset', () => {
  it('follows a deliberate drag to the right', () => {
    expect(swipeOffset(40, 4)).toBe(40)
  })

  it('stops following a little past the point that counts', () => {
    expect(swipeOffset(200, 0)).toBe(SWIPE_MAX_PX)
  })

  it('ignores the sideways drift of a thumb that is scrolling', () => {
    // Mostly vertical: a scroll, not a swipe.
    expect(swipeOffset(20, 30)).toBeNull()
  })

  it('ignores anything leftwards', () => {
    expect(swipeOffset(-50, 0)).toBeNull()
  })

  it('ignores the wobble below the slop', () => {
    expect(swipeOffset(MOVE_CANCEL_PX, 0)).toBeNull()
  })
})
