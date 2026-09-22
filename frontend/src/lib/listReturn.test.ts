import { beforeEach, describe, expect, it, vi } from 'vitest'
import { listFromParam, listParamOf, listReturnPath, rememberList } from './listReturn'

describe('a checkbox filter in the address', () => {
  it('leaves "all" out of the address entirely', () => {
    expect(listParamOf(null)).toBeNull()
    expect(listFromParam(null)).toBeNull()
  })

  it('carries a chosen few there and back', () => {
    expect(listParamOf(['follow_up', 'new'])).toBe('follow_up,new')
    expect(listFromParam('follow_up,new')).toEqual(['follow_up', 'new'])
  })

  it('keeps "nothing ticked" distinct from "all"', () => {
    expect(listParamOf([])).toBe('~')
    expect(listFromParam('~')).toEqual([])
  })
})

describe('where Back goes', () => {
  // The tests run outside a browser, so the session store is a plain map.
  beforeEach(() => {
    const store = new Map<string, string>()
    vi.stubGlobal('sessionStorage', {
      getItem: (k: string) => store.get(k) ?? null,
      setItem: (k: string, v: string) => { store.set(k, v) },
      clear: () => store.clear(),
    })
  })

  it('goes to the list as it was last seen', () => {
    rememberList('leads', '/crm/leads?fu_from=2026-09-20&fu_to=2026-09-22')

    expect(listReturnPath('leads', '/crm/leads')).toBe('/crm/leads?fu_from=2026-09-20&fu_to=2026-09-22')
  })

  it('falls back to the plain list when there is nothing remembered', () => {
    expect(listReturnPath('leads', '/crm/leads')).toBe('/crm/leads')
  })

  it('keeps each list to itself', () => {
    rememberList('leads', '/crm/leads?status=new')

    expect(listReturnPath('clients', '/crm/clients')).toBe('/crm/clients')
  })
})
