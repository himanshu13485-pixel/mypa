import { useEffect } from 'react'
import { useLocation, useSearchParams } from 'react-router-dom'
import { listFromParam, listParamOf, rememberList } from './listReturn'

/**
 * A list's filters, kept in its address.
 *
 * Every list in the CRM held its filters in memory alone, so leaving it -
 * to open a record, or anywhere else - and coming back rebuilt it from
 * nothing. With the filters in the address, the browser's Back, a reload
 * and a shared link all bring the list back as it was left; and the
 * address is remembered for the session, so a record's own Back button can
 * return to it too (see listReturn.ts).
 *
 * Two halves, because they happen at different moments:
 *
 *   useFilterAddress()  - read once, as the list's filters are first set up
 *   useFiltersInAddress(list, values) - written back whenever they change,
 *                         replacing the history entry rather than adding
 *                         one, so ticking three filters does not cost three
 *                         presses of Back to leave the page.
 */
export function useFilterAddress() {
  const [params] = useSearchParams()

  return {
    params,
    text: (key: string, fallback = '') => params.get(key) ?? fallback,
    /** A checkbox filter; `fallback` is what an address without it means. */
    list: (key: string, fallback: string[] | null = null) =>
      params.has(key) ? (params.get(key) === 'all' ? null : listFromParam(params.get(key))) : fallback,
    flag: (key: string) => params.get(key) === '1',
    page: () => Math.max(1, Number(params.get('page')) || 1),
    /** Every key under a prefix - for lists whose filters are named at run time. */
    prefixed: (prefix: string) => {
      const found: Record<string, string> = {}
      params.forEach((value, key) => {
        if (key.startsWith(prefix)) found[key.slice(prefix.length)] = value
      })
      return found
    },
  }
}

/** A checkbox filter for the address. */
export const asList = (value: string[] | null, defaultIsAll = true): string | null =>
  // A filter whose default is NOT "all" has to say "all" out loud, or
  // choosing everything would read back as the default.
  value === null ? (defaultIsAll ? null : 'all') : listParamOf(value)

/**
 * `prefixes` are for filters named at run time (a complaint form's own
 * fields): every key under them is cleared first, so a filter taken off
 * leaves the address instead of lingering in it.
 */
export function useFiltersInAddress(
  list: string,
  values: Record<string, string | null | undefined>,
  prefixes: string[] = [],
) {
  const [params, setParams] = useSearchParams()
  const location = useLocation()
  const signature = JSON.stringify(values)

  useEffect(() => {
    const next = new URLSearchParams(params)
    for (const key of [...next.keys()]) {
      if (prefixes.some((prefix) => key.startsWith(prefix))) next.delete(key)
    }
    for (const [key, value] of Object.entries(values)) {
      if (value) next.set(key, value)
      else next.delete(key)
    }

    if (next.toString() !== params.toString()) setParams(next, { replace: true })
    rememberList(list, location.pathname + (next.toString() ? `?${next}` : ''))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signature])
}
