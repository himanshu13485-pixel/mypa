import { type ReactNode, useState } from 'react'
import { Link, NavLink } from 'react-router-dom'
import { Menu, X } from 'lucide-react'
import NetvorkMark from '../../components/Logo'

/**
 * Public site frame (landing, about, contact, legal): dark console
 * aesthetic with the cyan accent, independent from the in-app shell.
 */
export default function SiteShell({ children }: { children: ReactNode }) {
  const [open, setOpen] = useState(false)

  const links = [
    { to: '/home#platform', label: 'Platform', hash: true },
    { to: '/home#capabilities', label: 'Capabilities', hash: true },
    { to: '/pricing', label: 'Pricing' },
    { to: '/about', label: 'About' },
    { to: '/contact', label: 'Contact' },
  ]

  return (
    <div className="min-h-screen bg-[#070A12] font-sans text-slate-200">
      <header className="sticky top-0 z-40 border-b border-white/10 bg-[#070A12]/90 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4">
          <Link to="/home" className="flex items-center gap-2.5">
            <NetvorkMark className="size-8" />
            <span className="text-lg font-bold tracking-tight text-white">Netvork</span>
          </Link>
          <nav className="hidden items-center gap-6 text-sm text-slate-300 md:flex">
            {links.map((l) => (
              <NavLink key={l.label} to={l.to} className="transition-colors hover:text-[#6CE9FF]">
                {l.label}
              </NavLink>
            ))}
          </nav>
          <div className="hidden items-center gap-2 md:flex">
            <Link to="/login" className="rounded-lg px-3 py-1.5 text-sm text-slate-200 transition-colors hover:text-[#6CE9FF]">
              Sign in
            </Link>
            <Link
              to="/register"
              className="rounded-lg bg-[#6CE9FF] px-4 py-1.5 text-sm font-semibold text-[#070A12] transition-opacity hover:opacity-90"
            >
              Start free
            </Link>
          </div>
          <button className="md:hidden" onClick={() => setOpen(!open)} aria-label="Menu">
            {open ? <X className="size-5" /> : <Menu className="size-5" />}
          </button>
        </div>
        {open && (
          <div className="border-t border-white/10 px-4 py-3 md:hidden">
            {links.map((l) => (
              <NavLink key={l.label} to={l.to} className="block py-2 text-sm text-slate-300" onClick={() => setOpen(false)}>
                {l.label}
              </NavLink>
            ))}
            <div className="mt-2 flex gap-2">
              <Link to="/login" className="flex-1 rounded-lg border border-white/20 px-3 py-2 text-center text-sm">Sign in</Link>
              <Link to="/register" className="flex-1 rounded-lg bg-[#6CE9FF] px-3 py-2 text-center text-sm font-semibold text-[#070A12]">Start free</Link>
            </div>
          </div>
        )}
      </header>

      {children}

      <footer className="border-t border-white/10">
        <div className="mx-auto max-w-6xl px-4 py-10">
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div className="flex items-center gap-2.5">
              <NetvorkMark className="size-7" />
              <div>
                <p className="text-sm font-semibold text-white">Netvork — Client Operations Platform</p>
                <p className="text-xs italic text-slate-500">One App. Every Task. Every Connection.</p>
              </div>
            </div>
            <nav className="flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-400">
              <Link to="/about" className="hover:text-[#6CE9FF]">About Us</Link>
              <Link to="/pricing" className="hover:text-[#6CE9FF]">Pricing</Link>
              <Link to="/contact" className="hover:text-[#6CE9FF]">Contact Us</Link>
              <Link to="/terms" className="hover:text-[#6CE9FF]">Terms &amp; Conditions</Link>
              <Link to="/privacy" className="hover:text-[#6CE9FF]">Privacy Policy</Link>
            </nav>
          </div>
          <p className="mt-6 font-mono text-[11px] uppercase tracking-widest text-slate-600">
            © 2026 · Built for service teams
          </p>
        </div>
      </footer>
    </div>
  )
}

/** Simple content page wrapper for the legal/info pages. */
export function SitePage({ title, children }: { title: string; children: ReactNode }) {
  return (
    <SiteShell>
      <main className="mx-auto max-w-3xl px-4 py-14">
        <p className="font-mono text-xs uppercase tracking-widest text-[#6CE9FF]">Netvork</p>
        <h1 className="mt-2 text-3xl font-bold text-white">{title}</h1>
        <div className="prose-invert mt-8 space-y-5 text-sm leading-6 text-slate-300">{children}</div>
      </main>
    </SiteShell>
  )
}
