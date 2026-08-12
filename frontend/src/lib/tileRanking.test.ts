import { describe, expect, it } from 'vitest'
import { MAX_VIDEO_TILES, rankTiles } from './videoLayout'

/**
 * Who gets a tile when there are more people than slots.
 *
 * Ranked rather than truncated: taking the first nine in join order would drop
 * whoever is speaking the moment a tenth person arrives, which is exactly
 * backwards. The person worth looking at is the one talking.
 */
const people = (n: number) => Array.from({ length: n }, (_, i) => ({ uuid: `u${i}`, name: `P${i}` }))

describe('rankTiles', () => {
  it('shows everybody when they fit', () => {
    const { visible, overflow } = rankTiles(people(MAX_VIDEO_TILES))
    expect(visible).toHaveLength(MAX_VIDEO_TILES)
    expect(overflow).toHaveLength(0)
  })

  it('caps at the limit and leaves the rest over', () => {
    // Derived from the constant, not restated: these asserted "caps at nine"
    // with literal nines, so lowering the cap to four broke three tests that
    // were all making the same true claim.
    const { visible, overflow } = rankTiles(people(20))
    expect(visible).toHaveLength(MAX_VIDEO_TILES)
    expect(overflow).toHaveLength(20 - MAX_VIDEO_TILES)
  })

  it('brings whoever is talking on screen, however late they joined', () => {
    // The case truncation gets wrong: person 15 speaks and is not shown.
    const { visible } = rankTiles(people(20), { activeSpeaker: 'u15' })
    expect(visible.map((p) => p.uuid)).toContain('u15')
    expect(visible[0].uuid).toBe('u15')
  })

  it('keeps a pin above everything, including the current speaker', () => {
    // An explicit choice by the viewer outranks anything automatic — being
    // pushed off your own pin because somebody coughed would be maddening.
    const { visible } = rankTiles(people(20), { pinned: 'u17', activeSpeaker: 'u3' })
    expect(visible[0].uuid).toBe('u17')
    expect(visible.map((p) => p.uuid)).toContain('u3')
  })

  it("keeps the host's spotlight on screen", () => {
    const { visible } = rankTiles(people(20), { spotlight: 'u12' })
    expect(visible.map((p) => p.uuid)).toContain('u12')
  })

  it('holds recent speakers so a conversation does not flicker', () => {
    // Two people going back and forth should both stay visible rather than
    // swapping places every time the other one starts.
    const { visible } = rankTiles(people(20), {
      activeSpeaker: 'u18',
      recentSpeakers: ['u19', 'u16'],
    })
    const shown = visible.map((p) => p.uuid)
    expect(shown).toContain('u18')
    expect(shown).toContain('u19')
    expect(shown).toContain('u16')
  })

  it('ranks recent speakers by how recently they spoke', () => {
    const { visible } = rankTiles(people(20), { recentSpeakers: ['u9', 'u5'] })
    expect(visible.map((p) => p.uuid).indexOf('u9'))
      .toBeLessThan(visible.map((p) => p.uuid).indexOf('u5'))
  })

  it('does not reshuffle when nothing has changed', () => {
    // Equal ranks keep join order, so the grid stays put instead of dancing
    // about every time the roster is recomputed.
    const first = rankTiles(people(20)).visible.map((p) => p.uuid)
    const second = rankTiles(people(20)).visible.map((p) => p.uuid)
    expect(second).toEqual(first)
    expect(first).toEqual(people(MAX_VIDEO_TILES).map((p) => p.uuid))
  })

  it('loses nobody — everyone is either shown or counted', () => {
    const all = people(30)
    const { visible, overflow } = rankTiles(all, { activeSpeaker: 'u25', pinned: 'u28' })
    expect(visible.length + overflow.length).toBe(30)
    expect(new Set([...visible, ...overflow].map((p) => p.uuid)).size).toBe(30)
  })

  it('copes with an empty room and with a limit of one', () => {
    expect(rankTiles([]).visible).toHaveLength(0)
    expect(rankTiles(people(5), { limit: 1 }).visible).toHaveLength(1)
  })
})
