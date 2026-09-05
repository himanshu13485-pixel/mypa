import { describe, expect, it } from 'vitest'
// The route table as text, so this test reads what ships rather than a copy
// of it somebody remembered to update.
import appSource from '../App.tsx?raw'
import { CRM_SECTIONS, companyIn, withCompany } from './crmPath'

describe('reading the company out of a CRM path', () => {
  it('takes the first segment when it is not a screen', () => {
    expect(companyIn('/crm/bhavya-steel/leads')).toBe('bhavya-steel')
    expect(companyIn('/crm/bhavya-steel')).toBe('bhavya-steel')
  })

  it('says nobody for a path written the old way', () => {
    // The whole reason CRM_SECTIONS exists: /crm/leads is the leads screen,
    // not a company called leads.
    expect(companyIn('/crm/leads')).toBeNull()
    expect(companyIn('/crm/invoices/abc-123')).toBeNull()
    expect(companyIn('/crm')).toBeNull()
  })

  it('says nobody for a path that is not the CRM at all', () => {
    expect(companyIn('/messages')).toBeNull()
    expect(companyIn('/')).toBeNull()
  })
})

describe('putting a company on a path', () => {
  it('prefixes a plain CRM path', () => {
    expect(withCompany('/crm/leads', 'acme')).toBe('/crm/acme/leads')
    expect(withCompany('/crm', 'acme')).toBe('/crm/acme')
  })

  it('keeps the query string, which decides what half these screens show', () => {
    // Proforma and Invoices are one screen told apart by ?kind — losing it
    // would send every money link to the same place.
    expect(withCompany('/crm/invoices?kind=proforma', 'acme'))
      .toBe('/crm/acme/invoices?kind=proforma')
  })

  it('swaps rather than doubles when a company is already there', () => {
    expect(withCompany('/crm/acme/leads', 'other')).toBe('/crm/other/leads')
  })

  it('leaves a path alone when there is no company to put on it', () => {
    expect(withCompany('/crm/leads', null)).toBe('/crm/leads')
  })

  it('leaves paths outside the CRM alone', () => {
    expect(withCompany('/messages', 'acme')).toBe('/messages')
  })
})

describe('the screen list against the routes themselves', () => {
  /*
   * The one that matters. CRM_SECTIONS is what tells a company apart from a
   * screen, so a route added to App.tsx and not added here becomes a screen
   * the router reads as a company name — it would redirect instead of open,
   * and nothing else in the suite would notice.
   */
  it('names every top-level CRM route', () => {
    // Every CRM screen: the shared crmScreens fragment, plus the two the
    // Super Admin's own routes add beside it.
    const screens = appSource.slice(appSource.indexOf('const crmScreens = ('))
    const routes = screens.slice(0, screens.indexOf('</Route>'))

    const segments = [...routes.matchAll(/<Route path="([^"]+)"/g)]
      .map((m) => m[1]!.split('/')[0]!)
      .filter((seg) => seg && !seg.startsWith(':'))

    expect(segments.length).toBeGreaterThan(20)

    const missing = segments.filter((seg) => !CRM_SECTIONS.has(seg))
    expect(missing).toEqual([])
  })
})
