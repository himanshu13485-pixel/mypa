import { describe, expect, it } from 'vitest'
import { money } from './money'

describe('an amount in the currency it is in', () => {
  it('writes rupees the way an Indian office reads them', () => {
    expect(money(100000, 'INR')).toBe('₹1,00,000.00')
  })

  it('writes dollars the way the client abroad reads them', () => {
    // Thousands, not lakhs: this is the figure on a document sent overseas.
    expect(money(100000, 'USD')).toBe('$100,000.00')
  })

  it('does not call a dollar figure rupees', () => {
    expect(money(800, 'USD')).not.toContain('₹')
    expect(money(800, 'EUR')).toContain('€')
    expect(money(800, 'GBP')).toContain('£')
  })

  it('reads a missing or nonsense code as rupees rather than failing to draw', () => {
    expect(money(50, null)).toBe('₹50.00')
    expect(money(50, '')).toBe('₹50.00')
    expect(money(50, 'rupees')).toBe('₹50.00')
  })

  it('takes the figure however the API sent it', () => {
    expect(money('1234.5', 'INR')).toBe('₹1,234.50')
    expect(money(undefined, 'INR')).toBe('₹0.00')
    expect(money('not a number', 'INR')).toBe('₹0.00')
  })

  it('can leave the paise off', () => {
    expect(money(1234.56, 'INR', { decimals: 0 })).toBe('₹1,235')
  })
})
