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

/**
 * Where a borrowed seat is set aside — see lib/impersonation.ts, which owns
 * everything about it except this one line.
 *
 * The key lives here so that clear() can drop it, and clear() drops it
 * because there is more than one way to end a session — the Sign out button,
 * and any 401 the client sees — and a stash that outlived one of them would
 * greet the next person at this browser with an amber bar about somebody
 * else's workspace and a button offering to restore a token that is gone.
 */
export const IMPERSONATION_KEY = 'netvork-impersonation'

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      token: null,
      user: null,
      setAuth: (token, user) => set({ token, user }),
      setUser: (user) => set({ user }),
      clear: () => {
        try {
          localStorage.removeItem(IMPERSONATION_KEY)
        } catch {
          // Private mode, or storage turned off. Nothing was stashed either.
        }
        set({ token: null, user: null })
      },
    }),
    { name: 'mypa-auth' },
  ),
)

export const isAdmin = (user: User | null) =>
  !!user?.roles?.some((r) => r === 'admin' || r === 'super_admin')

export const isStaff = (user: User | null) =>
  !!user?.roles?.some((r) => ['admin', 'super_admin', 'subadmin', 'salesperson'].includes(r))
