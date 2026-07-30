import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import type { User } from '../types'

interface AuthState {
  token: string | null
  user: User | null
  setAuth: (token: string, user: User) => void
  setUser: (user: User) => void
  clear: () => void
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      token: null,
      user: null,
      setAuth: (token, user) => set({ token, user }),
      setUser: (user) => set({ user }),
      clear: () => set({ token: null, user: null }),
    }),
    { name: 'mypa-auth' },
  ),
)

export const isAdmin = (user: User | null) =>
  !!user?.roles?.some((r) => r === 'admin' || r === 'super_admin')

export const isStaff = (user: User | null) =>
  !!user?.roles?.some((r) => ['admin', 'super_admin', 'subadmin', 'salesperson'].includes(r))
