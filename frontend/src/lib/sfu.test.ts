import { describe, expect, it } from 'vitest'
import { nextQuality, type ReceiveQuality } from './sfu'

/** Walk a room through a sequence of sizes and collect what it settles on. */
const walk = (sizes: number[], from: ReceiveQuality = 'high') =>
  sizes.map((n) => (from = nextQuality(from, n)))

describe('nextQuality', () => {
  it('gives a small room everything', () => {
    // Two people on a large tile each is the case that should look best, and
    // the one that looked worst — a library judging the tile small served the
    // low layer by design.
    expect(walk([1, 2, 3, 4])).toEqual(['high', 'high', 'high', 'high'])
  })

  it('steps down as the room fills', () => {
    expect(walk([4, 5, 8])).toEqual(['high', 'medium', 'low'])
  })

  it('does not switch back and forth around a boundary', () => {
    /*
     * The whole point. Somebody joining and leaving a four-person meeting
     * repeatedly must not renegotiate every time — each switch costs a
     * keyframe, and a keyframe is the flicker people were reporting.
     */
    const settled = walk([4, 5, 4, 5, 4, 5])
    expect(settled).toEqual(['high', 'medium', 'medium', 'medium', 'medium', 'medium'])
  })

  it('recovers quality once the room is properly smaller, not merely one under', () => {
    // Down at five, back up at three: a gap wide enough that ordinary comings
    // and goings do not cross it, but a meeting that genuinely emptied out
    // does not stay pinned to a low layer for the rest of its life.
    expect(walk([5, 4])).toEqual(['medium', 'medium'])
    expect(walk([5, 3])).toEqual(['medium', 'high'])
  })

  it('has a gap at the lower rung too', () => {
    // Starting from low, because it takes two steps to get there from high —
    // see the rung test below. Down at eight, back up at six.
    expect(walk([8, 7], 'low')).toEqual(['low', 'low'])
    expect(walk([8, 6], 'low')).toEqual(['low', 'medium'])
  })

  it('reaches the bottom rung by passing through the middle one', () => {
    // A room that jumps from four people to nine does not go straight to the
    // low layer; it steps to medium, then to low on the next event. LiveKit
    // fires one for every arrival, so a genuinely large room gets there
    // immediately — but it arrives by steps rather than by a single lurch.
    expect(walk([9, 9])).toEqual(['medium', 'low'])
  })

  it('never skips a rung, so quality moves one step at a time', () => {
    // A room going from two people to twenty in one beat steps to medium, then
    // to low on the next event. Jumping straight to the bottom would be a
    // bigger visible change than the room actually calls for.
    expect(nextQuality('high', 40)).toBe('medium')
    expect(nextQuality('low', 1)).toBe('medium')
  })

  it('is stable when nothing has changed', () => {
    // Called on every LiveKit event, including someone muting. Returning a new
    // answer there would re-request the layer and cost a keyframe for nothing.
    for (const n of [1, 4, 5, 7, 9, 30]) {
      const settled = nextQuality(nextQuality('high', n), n)
      expect(nextQuality(settled, n)).toBe(settled)
    }
  })
})
