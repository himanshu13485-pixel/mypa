import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { isStaff, useAuthStore } from '../stores/auth'

export function RequireAuth({ children }: { children: ReactNode }) {
  const token = useAuthStore((s) => s.token)
  const location = useLocation()
  if (!token) {
    // Visitors opening the site root see the public landing page;
    // deep links into the app still go to sign-in.
    return <Navigate to={location.pathname === '/' ? '/home' : '/login'} replace />
  }
  return children
}

export function RequireAdmin({ children }: { children: ReactNode }) {
  const user = useAuthStore((s) => s.user)
  if (!isStaff(user)) return <Navigate to="/" replace />
  return children
}
