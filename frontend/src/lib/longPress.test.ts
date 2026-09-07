import { describe, expect, it } from 'vitest'
import { MOVE_CANCEL_PX, movedTooFar } from './longPress'

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
