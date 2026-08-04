import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { isStaff, useAuthStore } from '../stores/auth'

export function RequireAuth({ children }: { children: ReactNode }) {
  const token = useAuthStore((s) => s.token)
  const user = useAuthStore((s) => s.user)
  const location = useLocation()

  if (!token) {
    // Visitors opening the site root see the public landing page;
    // deep links into the app still go to sign-in.
    return <Navigate to={location.pathname === '/' ? '/home' : '/login'} replace />
  }

  // Holding a token is not the same as owning the address it was issued for.
  // Registration signs you in so you can confirm your email, and without this
  // check any deep link — a meeting invite, for instance — walked straight
  // past the confirmation step into a fully working account. The flag comes
  // from the server so this matches the API's own rule exactly.
  if (user?.email_verification_required) {
    return <Navigate to="/verify-email" replace />
  }

  return children
}

export function RequireAdmin({ children }: { children: ReactNode }) {
  const user = useAuthStore((s) => s.user)
  if (!isStaff(user)) return <Navigate to="/" replace />
  return children
}
