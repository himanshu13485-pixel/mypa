import type { ReactNode } from 'react'
import { Navigate } from 'react-router-dom'
import { isStaff, useAuthStore } from '../stores/auth'

export function RequireAuth({ children }: { children: ReactNode }) {
  const token = useAuthStore((s) => s.token)
  if (!token) return <Navigate to="/login" replace />
  return children
}

export function RequireAdmin({ children }: { children: ReactNode }) {
  const user = useAuthStore((s) => s.user)
  if (!isStaff(user)) return <Navigate to="/" replace />
  return children
}
