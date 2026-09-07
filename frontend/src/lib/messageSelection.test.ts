import { describe, expect, it } from 'vitest'
import { canUnsendAll, copyTextOf, selectedIn, toggleSelected } from './messageSelection'
import type { ChatMessage } from '../types'

const message = (over: Partial<ChatMessage> & { uuid: string }): ChatMessage => ({
  type: 'text',
  body: over.uuid,
  is_deleted: false,
  is_own: true,
  attachments: [],
  reactions: [],
  created_at: '2026-09-03T10:00:00Z',
  ...over,
})

const thread = [
  message({ uuid: 'one', body: 'One', can_delete_for_everyone: true }),
  message({ uuid: 'two', body: 'Two', can_delete_for_everyone: true }),
  message({ uuid: 'three', body: 'Three', can_delete_for_everyone: false }),
]

describe('toggleSelected', () => {
  it('ticks and unticks without mutating what it was given', () => {
    const first = new Set<string>()
    const withOne = toggleSelected(first, 'one')

    expect(withOne.has('one')).toBe(true)
    expect(first.size).toBe(0)

    expect(toggleSelected(withOne, 'one').size).toBe(0)
  })
})

describe('selectedIn', () => {
  it('returns them in thread order, not the order they were tapped', () => {
    // Somebody ticking the last message first must not copy or forward their
    // conversation backwards.
    const tappedBackwards = new Set(['three', 'one'])

    expect(selectedIn(thread, tappedBackwards).map((m) => m.uuid)).toEqual(['one', 'three'])
  })
})

describe('canUnsendAll', () => {
  it('allows it when every selected message is still inside its window', () => {
    expect(canUnsendAll(thread, new Set(['one', 'two']))).toBe(true)
  })

  it('refuses a mixed selection rather than half-doing it', () => {
    // 'three' is past the six-hour window. Offering "delete for everyone"
    // here would leave exactly the message somebody most wanted gone.
    expect(canUnsendAll(thread, new Set(['one', 'three']))).toBe(false)
  })

  it('refuses an empty selection', () => {
    expect(canUnsendAll(thread, new Set())).toBe(false)
  })
})

describe('copyTextOf', () => {
  it('joins the bodies in thread order, one per line', () => {
    expect(copyTextOf(thread, new Set(['two', 'one']))).toBe('One\nTwo')
  })

  it('skips what has no text rather than leaving blank lines', () => {
    const withGaps = [
      message({ uuid: 'a', body: 'Kept' }),
      message({ uuid: 'b', body: null }),
      message({ uuid: 'c', body: 'Gone', is_deleted: true }),
      message({ uuid: 'd', body: 'Also kept' }),
    ]

    expect(copyTextOf(withGaps, new Set(['a', 'b', 'c', 'd']))).toBe('Kept\nAlso kept')
  })
})
