import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import {
  BarChart3, Calendar, CheckSquare, CreditCard, FileText, FolderKanban, MonitorUp,
  FolderOpen, LayoutDashboard, LogOut, Menu, MessageCircle, Moon, MoreHorizontal, Phone,
  Briefcase, Receipt, Repeat, Settings, Shield, Star, Sun, Target, UserPlus, Users, Video, X,
} from 'lucide-react'
import { clsx } from 'clsx'
import { useQuery } from '@tanstack/react-query'
import { auth, badges as badgesApi } from '../api/endpoints'
import { disconnectEcho } from '../lib/echo'
import { isStaff, useAuthStore } from '../stores/auth'
import NotificationBell from './NotificationBell'
import NetvorkMark from './Logo'
import { CallProvider } from './CallManager'
import VoiceAssistant from './VoiceAssistant'
import MobileVerifyBanner from './MobileVerifyBanner'

/** Sidebar rows that carry an unattended-items badge. */
const BADGE_KEYS: Record<string, 'messages' | 'calls' | 'connections'> = {
  '/messages': 'messages',
  '/calls': 'calls',
  '/connections': 'connections',
}

const navSections: { label: string | null; items: { to: string; label: string; icon: typeof LayoutDashboard }[] }[] = [
  { label: null, items: [
    { to: '/', label: 'Dashboard', icon: LayoutDashboard },
  ]},
  { label: 'Connect', items: [
    { to: '/connections', label: 'Connections', icon: UserPlus },
    { to: '/groups', label: 'Family & Teams', icon: Users },
    { to: '/messages', label: 'Messages', icon: MessageCircle },
    { to: '/calls', label: 'Calls', icon: Phone },
    { to: '/meetings', label: 'Meetings', icon: Video },
    { to: '/screen', label: 'Screen', icon: MonitorUp },
  ]},
  { label: 'Workspace', items: [
    { to: '/projects', label: 'Projects', icon: Briefcase },
    { to: '/notes', label: 'Notes', icon: FileText },
    { to: '/files', label: 'Files', icon: FolderOpen },
  ]},
  { label: 'Plan', items: [
    { to: '/tasks', label: 'My Tasks', icon: CheckSquare },
    { to: '/tasks?important=1', label: 'Important', icon: Star },
    { to: '/calendar', label: 'Calendar', icon: Calendar },
    { to: '/categories', label: 'Categories', icon: FolderKanban },
  ]},
  { label: 'Life', items: [
    { to: '/habits', label: 'Habits', icon: Repeat },
    { to: '/goals', label: 'Goals', icon: Target },
    { to: '/bills', label: 'Bills', icon: Receipt },
  ]},
  { label: 'Account', items: [
    { to: '/reports', label: 'Reports', icon: BarChart3 },
    { to: '/subscription', label: 'Subscription', icon: CreditCard },
    { to: '/settings', label: 'Settings', icon: Settings },
  ]},
]

/**
 * What a thumb reaches without opening a menu. Everything else stays one tap
 * away behind "More", which opens the same drawer the hamburger does.
 */
const bottomTabs: { to: string; label: string; icon: typeof LayoutDashboard }[] = [
  { to: '/', label: 'Home', icon: LayoutDashboard },
  { to: '/tasks', label: 'Tasks', icon: CheckSquare },
  { to: '/messages', label: 'Chats', icon: MessageCircle },
  { to: '/meetings', label: 'Meet', icon: Video },
]

function applyTheme(dark: boolean) {
  document.documentElement.classList.toggle('dark', dark)
}

export default function Layout() {
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const { user, clear } = useAuthStore()
  const [sidebarOpen, setSidebarOpen] = useState(false)

  // A live meeting or screen share wants the whole screen; a nav bar eating
  // 56px of a phone is the difference between seeing faces and seeing chrome.
  const immersive = /^\/(meetings\/room|screen\/session)\//.test(pathname)
  const [dark, setDark] = useState(() =>
    localStorage.getItem('mypa-theme')
      ? localStorage.getItem('mypa-theme') === 'dark'
      : window.matchMedia('(prefers-color-scheme: dark)').matches,
  )

  useEffect(() => {
    applyTheme(dark)
    localStorage.setItem('mypa-theme', dark ? 'dark' : 'light')
  }, [dark])

  const { data: badges } = useQuery({
    queryKey: ['badges'],
    queryFn: badgesApi.get,
    refetchInterval: 30_000,
  })

  const logout = async () => {
    try {
      await auth.logout()
    } finally {
      // Close the private channel before dropping the session — see the note
      // in api/client.ts; an orphaned socket outlives the logout.
      disconnectEcho()
      clear()
      navigate('/login')
    }
  }

  const links = (
    <nav className="scroll-pane flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto p-3 pb-safe">
      {navSections.map((section, si) => (
        <div key={si}>
          {section.label && (
            <p className="mb-0.5 mt-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-slate-400">
              {section.label}
            </p>
          )}
          {section.items.map(({ to, label, icon: Icon }) => {
            const badgeKey = BADGE_KEYS[to]
            const count = badgeKey && badges ? badges[badgeKey] : 0
            return (
              <NavLink
                key={to}
                to={to}
                end={to === '/'}
                onClick={() => setSidebarOpen(false)}
                className={({ isActive }) =>
                  clsx(
                    'tap flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                    isActive
                      ? 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300'
                      : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
                  )
                }
              >
                <Icon className="size-4" />
                <span className="flex-1">{label}</span>
                {count > 0 && (
                  <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-600 px-1.5 text-[11px] font-semibold text-white">
                    {count > 99 ? '99+' : count}
                  </span>
                )}
              </NavLink>
            )
          })}
        </div>
      ))}
      {isStaff(user) && (
        <NavLink
          to="/admin"
          onClick={() => setSidebarOpen(false)}
          className={({ isActive }) =>
            clsx(
              'mt-2 flex items-center gap-2.5 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-sm font-medium dark:border-slate-700',
              isActive
                ? 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300'
                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
            )
          }
        >
          <Shield className="size-4" />
          Admin Panel
        </NavLink>
      )}
    </nav>
  )

  return (
    <CallProvider>
    {/* h-dvh, not h-screen: 100vh on a phone is the height with the URL bar
        hidden, so a plain h-screen shell hides its own footer until you
        scroll, then jumps when the bar retracts. */}
    <div className="flex h-dvh overflow-hidden">
      {/* Desktop sidebar */}
      <aside className="hidden w-60 shrink-0 flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 lg:flex">
        <div className="flex items-center gap-2 px-5 py-4">
          <NetvorkMark className="size-8" />
          <span className="text-base font-semibold">Netvork</span>
        </div>
        <p className="-mt-2 px-5 pb-2 text-[10px] italic text-slate-400">
          One App. Every Task. Every Connection.
        </p>
        {links}
        <div className="border-t border-slate-200 p-3 dark:border-slate-800">
          <div className="px-3 py-1">
            <p className="truncate text-sm font-medium">{user?.name}</p>
            <p className="truncate text-xs text-slate-400">{user?.app_id}</p>
          </div>
        </div>
      </aside>

      {/* Mobile sidebar */}
      {sidebarOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-black/40" onClick={() => setSidebarOpen(false)} />
          <aside className="pt-safe absolute inset-y-0 left-0 flex h-full w-72 max-w-[85vw] flex-col overflow-hidden bg-white dark:bg-slate-900">
            <div className="flex items-center justify-between px-5 py-4">
              <span className="flex items-center gap-2 text-base font-semibold">
                <NetvorkMark className="size-7" /> Netvork
              </span>
              <button
                className="tap -mr-2 flex items-center justify-center rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                aria-label="Close menu"
                onClick={() => setSidebarOpen(false)}
              >
                <X className="size-5" />
              </button>
            </div>
            <p className="-mt-2 px-5 pb-2 text-[10px] italic text-slate-400">
              One App. Every Task. Every Connection.
            </p>
            {links}
          </aside>
        </div>
      )}

      {/* Main */}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="pt-safe px-safe flex shrink-0 items-center justify-between border-b border-slate-200 bg-white px-2 py-1.5 dark:border-slate-800 dark:bg-slate-900 sm:px-4 sm:py-3">
          <button
            className="tap flex items-center justify-center rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800 lg:hidden"
            aria-label="Open menu"
            onClick={() => setSidebarOpen(true)}
          >
            <Menu className="size-5" />
          </button>
          {/* On a phone the page title is the nav; on desktop the sidebar is. */}
          <span className="flex items-center gap-2 text-sm font-semibold lg:hidden">
            <NetvorkMark className="size-6" /> Netvork
          </span>
          <div className="hidden lg:block" />
          <div className="flex items-center gap-0.5 sm:gap-2">
            <NotificationBell />
            <button
              onClick={() => setDark(!dark)}
              className="tap flex items-center justify-center rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
              aria-label="Toggle theme"
              title="Toggle theme"
            >
              {dark ? <Sun className="size-5 sm:size-4" /> : <Moon className="size-5 sm:size-4" />}
            </button>
            <button
              onClick={logout}
              className="tap flex items-center justify-center rounded-lg p-2 text-sm text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
              aria-label="Log out"
              title="Log out"
            >
              <LogOut className="size-5 sm:size-4" />
            </button>
          </div>
        </header>
        <MobileVerifyBanner />
        {user?.must_change_password && (
          <div className="border-b border-amber-200 bg-amber-50 px-4 py-2 text-center text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            You are using a default password. Please{' '}
            <NavLink to="/settings" className="font-semibold underline">
              change it now
            </NavLink>{' '}
            to secure your account.
          </div>
        )}
        <main className="scroll-pane px-safe min-h-0 flex-1 overflow-y-auto p-3 sm:p-4 lg:p-6">
          <Outlet />
        </main>

        {/* Mobile bottom bar. Four destinations plus the drawer — the same
            shape every phone app uses, so the common screens stop being two
            taps deep behind a hamburger. */}
        <nav className={clsx(
          'pb-safe shrink-0 items-stretch border-t border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 lg:hidden',
          immersive ? 'hidden' : 'flex',
        )}>
          {bottomTabs.map(({ to, label, icon: Icon }) => {
            const badgeKey = BADGE_KEYS[to]
            const count = badgeKey && badges ? badges[badgeKey] : 0
            return (
              <NavLink
                key={to}
                to={to}
                end={to === '/'}
                className={({ isActive }) =>
                  clsx(
                    'relative flex flex-1 flex-col items-center justify-center gap-0.5 py-2 text-[11px] font-medium',
                    isActive ? 'text-brand-600 dark:text-brand-400' : 'text-slate-500 dark:text-slate-400',
                  )
                }
              >
                <Icon className="size-5" />
                {label}
                {count > 0 && (
                  <span className="absolute right-1/2 top-1 -mr-3 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-semibold text-white">
                    {count > 9 ? '9+' : count}
                  </span>
                )}
              </NavLink>
            )
          })}
          <button
            className="flex flex-1 flex-col items-center justify-center gap-0.5 py-2 text-[11px] font-medium text-slate-500 dark:text-slate-400"
            onClick={() => setSidebarOpen(true)}
          >
            <MoreHorizontal className="size-5" />
            More
          </button>
        </nav>
      </div>
      <VoiceAssistant />
    </div>
    </CallProvider>
  )
}
