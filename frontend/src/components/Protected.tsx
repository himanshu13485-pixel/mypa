import type { ReactNode } from 'react'
import { Navigate, useLocation, useParams } from 'react-router-dom'
import { isStaff, useAuthStore } from '../stores/auth'
import { guestPassExpired, guestRouteFor, readGuestPass } from '../lib/guestPass'
import { returnState } from '../lib/returnTo'

export function RequireAuth({ children }: { children: ReactNode }) {
  const token = useAuthStore((s) => s.token)
  const user = useAuthStore((s) => s.user)
  const location = useLocation()

  if (!token) {
    // One link for everybody. A meeting invite is the deep link that regularly
    // reaches people who have no account and are not about to make one, so it
    // must not dead-end at a sign-in form: send them to the guest door for the
    // same meeting, where the meeting password gets them in.
    const guestRoute = guestRouteFor(location.pathname, readGuestPass())
    if (guestRoute) return <Navigate to={guestRoute} replace />


    // Visitors opening the site root see the public landing page;
    // deep links into the app still go to sign-in — and are remembered, so
    // signing in finishes where they were going rather than at the dashboard.
    if (location.pathname === '/') return <Navigate to="/home" replace />

    return (
      <Navigate
        to="/login"
        state={returnState(`${location.pathname}${location.search}`)}
        replace
      />
    )
  }

  // Holding a token is not the same as owning the address it was issued for.
  // Registration signs you in so you can confirm your email, and without this
  // check any deep link — a meeting invite, for instance — walked straight
  // past the confirmation step into a fully working account. The flag comes
  // from the server so this matches the API's own rule exactly.
  if (user?.email_verification_required) {
    return <Navigate to="/verify-email" replace />
  }

  /*
   * An application signed in as itself gets its panel, wherever it was headed.
   *
   * Not a matter of taste: a service account has no profile worth filling in,
   * no meetings to join and no notes to write, and leaving those reachable is
   * how one ends up holding real work that nobody can find later. The panel is
   * the whole of the app for this kind of account.
   */
  if (user?.is_service_account && location.pathname !== '/service') {
    return <Navigate to="/service" replace />
  }

  return children
}

/**
 * The guest room, for someone actually holding a pass to it.
 *
 * The pass lives in sessionStorage, so it belongs to one tab and one sitting:
 * a second tab, a shared or bookmarked /guest/room URL, and a sitting resumed
 * after the half hour ran out all arrive here with nothing. Unguarded, the room
 * asked to join anyway, the request went to the members-only endpoint with no
 * credentials at all, and the answer was a bare "Unauthenticated." on a dead
 * end. Send them to the door instead — the password is all they need.
 */
export function RequireGuestPass({ children }: { children: ReactNode }) {
  const { code } = useParams()
  const token = useAuthStore((s) => s.token)
  const pass = readGuestPass()

  // Signed in already: the ordinary room is theirs, with no half-hour limit.
  if (token) return <Navigate to={`/meetings/room/${code}`} replace />

  if (!pass || pass.code !== code || guestPassExpired(pass)) {
    return <Navigate to={`/join/${code}`} replace />
  }

  return children
}

export function RequireAdmin({ children }: { children: ReactNode }) {
  const user = useAuthStore((s) => s.user)
  if (!isStaff(user)) return <Navigate to="/" replace />
  return children
}
