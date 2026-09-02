import { describe, expect, it } from 'vitest'
import { countUnseen, seenIdsOf } from './unseenMessages'

const msg = (uuid: string, is_own = false) => ({ uuid, is_own })

describe('countUnseen', () => {
  it('is zero when nothing has changed', () => {
    const messages = [msg('a'), msg('b')]
    expect(countUnseen(messages, seenIdsOf(messages))).toBe(0)
  })

  it('counts the ones that were not there before', () => {
    const seen = seenIdsOf([msg('a'), msg('b')])
    expect(countUnseen([msg('a'), msg('b'), msg('c'), msg('d')], seen)).toBe(2)
  })

  /*
   * The reason this is not a length comparison.
   *
   * The endpoint returns a fixed window of recent messages, so two arriving
   * pushes two off the top and the length never moves.
   */
  it('still counts when the window slid and the length did not change', () => {
    const seen = seenIdsOf([msg('a'), msg('b'), msg('c')])
    const afterSlide = [msg('b'), msg('c'), msg('d')]

    expect(afterSlide.length).toBe(3)
    expect(countUnseen(afterSlide, seen)).toBe(1)
  })

  it('does not count your own messages back at you', () => {
    const seen = seenIdsOf([msg('a')])
    expect(countUnseen([msg('a'), msg('mine', true), msg('theirs')], seen)).toBe(1)
  })

  it('is idempotent across a refetch that changed nothing', () => {
    const seen = seenIdsOf([msg('a')])
    const arrived = [msg('a'), msg('b')]

    expect(countUnseen(arrived, seen)).toBe(1)
    // Same answer, not two.
    expect(countUnseen(arrived, seen)).toBe(1)
  })

  it('handles an empty or missing list', () => {
    expect(countUnseen([], new Set())).toBe(0)
    expect(countUnseen(undefined, new Set())).toBe(0)
  })

  it('counts everything when nothing has been seen yet', () => {
    expect(countUnseen([msg('a'), msg('b')], new Set())).toBe(2)
  })
})
