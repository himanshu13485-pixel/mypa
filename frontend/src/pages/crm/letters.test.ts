import { describe, expect, it } from 'vitest'
import type { CrmEmployeeFull } from '../../api/crm'
import { letterHtml } from './letters'

/**
 * The date on an HR letter.
 *
 * These are reprints of things that already happened. Somebody asking for
 * their offer letter years later wants the document as it was — and the
 * bank or landlord they hand it to reads its date as when they were hired,
 * so printing today's date on it says something untrue.
 */

/** One row of salary history, shaped as the API sends it. */
const salary = (id: number, amount: string, from: string, created: string, designation: string | null = null) =>
  ({ id, amount, currency: 'INR', effective_from: from, designation, note: null, created_at: created })

/** An employee with the dates and one salary record every letter needs. */
function employee(over: Partial<CrmEmployeeFull> = {}): CrmEmployeeFull {
  return {
    name: 'Riya Sharma',
    employee_code: 'EMP-14',
    designation: 'Sales Executive',
    joined_at: '2023-04-17',
    resigned_at: null,
    status: 'active',
    salary_records: [salary(1, '45000', '2023-04-17', '2023-04-10')],
    ...over,
  } as unknown as CrmEmployeeFull
}

/** The letter as a page. No browser involved — letterHtml is pure. */
const render = (type: Parameters<typeof letterHtml>[0], e: CrmEmployeeFull, amount?: number) =>
  letterHtml(type, e, 'Sterling Steel Pvt Ltd', amount)

/** The "Date: ..." the letter head prints. */
const printedDate = (html: string) => html.match(/Date:\s*([^<]+)</)?.[1]?.trim()

describe('the date an HR letter carries', () => {
  it('dates an offer letter by the joining date, not today', () => {
    expect(printedDate(render('offer', employee()))).toBe('17 April 2023')
  })

  it('dates an appointment letter by the joining date', () => {
    expect(printedDate(render('appointment', employee()))).toBe('17 April 2023')
  })

  it('dates a resignation acceptance by the last working day', () => {
    const html = render('resignation', employee({ resigned_at: '2025-11-30' }))

    expect(printedDate(html)).toBe('30 November 2025')
  })

  it('dates a settlement by the last working day', () => {
    const html = render('fnf', employee({ resigned_at: '2025-11-30', status: 'inactive' }), 61200)

    expect(printedDate(html)).toBe('30 November 2025')
  })

  it('reprints a promotion under the date it was made', () => {
    const e = employee({
      salary_records: [
        salary(2, '62000', '2024-07-01', '2024-06-24', 'Senior Executive'),
        salary(1, '45000', '2023-04-17', '2023-04-10'),
      ],
    })

    expect(printedDate(render('promotion', e))).toBe('24 June 2024')
  })

  it('falls back to today when the letter has no date of its own', () => {
    // An undated letter is worse than an approximately dated one, so a
    // record with nothing to go on still prints something.
    const html = render('offer', employee({ joined_at: null }))
    const today = new Date().toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' })

    expect(printedDate(html)).toBe(today)
  })

  it('takes the reference number year from the same date', () => {
    // A 2023 letter numbered .../2026/... would contradict its own heading.
    const html = render('offer', employee())

    expect(html).toContain('/HR/2023/EMP-14')
    expect(html).not.toContain(`/HR/${new Date().getFullYear()}/`)
  })
})
