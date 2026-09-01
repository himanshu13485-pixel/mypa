import { describe, expect, it } from 'vitest'
import { COUNTRIES, DEFAULT_DIAL, flagOf } from './countries'

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
