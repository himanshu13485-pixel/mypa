import { Suspense, useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import {
  BarChart3, Calendar, CheckSquare, CreditCard, FileText, FolderKanban, MonitorUp,
  FolderOpen, LayoutDashboard, LogOut, Menu, MessageCircle, Moon, MoreHorizontal, Phone,
  Briefcase, Receipt, Repeat, Settings, Shield, Star, Sun, Target, UserPlus, Users, Video, X,
} from 'lucide-react'
import { clsx } from 'clsx'
import { useQuery } from '@tanstack/react-query'
import { auth, badges as badgesApi } from '../api/endpoints'
import { ensurePushRegistered } from '../lib/alerts'
import { disconnectEcho } from '../lib/echo'
import { isStaff, useAuthStore } from '../stores/auth'
import NotificationBell from './NotificationBell'
import NetvorkMark from './Logo'
import { CallProvider } from './CallManager'
import { MeetingHost, MeetingSlot } from './MeetingHost'
import VoiceAssistant from './VoiceAssistant'
import MobileVerifyBanner from './MobileVerifyBanner'
import { Avatar } from '../lib/avatars'
import { useLandscapePhone } from '../lib/useMediaQuery'
import { useScrollRestoration } from '../lib/useScrollRestoration'
import { Spinner } from './ui'

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
    { to: '/messages', label: 'Call/Messages', icon: MessageCircle },
    { to: '/calls', label: 'Call Logs', icon: Phone },
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

/**
 * @param preloadPath fetches the page behind a link before it is clicked.
 *   Passed in rather than imported, because the route table lives in App and
 *   App renders this — importing it back would be a cycle.
 */
export default function Layout({ preloadPath }: { preloadPath?: (to: string) => void }) {
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const { user, clear } = useAuthStore()
  const [sidebarOpen, setSidebarOpen] = useState(false)

  /*
   * The app scrolls inside <main>, and <main> outlives every page in it, so
   * without this a section opens wherever the last one was left.
   */
  const mainRef = useRef<HTMLElement>(null)
  useScrollRestoration(mainRef)

  // A live meeting or screen share wants the whole screen; a nav bar eating
  // 56px of a phone is the difference between seeing faces and seeing chrome.
  const immersive = /^\/(meetings\/room|screen\/session)\//.test(pathname)
  /*
   * Turned sideways, a phone is 390px tall. Every strip of app furniture is a
   * third of the picture, so on an immersive route none of it is drawn at all —
   * no header, no tab bar, no padding. In the installed app, where there is no
   * address bar either, that is genuinely the whole screen.
   */
  const landscape = useLandscapePhone()
  const bare = immersive && landscape
  /** A conversation is open, so the bottom-right corner belongs to Send. */
  const chatOpen = pathname.startsWith('/messages')
  const [dark, setDark] = useState(() =>
    localStorage.getItem('mypa-theme')
      ? localStorage.getItem('mypa-theme') === 'dark'
      : window.matchMedia('(prefers-color-scheme: dark)').matches,
  )

  useEffect(() => {
    applyTheme(dark)
    localStorage.setItem('mypa-theme', dark ? 'dark' : 'light')
  }, [dark])

  /*
   * Re-register this browser for push, silently, once per load.
   *
   * Inside Layout because Layout only renders behind the auth guard, so
   * there is a signed-in user for the subscription to belong to. It never
   * prompts — it returns immediately unless permission was already granted —
   * so the cost to somebody who has not opted in is one `if`.
   *
   * What it buys is a subscription that repairs itself. An endpoint that
   * expires is pruned server-side on the next failed send, and until now
   * nothing ever put it back: the toggle went on reading "on" and no
   * notification ever arrived again.
   */
  useEffect(() => {
    void ensurePushRegistered()
  }, [])

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
            <p className="mb-1 mt-4 px-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-400 first:mt-0 dark:text-slate-500">
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
                /*
                 * Start fetching the page on approach.
                 *
                 * A pointer entering a link precedes the click by a few
                 * hundred milliseconds, and a keyboard focus by longer, so
                 * the chunk is usually in memory by the time the navigation
                 * happens. Touch has no hover, which is what pointerdown is
                 * for: it fires as the finger lands, before the tap has been
                 * recognised as a tap.
                 *
                 * Already-loaded pages cost nothing to ask for again — the
                 * import is memoised — so this needs no bookkeeping.
                 */
                onPointerEnter={() => preloadPath?.(to)}
                onPointerDown={() => preloadPath?.(to)}
                onFocus={() => preloadPath?.(to)}
                className={({ isActive }) =>
                  clsx(
                    'tap group flex items-center gap-3 rounded-xl px-3 py-2 text-sm transition-colors',
                    isActive
                      ? 'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300'
                      : 'font-medium text-slate-600 hover:bg-slate-900/5 dark:text-slate-400 dark:hover:bg-white/5 dark:hover:text-slate-200',
                  )
                }
              >
                {/* Inherits the row's colour rather than being told its own,
                    so it turns brand-blue with the label when active. Held
                    back at 70% otherwise, so the icons read as a quiet column
                    beside the words instead of competing with them. */}
                <Icon className="size-[18px] opacity-70 transition-opacity group-hover:opacity-100" />
                <span className="flex-1">{label}</span>
                {count > 0 && (
                  <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-600 px-1.5 text-[11px] font-semibold tabular-nums text-white">
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
          onPointerEnter={() => preloadPath?.('/admin')}
          onPointerDown={() => preloadPath?.('/admin')}
          onFocus={() => preloadPath?.('/admin')}
          onClick={() => setSidebarOpen(false)}
          className={({ isActive }) =>
            clsx(
              'mt-4 flex items-center gap-3 rounded-xl border border-dashed border-slate-300 px-3 py-2 text-sm font-medium dark:border-slate-700',
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
    <MeetingHost>
    {/* h-dvh, not h-screen: 100vh on a phone is the height with the URL bar
        hidden, so a plain h-screen shell hides its own footer until you
        scroll, then jumps when the bar retracts. */}
    {/* The side insets belong here, on the one element with no padding of its
        own to be overwritten. They are 0 on a phone held upright and only
        appear in landscape, where the notch eats into the side of the screen. */}
    <div className="px-safe flex h-dvh overflow-hidden">
      {/* Desktop sidebar */}
      {/* The sidebar shares the page's tone rather than being a white slab
          with a rule down its edge. What should look raised is the content. */}
      <aside className="hidden w-64 shrink-0 flex-col bg-slate-100 dark:bg-slate-950 lg:flex">
        <div className="flex items-center gap-2.5 px-5 pb-1 pt-5">
          <NetvorkMark className="size-8" />
          <span className="text-[15px] font-semibold tracking-tight">Netvork</span>
        </div>
        <p className="px-5 pb-3 text-[11px] text-slate-400">
          One App. Every Task. Every Connection.
        </p>
        {links}
        <div className="p-3">
          <div className="flex items-center gap-3 rounded-xl bg-white px-3 py-2.5 shadow-card ring-1 ring-slate-900/5 dark:bg-slate-900 dark:shadow-none dark:ring-white/10">
            <Avatar
              name={user?.name}
              photoPath={user?.profile?.photo_path}
              avatar={user?.profile?.avatar}
              gender={user?.profile?.gender}
              size={34}
            />
            <div className="min-w-0">
              <p className="truncate text-sm font-medium">{user?.name}</p>
              <p className="truncate text-xs text-slate-400">{user?.app_id}</p>
            </div>
          </div>
        </div>
      </aside>

      {/* Mobile sidebar */}
      {sidebarOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={() => setSidebarOpen(false)} />
          <aside className="pt-safe absolute inset-y-0 left-0 flex h-full w-72 max-w-[85vw] flex-col overflow-hidden bg-white shadow-lift dark:bg-slate-900">
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
        <header className={clsx(
          'pt-safe z-10 shrink-0 items-center justify-between bg-slate-100/85 px-2 py-2 backdrop-blur dark:bg-slate-950/85 sm:px-4 sm:py-3',
          // A meeting has its own title bar and its own way out. On a phone
          // this one was costing 50px of faces to show a hamburger and a
          // logout button nobody wants mid-call.
          immersive || bare ? 'hidden lg:flex' : 'flex',
        )}>
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
        {/* The width cap goes on <main> itself, not on a wrapper inside it.
            A page like the meeting room sizes itself with `h-full`, which only
            resolves against a parent with a definite height — <main> has one
            from `flex-1`, an extra div in between does not, and the meeting
            tiles collapsed from 366x648 to 256x144. */}
        <main ref={mainRef} className={clsx(
          'scroll-pane mx-auto min-h-0 w-full max-w-7xl flex-1 overflow-y-auto',
          // A meeting wants the screen; every other page wants margins.
          bare ? 'p-0' : immersive ? 'p-2 sm:p-4' : 'p-4 sm:p-6',
        )}>
          {/*
            * The waiting happens here, not around the whole app.
            *
            * The only Suspense boundary used to sit above the Routes, so
            * opening a section replaced everything — sidebar, header, the lot —
            * with a full-screen spinner and then painted the new page. Two
            * whole-screen changes to move between two pages that share all
            * their furniture, which is most of what "choppy" was.
            *
            * Inside <main>, only the content area waits, and the chrome you
            * navigated with stays where it is.
            */}
          <Suspense fallback={<RouteFallback />}>
            <div key={pathname} className="route-enter">
              <Outlet />
            </div>
          </Suspense>
          <MeetingSlot />
        </main>

        {/* Mobile bottom bar. Four destinations plus the drawer — the same
            shape every phone app uses, so the common screens stop being two
            taps deep behind a hamburger. */}
        <nav className={clsx(
          'pb-safe shrink-0 items-stretch border-t border-slate-200/70 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90 lg:hidden',
          immersive || bare ? 'hidden' : 'flex',
        )}>
          {bottomTabs.map(({ to, label, icon: Icon }) => {
            const badgeKey = BADGE_KEYS[to]
            const count = badgeKey && badges ? badges[badgeKey] : 0
            return (
              <NavLink
                key={to}
                to={to}
                end={to === '/'}
                onPointerDown={() => preloadPath?.(to)}
                className={({ isActive }) =>
                  clsx(
                    'relative flex flex-1 flex-col items-center justify-center gap-1 py-2 text-[11px] font-medium transition-colors',
                    isActive ? 'text-brand-600 dark:text-brand-400' : 'text-slate-400 dark:text-slate-500',
                  )
                }
              >
                {/* Children as a function, because the pill below needs to know
                    whether the tab is current and `isActive` only exists inside
                    NavLink's own callbacks — reading it out here compiled
                    perfectly well and threw the moment the page rendered. */}
                {({ isActive }) => (
                  <>
                    {/* The lit pill, not just a blue icon, is what makes the
                        current tab obvious at a glance on a small screen. */}
                    <span className={clsx(
                      'flex h-7 w-12 items-center justify-center rounded-full transition-colors',
                      isActive && 'bg-brand-50 dark:bg-brand-500/15',
                    )}>
                      <Icon className="size-5" />
                    </span>
                    {label}
                    {count > 0 && (
                      <span className="absolute right-1/2 top-0.5 -mr-4 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-semibold tabular-nums text-white ring-2 ring-white dark:ring-slate-900">
                        {count > 9 ? '9+' : count}
                      </span>
                    )}
                  </>
                )}
              </NavLink>
            )
          })}
          <button
            className="flex flex-1 flex-col items-center justify-center gap-1 py-2 text-[11px] font-medium text-slate-400 dark:text-slate-500"
            onClick={() => setSidebarOpen(true)}
          >
            <span className="flex h-7 w-12 items-center justify-center">
              <MoreHorizontal className="size-5" />
            </span>
            More
          </button>
        </nav>
      </div>
      {/*
        Its button floats over the bottom-right corner, which is exactly where
        a meeting puts Leave and a chat puts Send — and on a phone it was
        sitting on top of both. A chat has its own microphone in the composer,
        so nothing is lost by standing down there.
      */}
      {!immersive && !chatOpen && <VoiceAssistant />}
    </div>
    </MeetingHost>
    </CallProvider>
  )
}

/**
 * What a section shows while its file is still arriving.
 *
 * Deliberately not a spinner. A spinner that appears for 80ms and vanishes
 * reads as a flicker rather than as progress, and most of these loads are
 * that fast — the routes are pre-fetched while the app sits idle. The delay
 * means nothing is drawn at all unless the wait is long enough to be worth
 * acknowledging, which for a fast load is never.
 */
function RouteFallback() {
  return (
    <div className="route-fallback flex items-center justify-center py-16">
      <Spinner />
    </div>
  )
}
