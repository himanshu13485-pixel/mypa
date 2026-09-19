import { describe, expect, it } from 'vitest'
import { describeParsed, parseLeadText } from './leadText'

describe('reading a lead out of the text it arrived in', () => {
  it('takes each labelled line to its own box', () => {
    const parsed = parseLeadText([
      'Name : Tarun Dalmia',
      'Email : tarun@tdoverseas.com',
      'Contact : 9872995771',
      'Country or Market : India',
      'Search For : Buyer Contact Details',
      'Message : Polyfill',
      'Thanks',
    ].join('\n'))

    expect(parsed.contact_person).toBe('Tarun Dalmia')
    expect(parsed.email).toBe('tarun@tdoverseas.com')
    expect(parsed.mobile).toBe('9872995771')
    // Nobody named a company, so the person stands in for one - a lead has
    // to belong to somebody.
    expect(parsed.company_name).toBe('Tarun Dalmia')
  })

  it('keeps a company of its own when there is one', () => {
    const parsed = parseLeadText('Company: Akanksha Plastics India\nContact person: Mr. Atul Shah')

    expect(parsed.company_name).toBe('Akanksha Plastics India')
    expect(parsed.contact_person).toBe('Mr. Atul Shah')
  })

  it('reads an address and a number that nobody labelled', () => {
    const parsed = parseLeadText('Hi, please send rates.\ninfo@akankshaplastics.com\n+91 98201 20809')

    expect(parsed.email).toBe('info@akankshaplastics.com')
    expect(parsed.mobile).toBe('+919820120809')
  })

  it('puts a second number in the phone box rather than losing it', () => {
    const parsed = parseLeadText('Mobile: 9310325393\nPhone: 0172-4567890')

    expect(parsed.mobile).toBe('9310325393')
    expect(parsed.phone).toBe('01724567890')
  })

  it('counts one number once, however it was written', () => {
    const parsed = parseLeadText('Contact : 98729 95771\nWhatsApp : +91-9872995771')

    expect(parsed.mobile).toBe('9872995771')
    expect(parsed.phone).toBeUndefined()
  })

  it('leaves a sentence with a colon alone', () => {
    const parsed = parseLeadText('Note: we spoke last week about the order\nName: Ravi Menon')

    expect(parsed.company_name).toBe('Ravi Menon')
    expect(parsed.contact_person).toBe('Ravi Menon')
  })

  it('ignores a number too short or too long to ring', () => {
    const parsed = parseLeadText('GSTIN : 24AFEFS3799F1ZO\nQty : 5000\nMobile : 9722202372')

    expect(parsed.mobile).toBe('9722202372')
    expect(parsed.phone).toBeUndefined()
  })

  it('keeps the first answer when the signature repeats it', () => {
    const parsed = parseLeadText('Name : Suchit\nEmail : a@b.com\n\nThanks\nName : Suchit Patel')

    expect(parsed.contact_person).toBe('Suchit')
  })

  it('says what it filled, and says so when there is nothing', () => {
    expect(describeParsed(parseLeadText('Name : Tarun\nContact : 9872995771')))
      .toBe('Filled company, contact, mobile from the text.')
    expect(describeParsed(parseLeadText('please send your rates'))).toContain('Nothing recognisable')
  })

  it('finds nothing in an empty box rather than inventing it', () => {
    expect(parseLeadText('')).toEqual({})
  })
})
