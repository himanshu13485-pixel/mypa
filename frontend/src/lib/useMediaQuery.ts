import { useEffect, useState } from 'react'

/**
 * Reactive media query. Used where a phone needs a different *structure*
 * rather than different styling — Tailwind handles the latter, but controls
 * that move into an overflow sheet must exist in exactly one place at a time.
 */
export function useMediaQuery(query: string): boolean {
  const [matches, setMatches] = useState(() =>
    typeof window !== 'undefined' && typeof window.matchMedia === 'function'
      ? window.matchMedia(query).matches
      : false,
  )

  useEffect(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return
    const list = window.matchMedia(query)
    const onChange = () => setMatches(list.matches)
    onChange()
    list.addEventListener('change', onChange)
    return () => list.removeEventListener('change', onChange)
  }, [query])

  return matches
}

/** Tailwind's `sm` breakpoint is 640px, so below it is "phone". */
export function useIsPhone(): boolean {
  return useMediaQuery('(max-width: 639px)')
}
