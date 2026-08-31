import { describe, expect, it } from 'vitest'
import { scrollTargetFor } from './useScrollRestoration'

describe('scrollTargetFor', () => {
  it('starts a new page at the top', () => {
    // Following a link is asking to see something, and the top is where it
    // begins. This is the case that was broken: <main> is never unmounted, so
    // without an answer here the new page opened at the old one's offset.
    expect(scrollTargetFor('PUSH', undefined)).toBe(0)
    expect(scrollTargetFor('PUSH', 1200)).toBe(0)
  })

  it('ignores a remembered position on a replace', () => {
    expect(scrollTargetFor('REPLACE', 800)).toBe(0)
  })

  it('returns you to where you were when you go back', () => {
    // Coming back from a task to a list scrolled to the top means finding
    // your place again every time.
    expect(scrollTargetFor('POP', 640)).toBe(640)
  })

  it('treats a back with nothing remembered as a first visit', () => {
    // A reload, or a link opened in a fresh tab: there is no earlier position
    // to return to, and guessing one would be worse than the top.
    expect(scrollTargetFor('POP', undefined)).toBe(0)
  })

  it('keeps the top of a page that was never scrolled', () => {
    expect(scrollTargetFor('POP', 0)).toBe(0)
  })
})
