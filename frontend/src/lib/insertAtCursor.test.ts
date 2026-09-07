import { describe, expect, it } from 'vitest'
import { insertAtCursor } from './insertAtCursor'

describe('insertAtCursor', () => {
  it('inserts mid-sentence rather than at the end', () => {
    // "finishing the sentence" is the ordinary case: pick an emoji, keep typing.
    expect(insertAtCursor('Hello  world', '😊', 6, 6)).toEqual({
      text: 'Hello 😊 world',
      cursor: 8,
    })
  })

  it('appends when the cursor is at the end', () => {
    expect(insertAtCursor('gm', '☀️', 2, 2)).toEqual({
      text: 'gm☀️',
      cursor: 4,
    })
  })

  it('prepends when the cursor is at the start', () => {
    expect(insertAtCursor('world', '👋', 0, 0)).toEqual({
      text: '👋world',
      cursor: 2,
    })
  })

  it('replaces a selection rather than inserting inside it', () => {
    // Typing over a selection replaces it; an emoji pick should behave the same.
    expect(insertAtCursor('Hello world', '🌍', 6, 11)).toEqual({
      text: 'Hello 🌍',
      cursor: 8,
    })
  })

  it('works on an empty draft', () => {
    expect(insertAtCursor('', '🎉', 0, 0)).toEqual({ text: '🎉', cursor: 2 })
  })
})
