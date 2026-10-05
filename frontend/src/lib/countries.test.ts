import { describe, expect, it } from 'vitest'
import { COUNTRIES, DEFAULT_DIAL, flagOf, joinDial, splitDial } from './countries'

/**
 * The dialling codes are generated rather than typed, so what is worth
 * testing is not the individual numbers — it is that the generated file is
 * still whole: every country present, every code usable, nothing duplicated
 * into a list where two rows look identical to somebody scrolling it.
 */
describe('the dialling codes', () => {
  it('covers the world, not the fourteen countries somebody thought of', () => {
    expect(COUNTRIES.length).toBeGreaterThan(200)
  })

  it('gives every country a usable code and a name', () => {
    for (const country of COUNTRIES) {
      expect(country.iso).toMatch(/^[A-Z]{2}$/)
      expect(country.dial).toMatch(/^[1-9][0-9]{0,3}$/)
      expect(country.name.trim()).not.toBe('')
    }
  })

  it('lists each country once, and reads in alphabetical order', () => {
    const isos = COUNTRIES.map((c) => c.iso)
    expect(new Set(isos).size).toBe(isos.length)

    const names = COUNTRIES.map((c) => c.name)
    expect(names).toEqual([...names].sort((a, b) => a.localeCompare(b)))
  })

  it('knows the countries this is actually dialled from', () => {
    const dialOf = (iso: string) => COUNTRIES.find((c) => c.iso === iso)?.dial
    expect(dialOf('IN')).toBe('91')
    expect(dialOf('US')).toBe('1')
    expect(dialOf('GB')).toBe('44')
    expect(dialOf('AE')).toBe('971')
    expect(DEFAULT_DIAL).toBe('+91')
  })

  it('draws a flag from the country code itself', () => {
    // Two regional-indicator letters — what the reader's own system paints.
    expect(flagOf('IN')).toBe('\u{1F1EE}\u{1F1F3}')
    expect(flagOf('gb')).toBe('\u{1F1EC}\u{1F1E7}')
  })
})

/**
 * Taking a stored number apart again.
 *
 * One string is stored, because the whole number is the only thing worth
 * keeping. A form that lets somebody change it has to split it back into the
 * country and the rest - and the codes are prefixes of one another, which is
 * where this goes wrong if it is written carelessly.
 */
describe('splitting a stored number', () => {
  it('reads the longest dialling code, not the first that fits', () => {
    // 971 begins with 9 and with 97, both of which would match first on a
    // shortest-wins search and give a number belonging to nobody.
    expect(splitDial('+971501234567')).toEqual({ code: '+971', national: '501234567' })
    expect(splitDial('+919310325393')).toEqual({ code: '+91', national: '9310325393' })
    expect(splitDial('+14155550123')).toEqual({ code: '+1', national: '4155550123' })
  })

  it('reads a number with no code as Indian, which is what the old rows are', () => {
    expect(splitDial('9310325393')).toEqual({ code: '+91', national: '9310325393' })
  })

  it('drops the trunk zero people write in front of an Indian number', () => {
    expect(splitDial('09310325393')).toEqual({ code: '+91', national: '9310325393' })
  })

  it('ignores how somebody spaced it', () => {
    expect(splitDial('+91 93103 25393')).toEqual({ code: '+91', national: '9310325393' })
    expect(splitDial('  +91-93103-25393 ')).toEqual({ code: '+91', national: '9310325393' })
  })

  it('has an answer for nothing at all', () => {
    expect(splitDial('')).toEqual({ code: DEFAULT_DIAL, national: '' })
    expect(splitDial(null)).toEqual({ code: DEFAULT_DIAL, national: '' })
    expect(splitDial(undefined)).toEqual({ code: DEFAULT_DIAL, national: '' })
  })

  it('survives a round trip, which is the only reason it exists', () => {
    for (const stored of ['+919310325393', '+14155550123', '+971501234567']) {
      const { code, national } = splitDial(stored)
      expect(joinDial(code, national)).toBe(stored)
    }
  })
})

describe('joining a number back up', () => {
  it('stores nothing when there is no number', () => {
    // A country code on its own is the answer to a question nobody asked.
    expect(joinDial('+91', '')).toBe('')
    expect(joinDial('+91', '   ')).toBe('')
  })

  it('keeps only the digits of what was typed', () => {
    expect(joinDial('+91', '93103 25393')).toBe('+919310325393')
  })
})
