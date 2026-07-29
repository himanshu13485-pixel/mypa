import { useEffect, useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import {
  BarChart3, Calendar, CheckSquare, FileText, FolderKanban, FolderOpen,
  LayoutDashboard, LogOut, Menu, MessageCircle, Moon, Phone, Settings, Shield,
  Star, Sun, UserPlus, Users, X,
} from 'lucide-react'
import { clsx } from 'clsx'
import { auth } from '../api/endpoints'
import { isAdmin, useAuthStore } from '../stores/auth'
import NotificationBell from './NotificationBell'
import { CallProvider } from './CallManager'

const nav = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/tasks', label: 'My Tasks', icon: CheckSquare },
  { to: '/tasks?important=1', label: 'Important', icon: Star },
  { to: '/calendar', label: 'Calendar', icon: Calendar },
  { to: '/categories', label: 'Categories', icon: FolderKanban },
  { to: '/messages', label: 'Messages', icon: MessageCircle },
  { to: '/calls', label: 'Calls', icon: Phone },
  { to: '/notes', label: 'Notes', icon: FileText },
  { to: '/files', label: 'Files', icon: FolderOpen },
  { to: '/groups', label: 'Family & Teams', icon: Users },
  { to: '/connections', label: 'Connections', icon: UserPlus },
  { to: '/reports', label: 'Reports', icon: BarChart3 },
  { to: '/settings', label: 'Settings', icon: Settings },
]

function applyTheme(dark: boolean) {
  document.documentElement.classList.toggle('dark', dark)
}

export default function Layout() {
  const navigate = useNavigate()
  const { user, clear } = useAuthStore()
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [dark, setDark] = useState(() =>
    localStorage.getItem('mypa-theme')
      ? localStorage.getItem('mypa-theme') === 'dark'
      : window.matchMedia('(prefers-color-scheme: dark)').matches,
  )

  useEffect(() => {
    applyTheme(dark)
    localStorage.setItem('mypa-theme', dark ? 'dark' : 'light')
  }, [dark])

  const logout = async () => {
    try {
      await auth.logout()
    } finally {
      clear()
      navigate('/login')
    }
  }

  const links = (
    <nav className="flex flex-1 flex-col gap-0.5 p-3">
      {nav.map(({ to, label, icon: Icon }) => (
        <NavLink
          key={to}
          to={to}
          end={to === '/'}
          onClick={() => setSidebarOpen(false)}
          className={({ isActive }) =>
            clsx(
              'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
              isActive
                ? 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300'
                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
            )
          }
        >
          <Icon className="size-4" />
          {label}
        </NavLink>
      ))}
      {isAdmin(user) && (
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
    <div className="flex h-full">
      {/* Desktop sidebar */}
      <aside className="hidden w-60 shrink-0 flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 lg:flex">
        <div className="flex items-center gap-2 px-5 py-4">
          <div className="flex size-8 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">
            PA
          </div>
          <span className="text-base font-semibold">My PA</span>
        </div>
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
          <aside className="absolute inset-y-0 left-0 flex w-64 flex-col bg-white dark:bg-slate-900">
            <div className="flex items-center justify-between px-5 py-4">
              <span className="text-base font-semibold">My PA</span>
              <button onClick={() => setSidebarOpen(false)}>
                <X className="size-5" />
              </button>
            </div>
            {links}
          </aside>
        </div>
      )}

      {/* Main */}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
          <button className="lg:hidden" onClick={() => setSidebarOpen(true)}>
            <Menu className="size-5" />
          </button>
          <div className="hidden lg:block" />
          <div className="flex items-center gap-2">
            <NotificationBell />
            <button
              onClick={() => setDark(!dark)}
              className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
              title="Toggle theme"
            >
              {dark ? <Sun className="size-4" /> : <Moon className="size-4" />}
            </button>
            <button
              onClick={logout}
              className="flex items-center gap-1.5 rounded-lg p-2 text-sm text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
              title="Log out"
            >
              <LogOut className="size-4" />
            </button>
          </div>
        </header>
        <main className="flex-1 overflow-y-auto p-4 lg:p-6">
          <Outlet />
        </main>
      </div>
    </div>
    </CallProvider>
  )
}
