import { describe, expect, it } from 'vitest'
// The route table as text, so this test reads what ships rather than a copy
// of it somebody remembered to update.
import appSource from '../App.tsx?raw'
import { navMatches, CRM_SECTIONS, companyIn, withCompany } from './crmPath'

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

describe('which sidebar entry the open page belongs to', () => {
  /*
   * This rule decides the highlight AND which section's badge is marked
   * seen. Proforma and Invoices share the path /crm/invoices and differ only
   * by ?kind, so a comparison that ignored the query matched whichever came
   * first in the sidebar — Proforma. Opening Invoices therefore marked
   * Proforma seen, and the Invoices badge could never be cleared.
   */
  const at = (to: string, url: string) => {
    const [pathname, search] = url.split('?')
    return navMatches(to, pathname, search ? '?' + search : '')
  }

  it('tells Invoices apart from Proforma on their shared path', () => {
    expect(at('/crm/invoices?kind=invoice', '/crm/acme/invoices?kind=invoice')).toBe(true)
    expect(at('/crm/invoices?kind=proforma', '/crm/acme/invoices?kind=invoice')).toBe(false)

    expect(at('/crm/invoices?kind=proforma', '/crm/acme/invoices?kind=proforma')).toBe(true)
    expect(at('/crm/invoices?kind=invoice', '/crm/acme/invoices?kind=proforma')).toBe(false)
  })

  it('tells the two logs apart the same way', () => {
    expect(at('/crm/invoice-log?kind=invoice', '/crm/acme/invoice-log?kind=invoice')).toBe(true)
    expect(at('/crm/invoice-log?kind=proforma', '/crm/acme/invoice-log?kind=invoice')).toBe(false)
  })

  it('reads a document page with no kind as a tax invoice', () => {
    // Which is what the screen itself defaults to.
    expect(at('/crm/invoices?kind=invoice', '/crm/acme/invoices')).toBe(true)
    expect(at('/crm/invoices?kind=proforma', '/crm/acme/invoices')).toBe(false)
  })

  it('matches a child page of a section', () => {
    expect(at('/crm/leads', '/crm/acme/leads/01a07-abc')).toBe(true)
  })

  it('does not let the dashboard swallow every screen under it', () => {
    expect(at('/crm', '/crm/acme')).toBe(true)
    expect(at('/crm', '/crm/acme/invoices')).toBe(false)
  })

  it('does not match a different section', () => {
    expect(at('/crm/leads', '/crm/acme/clients')).toBe(false)
  })
})
