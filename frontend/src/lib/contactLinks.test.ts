import { describe, expect, it } from 'vitest'
import { mailtoHref, telHref } from './contactLinks'

describe('telHref', () => {
  it('strips the punctuation people type and keeps the digits', () => {
    expect(telHref('098765-43210')).toBe('tel:09876543210')
    expect(telHref('(022) 2758 1234')).toBe('tel:02227581234')
  })

  it('keeps a leading +, which is meaning rather than punctuation', () => {
    expect(telHref('+91 98765 43210')).toBe('tel:+919876543210')
  })

  it('does not draw a link for something that is not a number', () => {
    // These fields get used for notes and placeholders; a dialler opened on
    // "12" or a dash helps nobody.
    expect(telHref('-')).toBeNull()
    expect(telHref('ext 12')).toBeNull()
    expect(telHref('   ')).toBeNull()
    expect(telHref('')).toBeNull()
  })

  it('accepts a short but plausible landline', () => {
    expect(telHref('2758 1234')).toBe('tel:27581234')
  })
})

describe('mailtoHref', () => {
  it('links an ordinary address', () => {
    expect(mailtoHref('jaimin@bhavyasteel.com')).toBe('mailto:jaimin@bhavyasteel.com')
  })

  it('trims what somebody pasted with a space on the end', () => {
    expect(mailtoHref('  a@b.com ')).toBe('mailto:a@b.com')
  })

  it('leaves anything that is plainly not an address as plain text', () => {
    expect(mailtoHref('not an address')).toBeNull()
    expect(mailtoHref('nobody')).toBeNull()
    expect(mailtoHref('')).toBeNull()
  })
})
