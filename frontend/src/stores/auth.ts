import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import type { User } from '../types'

/**
 * One signed-in account this browser is holding.
 *
 * The token is kept beside the person it belongs to, so switching is a swap
 * rather than a sign-in: the same browser, several seats, and the one in use
 * is whichever `token` and `user` currently point at.
 */
export interface SavedAccount {
  uuid: string
  token: string
  user: User
}

/** Three. Enough for a personal seat and the companies somebody works in. */
export const MAX_ACCOUNTS = 3

interface AuthState {
  token: string | null
  user: User | null
  /**
   * Every account signed in on this browser, the active one included.
   *
   * Kept beside token/user rather than replacing them so that nothing else
   * in the app has to know this exists: every caller still reads the active
   * token the way it always did.
   */
  accounts: SavedAccount[]
  setAuth: (token: string, user: User) => void
  setUser: (user: User) => void
  /**
     * Take a seat without being handed the keys to it.
     *
     * "Login as" lends an Admin somebody else's session for a few minutes,
     * and coming back hands it straight over again. Neither is a person
     * signing in on this browser: recording them cost somebody their own
     * account, because a third seat arriving pushed the oldest out.
     */
  borrowSeat: (token: string, user: User) => void
  /** Make one of the held accounts the active one. */
  switchTo: (uuid: string) => SavedAccount | null
  /**
   * Sign out of one account by name, whether or not it is the one in use.
   *
   * Three is the limit, so making room has to be possible without first
   * switching to the account being given up.
   */
  signOut: (uuid: string) => SavedAccount | null
  /**
   * Sign out of the account in use, keeping the others.
   *
   * Returns whichever account takes over, or null when that was the last
   * one and this is an ordinary sign-out.
   */
  signOutActive: () => SavedAccount | null
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
    (set, get) => ({
      token: null,
      user: null,
      accounts: [],
      setAuth: (token, user) => set((state) => {
        /*
         * Signing in records the account as well as taking the seat.
         *
         * Signing into one already held replaces its token - that is the
         * same person with a fresh session, not a fourth account - which is
         * also what keeps the list from filling up with one's own logins.
         */
        const without = state.accounts.filter((a) => a.uuid !== user.uuid)
        const accounts = [...without, { uuid: user.uuid, token, user }].slice(-MAX_ACCOUNTS)

        return { token, user, accounts }
      }),
      setUser: (user) => set((state) => ({
        user,
        // The renamed, re-photographed person is the same account: keep the
        // switcher's copy in step so it does not go on showing an old name.
        accounts: state.accounts.map((a) => (a.uuid === user.uuid ? { ...a, user } : a)),
      })),
      borrowSeat: (token, user) => set({ token, user }),
      switchTo: (uuid) => {
        const next = get().accounts.find((a) => a.uuid === uuid)
        if (!next) return null

        set({ token: next.token, user: next.user })

        return next
      },
      signOut: (uuid) => {
        const { user, accounts } = get()
        const rest = accounts.filter((a) => a.uuid !== uuid)

        // Somebody else's seat: the one in use is untouched.
        if (user?.uuid !== uuid) {
          set({ accounts: rest })

          return null
        }

        const next = rest[rest.length - 1] ?? null
        set({ accounts: rest, token: next?.token ?? null, user: next?.user ?? null })

        return next
      },
      signOutActive: () => {
        const { user, accounts } = get()
        const rest = accounts.filter((a) => a.uuid !== user?.uuid)
        const next = rest[rest.length - 1] ?? null

        set({ accounts: rest, token: next?.token ?? null, user: next?.user ?? null })

        return next
      },
      clear: () => {
        try {
          localStorage.removeItem(IMPERSONATION_KEY)
        } catch {
          // Private mode, or storage turned off. Nothing was stashed either.
        }
        // Everything, not only the seat in use: this is the path a 401 takes
        // as well as the Sign out button, and a token the server has stopped
        // honouring is no reason to keep the others - but it IS a reason not
        // to leave somebody looking at a screen that is still half signed in.
        set({ token: null, user: null, accounts: [] })
      },
    }),
    {
      name: 'mypa-auth',
      /*
       * Somebody already signed in when this arrived.
       *
       * Their token and user were stored long before there was a list to
       * put them in, so without this the switcher would open on an empty
       * menu for every existing user — signed in, and apparently nobody.
       */
      merge: (persisted, current) => {
        const saved = (persisted ?? {}) as Partial<AuthState>
        const accounts = saved.accounts ?? []
        const seeded = accounts.length === 0 && saved.token && saved.user
          ? [{ uuid: saved.user.uuid, token: saved.token, user: saved.user }]
          : accounts

        return { ...current, ...saved, accounts: seeded }
      },
    },
  ),
)

export const isAdmin = (user: User | null) =>
  !!user?.roles?.some((r) => r === 'admin' || r === 'super_admin')

export const isStaff = (user: User | null) =>
  !!user?.roles?.some((r) => ['admin', 'super_admin', 'subadmin', 'salesperson'].includes(r))
