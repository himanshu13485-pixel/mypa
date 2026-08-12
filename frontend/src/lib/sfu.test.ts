import { describe, expect, it } from 'vitest'
import { DEMOTE_AFTER_MS, FOCUS_LIMIT, focusQualities } from './sfu'

const T0 = 1_000_000

/** Everyone in the room, in the order the tile grid ranked them. */
const room = (...uuids: string[]) => uuids

describe('focusQualities', () => {
  it('gives full quality to the first four and the small layer to the rest', () => {
    const q = focusQualities(room('a', 'b', 'c', 'd', 'e', 'f'), room('a', 'b', 'c', 'd', 'e', 'f'), new Map(), T0)

    expect([...q.values()]).toEqual(['high', 'high', 'high', 'high', 'low', 'low'])
    expect(FOCUS_LIMIT).toBe(4)
  })

  it('gives the pinned tile the best picture', () => {
    // rankTiles puts pinned first, so this is the whole of it — but it is the
    // one guarantee somebody actually notices, so it gets its own test.
    const q = focusQualities(room('pinned', 'b', 'c', 'd', 'e'), room('e', 'd', 'c', 'b', 'pinned'), new Map(), T0)
    expect(q.get('pinned')).toBe('high')
  })

  it('leaves a small room entirely at full quality', () => {
    const q = focusQualities(room('a', 'b'), room('a', 'b'), new Map(), T0)
    expect([...q.values()]).toEqual(['high', 'high'])
  })

  it('does not drop somebody the moment they stop speaking', () => {
    /*
     * The reason the hold exists. A conversation between five people reorders
     * the focus every time the speaker changes, and every quality change costs
     * a keyframe — which is the flicker. Someone who was just large stays
     * large for a while.
     */
    const held = new Map<string, number>()
    const all = room('a', 'b', 'c', 'd', 'e')
    focusQualities(all, all, held, T0)

    // 'a' drops out of the focus a second later.
    const q = focusQualities(room('b', 'c', 'd', 'e', 'a'), all, held, T0 + 1_000)
    expect(q.get('a')).toBe('high')
  })

  it('does drop them once the hold has passed', () => {
    const held = new Map<string, number>()
    const all = room('a', 'b', 'c', 'd', 'e')
    focusQualities(all, all, held, T0)

    const q = focusQualities(room('b', 'c', 'd', 'e', 'a'), all, held, T0 + DEMOTE_AFTER_MS + 1)
    expect(q.get('a')).toBe('low')
  })

  it('a speaker swapping back and forth costs nothing', () => {
    // Two people talking over each other for ten seconds must not renegotiate
    // anybody's layer. Every answer here should be identical to the last.
    const held = new Map<string, number>()
    const all = room('a', 'b', 'c', 'd', 'e')
    const answers: string[] = []
    for (let i = 0; i < 10; i++) {
      const order = i % 2 ? room('a', 'b', 'c', 'd', 'e') : room('b', 'a', 'c', 'd', 'e')
      answers.push([...focusQualities(order, all, held, T0 + i * 1_000).values()].join(','))
    }
    expect(new Set(answers).size).toBe(1)
  })

  it('forgets somebody who has left', () => {
    // Otherwise they keep a seat in the map for the rest of the meeting and
    // walk back in on full quality however long they were away.
    const held = new Map<string, number>()
    focusQualities(room('a', 'b'), room('a', 'b'), held, T0)
    expect(held.has('a')).toBe(true)

    focusQualities(room('b'), room('b'), held, T0 + 1_000)
    expect(held.has('a')).toBe(false)
  })

  it('answers only for people who are actually there', () => {
    // The ranking is of tiles, which can lag the server's list either way.
    const q = focusQualities(room('a', 'ghost'), room('a'), new Map(), T0)
    expect([...q.keys()]).toEqual(['a'])
  })
})
