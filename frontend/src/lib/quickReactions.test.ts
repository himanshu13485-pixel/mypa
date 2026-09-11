import { beforeEach, describe, expect, it } from 'vitest'
import { DEFAULT_REACTIONS, quickReactions, recordReaction, THUMBS_UP } from './quickReactions'

/*
 * These tests run under node, where there is no localStorage - the suite has
 * no DOM environment and does not need one for anything else. A dozen lines
 * of Map stand in for it rather than pulling in jsdom for one key.
 */
const store = new Map<string, string>()
Object.defineProperty(globalThis, 'localStorage', {
  configurable: true,
  value: {
    getItem: (k: string) => store.get(k) ?? null,
    setItem: (k: string, v: string) => void store.set(k, String(v)),
    removeItem: (k: string) => void store.delete(k),
    clear: () => store.clear(),
  },
})

describe('quickReactions', () => {
  beforeEach(() => localStorage.clear())

  it('starts as the default row', () => {
    expect(quickReactions()).toEqual(DEFAULT_REACTIONS)
  })

  it('always leads with the thumbs up', () => {
    for (let i = 0; i < 20; i++) recordReaction('🎉')

    expect(quickReactions()[0]).toBe(THUMBS_UP)
    expect(quickReactions()[1]).toBe('🎉')
  })

  it('orders the rest by how often they are actually used', () => {
    recordReaction('🙏')
    recordReaction('🎉')
    recordReaction('🎉')
    recordReaction('🔥')
    recordReaction('🔥')
    recordReaction('🔥')

    expect(quickReactions(4)).toEqual([THUMBS_UP, '🔥', '🎉', '🙏'])
  })

  it('fills out of the defaults and never repeats one', () => {
    recordReaction('❤️')

    const row = quickReactions()
    expect(row).toHaveLength(6)
    expect(new Set(row).size).toBe(6)
    expect(row[1]).toBe('❤️')
  })

  it('counting the thumbs up does not move it or duplicate it', () => {
    recordReaction(THUMBS_UP)
    recordReaction('😂')

    const row = quickReactions()
    expect(row[0]).toBe(THUMBS_UP)
    expect(row.filter((e) => e === THUMBS_UP)).toHaveLength(1)
  })

  it('survives rubbish in storage', () => {
    localStorage.setItem('netvork-reaction-use', '{"🎉": "lots", "🔥": -3, ')

    expect(quickReactions()).toEqual(DEFAULT_REACTIONS)
  })
})
