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

/**
 * A phone, in either orientation.
 *
 * Width alone is not the question. Turned sideways a phone is 844px wide,
 * which sails past any "narrow screen" test while still being a phone in the
 * hand — and the call layout that keys off this was falling back to the
 * desktop window there, giving a 310px picture on an 844px screen. Short and
 * landscape is the other half of the definition.
 */
export function useIsPhone(): boolean {
  const narrow = useMediaQuery(PHONE_NARROW)
  const shortLandscape = useMediaQuery(PHONE_LANDSCAPE)
  return narrow || shortLandscape
}

const PHONE_NARROW = '(max-width: 639px)'
const PHONE_LANDSCAPE = '(orientation: landscape) and (max-height: 520px)'

/** The same question outside React, for a useState initialiser. */
export function isPhoneViewport(): boolean {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false
  return window.matchMedia(PHONE_NARROW).matches || window.matchMedia(PHONE_LANDSCAPE).matches
}

/**
 * A phone turned on its side, which during a video call means "give me the lot".
 *
 * Deliberately not the Fullscreen API. requestFullscreen() needs a user
 * gesture and a rotation is not one — the browser rejects the call, so
 * auto-fullscreen on rotate cannot be done that way at all. What can be done
 * is take the app's own chrome out of the picture: on an installed app, with
 * no address bar, that is the whole screen.
 *
 * The height test matters as much as the orientation. A landscape phone is
 * short; a landscape iPad is not, and wants the ordinary layout.
 */
export function useLandscapePhone(): boolean {
  return useMediaQuery('(orientation: landscape) and (max-height: 520px)')
}
