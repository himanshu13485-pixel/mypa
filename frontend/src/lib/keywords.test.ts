import { describe, expect, it } from 'vitest'
import { assignTones, joinKeywords, readsAsKeywords, splitKeywords } from './keywords'

describe('reading a description as keywords', () => {
  it('splits on commas and forgives the spacing', () => {
    // What somebody actually types: spaces before commas, after them, both.
    expect(splitKeywords('brass, steel ,iron')).toEqual(['brass', 'steel', 'iron'])
  })

  it('treats one per line the same as one per comma', () => {
    expect(splitKeywords('brass\nsteel\niron')).toEqual(['brass', 'steel', 'iron'])
  })

  it('drops the empties a half-typed list is full of', () => {
    // Mid-typing, and after deleting a word out of the middle.
    expect(splitKeywords('brass, , steel,')).toEqual(['brass', 'steel'])
    expect(splitKeywords('   ')).toEqual([])
  })

  it('keeps a repeat once, in the spelling it was first given', () => {
    expect(splitKeywords('Brass, steel, brass')).toEqual(['Brass', 'steel'])
  })

  it('keeps a keyword that is more than one word', () => {
    // Materials are not always single words, and splitting on spaces would
    // turn one thing into two.
    expect(splitKeywords('stainless steel, mild steel')).toEqual(['stainless steel', 'mild steel'])
  })
})

describe('when a description is a list at all', () => {
  it('says no to prose', () => {
    /*
     * The important one. A description that was never meant as keywords must
     * keep reading as a description — one chip wrapped around a sentence is
     * a worse way to show it than plain text. No comma, no list.
     */
    expect(readsAsKeywords('Annual subscription including weekly updates')).toBe(false)
    expect(readsAsKeywords('')).toBe(false)
  })

  it('says yes to a list', () => {
    expect(readsAsKeywords('brass, steel')).toBe(true)
  })

  it('says yes to a list of one, because the comma said so', () => {
    /*
     * This used to be false, on the reasoning that one keyword is not a
     * list. But somebody who has typed "brass," has said they are listing
     * things and has so far named one — and waiting for a second before
     * showing the first is the app arguing with them about what they are
     * doing. The comma is the intent; the count is not.
     */
    expect(readsAsKeywords('brass,')).toBe(true)
    expect(readsAsKeywords('brass,  ')).toBe(true)
  })

  it('still says no when a separator names nothing', () => {
    expect(readsAsKeywords(',')).toBe(false)
    expect(readsAsKeywords(' , , ')).toBe(false)
  })
})

describe('the colour a keyword wears', () => {
  it('comes from the word, so it holds across lines', () => {
    // The bonus: a column of work order lines can be scanned for "steel".
    expect(assignTones(['steel'])).toEqual(assignTones([' Steel ']))
  })

  it('is never repeated within one description', () => {
    /*
     * The one that matters, and the one a plain per-word hash got wrong:
     * "brass, steel, iron" came out with brass and steel the same colour,
     * which is precisely the thing the colours are there to prevent.
     */
    const tones = assignTones(['brass', 'steel', 'iron'])

    expect(new Set(tones).size).toBe(3)
  })

  it('still gives every keyword a colour when there are more than there are colours', () => {
    const words = ['brass', 'steel', 'iron', 'copper', 'zinc', 'nickel', 'lead', 'tin']
    const tones = assignTones(words)

    expect(tones).toHaveLength(words.length)
    expect(tones.every(Boolean)).toBe(true)
  })
})

describe('writing keywords back', () => {
  it('produces a description somebody would have typed', () => {
    expect(joinKeywords(['brass', 'steel'])).toBe('brass, steel')
  })

  it('survives a round trip, which is what removing one relies on', () => {
    const left = splitKeywords('brass, steel ,iron').filter((w) => w !== 'steel')

    expect(joinKeywords(left)).toBe('brass, iron')
  })
})
