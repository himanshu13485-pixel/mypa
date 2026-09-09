import { describe, expect, it } from 'vitest'
import { stateCode, unavailableTaxes } from './gst'

/**
 * The cases here are the same ones the server's own test asserts
 * (CrmPlaceOfSupplyTest), because two halves of one rule that disagree are
 * worse than either half alone: the form would grey a box the document then
 * charges, or leave one open that is silently dropped.
 */
describe('which GST lines a document may carry', () => {
  it('charges CGST and SGST inside the state', () => {
    // Haryana company, Haryana client.
    expect(unavailableTaxes('06', '06AABCM1234C1ZX')).toEqual(['igst'])
  })

  it('charges IGST across a state line', () => {
    expect(unavailableTaxes('06', '27AABCM1234C1ZX')).toEqual(['cgst', 'sgst'])
  })

  it('rules nothing out when either side has not said where it is', () => {
    // An unregistered client, and a company whose state code was never set.
    expect(unavailableTaxes('06', '')).toEqual([])
    expect(unavailableTaxes('06', null)).toEqual([])
    expect(unavailableTaxes('', '27AABCM1234C1ZX')).toEqual([])
    expect(unavailableTaxes(null, null)).toEqual([])
  })

  it('reads a state code however it was typed', () => {
    // "6" for Haryana is the same state as "06".
    expect(unavailableTaxes('6', '06AABCM1234C1ZX')).toEqual(['igst'])
    // A GSTIN pasted with spaces or in lower case is the same GSTIN.
    expect(unavailableTaxes('27', ' 27aabcm1234c1zx ')).toEqual(['igst'])
  })

  it('never touches the other lines', () => {
    // Only the three that answer the intra/inter-state question are ever
    // ruled out — a discount, TDS, Other tax and a company's own line are
    // charged wherever the client is.
    for (const blocked of [
      unavailableTaxes('06', '06AABCM1234C1ZX'),
      unavailableTaxes('06', '27AABCM1234C1ZX'),
    ]) {
      expect(blocked).not.toContain('other_tax')
      expect(blocked).not.toContain('discount')
      expect(blocked).not.toContain('tds')
    }
  })

  it('reads a state out of a GST number, and nothing else as a state', () => {
    expect(stateCode('29AAACX1234R1Z5')).toBe('29')
    expect(stateCode('00AAACX1234R1Z5')).toBeNull()
    // 38 is the last of them; beyond that is a typo, not a state.
    expect(stateCode('38AAACX1234R1Z5')).toBe('38')
    expect(stateCode('99AAACX1234R1Z5')).toBeNull()
    expect(stateCode('ABC')).toBeNull()
  })
})
