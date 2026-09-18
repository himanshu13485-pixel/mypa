import { useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Check, LogOut, Plus, UserPlus, Users, X } from 'lucide-react'
import { clsx } from 'clsx'
import { MAX_ACCOUNTS, useAuthStore, type SavedAccount } from '../stores/auth'
import { disconnectEcho } from '../lib/echo'
import { isImpersonating } from '../lib/impersonation'
import { auth } from '../api/endpoints'
import { Avatar } from '../lib/avatars'

/**
 * Several seats in one browser, and the way between them.
 *
 * A personal account and the companies somebody works in used to mean
 * signing out and signing in again, which on a phone is a password each way.
 * Up to three are held at once — each with its own token — and switching is
 * a swap rather than a sign-in.
 *
 * Three things have to happen together on a switch, and leaving out any one
 * of them shows the wrong person's data:
 *
 *   the token changes    — every request reads it from the store, so this is
 *                          the switch itself;
 *   the socket is cut    — it is subscribed to a private channel of the
 *                          account that opened it, and would keep delivering
 *                          that person's calls and messages;
 *   the cache is emptied — every list on screen was fetched as somebody
 *                          else, and React Query would happily re-show it.
 */
export default function AccountSwitcher({ className }: { className?: string }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)

  const user = useAuthStore((s) => s.user)
  const accounts = useAuthStore((s) => s.accounts)
  const switchTo = useAuthStore((s) => s.switchTo)
  const signOutActive = useAuthStore((s) => s.signOutActive)
  const signOut = useAuthStore((s) => s.signOut)

  useEffect(() => {
    const away = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false)
    }
    const escape = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('mousedown', away)
    document.addEventListener('keydown', escape)

    return () => {
      document.removeEventListener('mousedown', away)
      document.removeEventListener('keydown', escape)
    }
  }, [])

  /*
   * Nothing to switch between yet.
   *
   * One account is the ordinary case, and a menu offering to switch to the
   * person already looking at it is furniture. It appears the moment there
   * is a second — and the way to a second is the Add account row, which is
   * why the menu is offered on one account too, just not as a row of one.
   */
  if (!user) return null

  /*
   * A borrowed seat is nobody's account.
   *
   * While an Admin is inside somebody else's workspace the person on screen
   * is not one of the accounts this browser holds, so a list with nothing
   * ticked would invite a switch that abandons the borrowed session without
   * giving it back. The amber banner owns the way out of that.
   */
  if (isImpersonating()) return null

  const settle = (to: SavedAccount | null) => {
    disconnectEcho()
    queryClient.clear()
    setOpen(false)
    // Home, deliberately: the page they were on belongs to the other account
    // and half of it may not exist for this one.
    navigate(to ? '/' : '/login', { replace: true })
  }

  const goTo = (uuid: string) => {
    if (uuid === user.uuid) return setOpen(false)
    settle(switchTo(uuid))
  }

  const addAnother = () => {
    setOpen(false)
    // The sign-in screen, told to keep what is already held rather than
    // treating this browser as signed out.
    navigate('/login?add=1')
  }

  const leave = () => {
    void auth.logout().catch(() => undefined)
    const next = signOutActive()
    settle(next)
  }

  /**
   * Sign out of one account without first switching to it.
   *
   * Three is the limit, so making room for a fourth means giving one up -
   * and having to switch into an account in order to leave it is a detour
   * nobody should have to work out. The token is revoked rather than merely
   * forgotten: one the browser has dropped is still a live token.
   *
   * Signing out of the seat in use hands over to whatever remains, which is
   * the same act the row at the bottom performs.
   */
  const drop = (account: SavedAccount) => {
    void auth.logoutToken(account.token).catch(() => undefined)

    if (account.uuid === user.uuid) {
      settle(signOut(account.uuid))

      return
    }

    signOut(account.uuid)
  }

  return (
    <div ref={rootRef} className={clsx('relative', className)}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-haspopup="menu"
        aria-expanded={open}
        title={accounts.length > 1 ? 'Switch account' : 'Your account'}
        className="flex items-center gap-1 rounded-full p-0.5 hover:bg-slate-100 dark:hover:bg-slate-800"
      >
        <Avatar
          name={user.name}
          photoPath={user.profile?.photo_path}
          avatar={user.profile?.avatar}
          gender={user.profile?.gender}
          size={28}
        />
        {accounts.length > 1 && (
          <span className="rounded-full bg-brand-100 px-1 text-[10px] font-semibold text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">
            {accounts.length}
          </span>
        )}
      </button>

      {open && (
        <div
          role="menu"
          className="absolute right-0 z-50 mt-1 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-lift dark:border-slate-700 dark:bg-slate-900"
        >
          <p className="px-3 py-1 text-[11px] uppercase tracking-wide text-slate-400">
            {accounts.length > 1 ? 'Accounts on this device' : 'Signed in as'}
          </p>

          {accounts.map((a) => (
            <div key={a.uuid} className="flex items-center hover:bg-slate-50 dark:hover:bg-slate-800">
            <button
              role="menuitem"
              onClick={() => goTo(a.uuid)}
              className="flex min-w-0 flex-1 items-center gap-2 px-3 py-2 text-left text-sm"
            >
              <Avatar
                name={a.user.name}
                photoPath={a.user.profile?.photo_path}
                avatar={a.user.profile?.avatar}
                gender={a.user.profile?.gender}
                size={26}
              />
              <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">{a.user.name}</span>
                <span className="block truncate text-[11px] text-slate-400">
                  {a.user.app_id ?? a.user.username ?? a.user.email}
                </span>
              </span>
              {a.uuid === user.uuid && <Check className="size-4 shrink-0 text-emerald-500" />}
            </button>
            <button
              onClick={() => { if (confirm(`Sign out of ${a.user.name} on this device?`)) drop(a) }}
              aria-label={`Sign out of ${a.user.name}`}
              title={`Sign out of ${a.user.name}`}
              className="shrink-0 rounded p-2 text-slate-300 hover:text-red-500"
            >
              <X className="size-3.5" />
            </button>
            </div>
          ))}

          <div className="my-1 border-t border-slate-100 dark:border-slate-800" />

          {accounts.length < MAX_ACCOUNTS ? (
            <button
              role="menuitem"
              onClick={addAnother}
              className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-800"
            >
              <UserPlus className="size-4 text-slate-400" /> Add another account
            </button>
          ) : (
            <p className="flex items-start gap-2 px-3 py-2 text-[11px] text-slate-400">
              <Users className="mt-0.5 size-3.5 shrink-0" />
              Three accounts is the most this browser holds. Sign out of one to add another.
            </p>
          )}

          <button
            role="menuitem"
            onClick={leave}
            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10"
          >
            <LogOut className="size-4" />
            {accounts.length > 1 ? `Sign out of ${user.name}` : 'Sign out'}
          </button>
        </div>
      )}
    </div>
  )
}

/** The same thing where only the "add" affordance is wanted. */
export function AddAccountButton() {
  const navigate = useNavigate()
  const accounts = useAuthStore((s) => s.accounts)
  if (accounts.length >= MAX_ACCOUNTS) return null

  return (
    <button
      type="button"
      onClick={() => navigate('/login?add=1')}
      className="flex items-center gap-1 text-xs text-slate-400 hover:text-brand-600"
    >
      <Plus className="size-3.5" /> Add account
    </button>
  )
}
