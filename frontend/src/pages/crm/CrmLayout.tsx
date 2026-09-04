import { useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { LeadFollowUpAlerts } from './LeadFollowUpAlerts'
import { NewLeadAlerts } from './NewLeadAlerts'
import { ComplaintAlerts } from './ComplaintAlerts'
import { BirthdayVibes } from './BirthdayVibes'
import { FestivalVibes } from './FestivalVibes'
import { CallProvider } from '../../components/CallManager'
import ImpersonationBanner from '../../components/ImpersonationBanner'
import NotificationBell from '../../components/NotificationBell'
import { useQuery } from '@tanstack/react-query'
import {
  ArrowLeft,
  ArrowLeftRight,
  Award,
  Banknote,
  BarChart3,
  Boxes,
  Briefcase,
  Building2,
  Activity,
  CalendarClock, CalendarDays,
  CalendarOff,
  CheckSquare,
  ClipboardCheck,
  FileText,
  Filter,
  Fingerprint,
  HandCoins,
  History,
  LayoutDashboard,
  LayoutTemplate,
  LifeBuoy,
  ListChecks,
  Mail,
  Menu,
  MessageSquare,
  MonitorUp,
  PhoneCall,
  NotebookPen,
  ReceiptText,
  Repeat,
  Settings2,
  Scale,
  ScrollText,
  Sparkles,
  Store,
  Target,
  TrendingDown,
  TrendingUp,
  Users,
  Video,
  Wallet,
  X,
} from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { crm, crmCan, setCrmOrg, type CrmMe } from '../../api/crm'
import { Spinner } from '../../components/ui'

interface NavItem {
  label: string
  /** Which trail section this entry counts unread activity from. */
  section?: string
  icon: typeof LayoutDashboard
  /** Route when the module is built; absent = not built yet, shown as Soon. */
  to?: string
  /** Rights module gating visibility; absent = visible to every member. */
  module?: string
  ability?: string
  /** Company authority only — admins and subadmins, never a right. */
  managerOnly?: boolean
  /** The Company Admin alone: house rules the whole company is held to. */
  adminOnly?: boolean
  /** Admin always; a Subadmin only when named with this raw capability. */
  capability?: string
  /** Which attend-counter this entry wears as its (n) number. */
  badge?: string
}

/**
 * The agreed structure: the 20 CRM modules in four groups matching how
 * people think about their day. Modules not built yet stay visible but
 * disabled — the sidebar is the roadmap, and it never reshuffles as
 * modules land.
 */
const SECTIONS: { label: string; items: NavItem[] }[] = [
  { label: 'Work', items: [
    { label: 'Dashboard', icon: LayoutDashboard, to: '/crm' },
    // Who is here and what is running — company-wide, so admins only.
    { label: 'Overview', icon: Activity, to: '/crm/overview', adminOnly: true },
    { label: 'My DWR', section: 'dwr', icon: NotebookPen, to: '/crm/dwr' },
    { label: 'Punch', section: 'punch', icon: Fingerprint, to: '/crm/punch' },
    { label: 'Tasks', section: 'tasks', icon: CheckSquare, to: '/crm/tasks', badge: 'tasks' },
    { label: 'Leaves', section: 'leaves', icon: CalendarOff, to: '/crm/leaves', badge: 'leaves' },
  ]},
  // The Netvork Connect suite, hosted inside the CRM: the same
  // connections, chats, calls, meetings and screen tools as the personal
  // side — one inbox, two doors.
  { label: 'Connect', items: [
    { label: 'Connections', icon: Users, to: '/crm/connect/connections', badge: 'connections' },
    { label: 'Family & Teams', icon: Users, to: '/crm/connect/groups' },
    { label: 'Call/Messages', icon: MessageSquare, to: '/crm/connect/messages', badge: 'messages' },
    { label: 'Call Logs', icon: PhoneCall, to: '/crm/connect/calls' },
    { label: 'Meetings', icon: Video, to: '/crm/connect/meetings' },
    { label: 'Screen', icon: MonitorUp, to: '/crm/connect/screen' },
    { label: 'Calendar', icon: CalendarDays, to: '/crm/connect/calendar' },
    { label: 'Book@Meetings', icon: CalendarClock, to: '/crm/connect/booking' },
  ]},
  { label: 'Sales', items: [
    { label: 'Leads', section: 'leads', icon: Filter, to: '/crm/leads', module: 'leads', badge: 'leads' },
    { label: 'Lead log', icon: ListChecks, to: '/crm/lead-log', module: 'lead_log' },
    { label: 'Clients', section: 'clients', icon: Briefcase, to: '/crm/clients', module: 'clients' },
    { label: 'Targets', section: 'targets', icon: Target, to: '/crm/targets', module: 'targets' },
    { label: 'Contest', section: 'contests', icon: Award, to: '/crm/contests', badge: 'contests' },
  ]},
  { label: 'Money', items: [
    { label: 'Proforma', section: 'proforma', icon: FileText, to: '/crm/invoices?kind=proforma', module: 'proforma' },
    { label: 'Proforma log', icon: ListChecks, to: '/crm/invoice-log?kind=proforma', module: 'proforma_log' },
    { label: 'Invoices', section: 'invoices', icon: ReceiptText, to: '/crm/invoices?kind=invoice', module: 'invoices' },
    { label: 'Invoice log', icon: ListChecks, to: '/crm/invoice-log?kind=invoice', module: 'invoice_log' },
    { label: 'Recurring', icon: Repeat, to: '/crm/recurring', module: 'recurring' },
    { label: 'Payments', section: 'payments', icon: Banknote, to: '/crm/payments', module: 'payments', badge: 'payments' },
    { label: 'Vendors', section: 'vendors', icon: Store, to: '/crm/vendors', module: 'vendors' },
    { label: 'Expenses', section: 'expenses', icon: Wallet, to: '/crm/expenses', module: 'expenses' },
    { label: 'Commissions', section: 'commissions', icon: HandCoins, to: '/crm/commissions', module: 'commissions' },
    { label: 'Incentives', icon: TrendingUp, to: '/crm/incentives' },
    { label: 'Salary', section: 'salary', icon: Wallet, to: '/crm/salary' },
    { label: 'P&L', icon: Scale, to: '/crm/pl', adminOnly: true },
  ]},
  { label: 'Manage', items: [
    { label: 'Users', section: 'employees', icon: Users, to: '/crm/employees', module: 'employees' },
    { label: 'Approvals', section: 'approvals', icon: ClipboardCheck, to: '/crm/approvals', badge: 'approvals' },
    { label: 'Newsletters', section: 'newsletters', icon: Mail, to: '/crm/newsletters', module: 'newsletters' },
    { label: 'Complaints (CMS)', section: 'complaints', icon: LifeBuoy, to: '/crm/complaints', module: 'complaints', badge: 'complaints' },
    { label: 'Complaint log', icon: ListChecks, to: '/crm/complaint-log', module: 'complaint_log' },
    { label: 'Notice board', section: 'cms', icon: LayoutTemplate, to: '/crm/cms', badge: 'notice' },
    { label: 'User log', icon: History, to: '/crm/user-log', module: 'user_log' },
    { label: 'Reports', icon: BarChart3, to: '/crm/reports', capability: 'reports.view' },
    { label: 'Churn', icon: TrendingDown, to: '/crm/churn' },
    { label: 'Office Assets', section: 'assets', icon: Boxes, to: '/crm/assets' },
    { label: 'Communication', icon: Mail, to: '/crm/communication', managerOnly: true },
    { label: 'Billing setup', section: 'settings', icon: Settings2, to: '/crm/settings', module: 'masters', ability: 'edit' },
    { label: 'HR Policy', icon: ScrollText, to: '/crm/hr-policy', adminOnly: true },
    { label: 'Workspace fields', icon: Sparkles, to: '/crm/workspace-fields', managerOnly: true },
  ]},
]

function visible(me: CrmMe | undefined, item: NavItem): boolean {
  if (!me?.enabled) return false
  if (item.adminOnly) {
    return me.member?.crm_role === 'admin'
  }
  if (item.managerOnly) {
    return me.member?.crm_role === 'admin' || me.member?.crm_role === 'subadmin'
  }
  if (item.capability) {
    if (me.member?.crm_role === 'admin') return true
    return me.member?.crm_role === 'subadmin'
      && (me.member?.capabilities ?? []).includes(item.capability)
  }
  if (!item.module) return true
  return crmCan(me, item.module, item.ability ?? 'view')
}

export default function CrmLayout() {
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const { data: me, isLoading } = useQuery({ queryKey: ['crm', 'me'], queryFn: crm.me })
  // The phone menu: the same list as the sidebar, behind the three lines.
  const [menuOpen, setMenuOpen] = useState(false)

  // Take the company hat off: back to the Super Admin's own context and
  // the organizations screen, with nothing cached under the old hat.
  const exitOversight = () => {
    setCrmOrg(null)
    queryClient.invalidateQueries({ queryKey: ['crm'] })
    navigate('/crm/organizations')
  }

  // The pending-work counters — refreshed every minute so anyone sitting
  // anywhere in the CRM sees new requests arrive.
  const { data: badges } = useQuery({
    queryKey: ['crm', 'badges'],
    queryFn: crm.badges,
    enabled: !!me?.enabled,
    refetchInterval: 60_000,
  })
  const attend = badges?.attend ?? {}
  const attendTotal = Object.values(attend).reduce((a, b) => a + b, 0)

  // When more work lands on this desk than a minute ago: a short chime and
  // a desktop notification naming the sections. Quiet on first load, and
  // quiet while numbers fall — attending work should never make noise.
  const prevAttend = useRef<number | null>(null)
  useEffect(() => {
    if (!badges?.attend) return
    const prev = prevAttend.current
    prevAttend.current = attendTotal
    if (prev === null || attendTotal <= prev) return
    try {
      type WebkitWindow = Window & { webkitAudioContext?: typeof AudioContext }
      const Ctx = window.AudioContext ?? (window as WebkitWindow).webkitAudioContext
      const ctx = new Ctx()
      const osc = ctx.createOscillator()
      const gain = ctx.createGain()
      osc.connect(gain)
      gain.connect(ctx.destination)
      osc.frequency.value = 880
      gain.gain.value = 0.08
      osc.start()
      osc.frequency.setValueAtTime(660, ctx.currentTime + 0.15)
      gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.4)
      osc.stop(ctx.currentTime + 0.45)
      setTimeout(() => { ctx.close().catch(() => {}) }, 700)
    } catch { /* the sound is a nicety — the badge still shows */ }
    try {
      if ('Notification' in window) {
        if (Notification.permission === 'default') Notification.requestPermission()
        if (Notification.permission === 'granted') {
          const labels: Record<string, string> = {
            connections: 'Connections', messages: 'Call/Messages', leads: 'Leads',
            tasks: 'Tasks', leaves: 'Leaves', contests: 'Contest', payments: 'Payments',
            approvals: 'Approvals', complaints: 'CMS', notice: 'Notice board',
          }
          const body = Object.entries(badges.attend)
            .filter(([, v]) => v > 0)
            .map(([k, v]) => `${labels[k] ?? k} (${v})`)
            .join(' · ')
          new Notification('Netvork CRM — needs your attention', { body, tag: 'crm-attend' })
        }
      }
    } catch { /* notifications blocked — the badge still shows */ }
  }, [attendTotal, badges?.attend])

  /*
   * Opening a section is reading it: the badge goes quiet, for this
   * member only, and the next thing a colleague does brings it back.
   *
   * Above the early returns, with every other hook. It used to sit
   * below them, so the first render — while /crm/me was still in the
   * air — ran one hook fewer than the render after it, and React threw
   * #310 across the whole screen. A hook cannot be conditional, and an
   * early return is a condition.
   */
  const currentSection = SECTIONS
    .flatMap((group) => group.items)
    .find((item) => item.to && item.section && (
      location.pathname === item.to.split('?')[0]
      || location.pathname.startsWith(item.to.split('?')[0] + '/')
    ))?.section

  useEffect(() => {
    if (!currentSection || !badges?.sections?.[currentSection]) return
    crm.markSectionSeen(currentSection)
      .then(() => queryClient.invalidateQueries({ queryKey: ['crm', 'badges'] }))
      .catch(() => { /* a badge that lingers is not worth an error */ })
  }, [currentSection, badges?.sections, queryClient])

  if (isLoading) {
    return (
      <div className="flex min-h-dvh items-center justify-center bg-slate-100 dark:bg-slate-950">
        <Spinner />
      </div>
    )
  }

  // Super admins land on the organizations screen even without a membership:
  // that screen is the addon's on switch.
  if (!me?.enabled && !me?.is_super_admin) {
    return (
      <div className="flex min-h-dvh flex-col items-center justify-center gap-4 bg-slate-100 p-6 text-center dark:bg-slate-950">
        <Building2 className="size-10 text-slate-400" />
        <div>
          <h1 className="text-lg font-semibold text-slate-800 dark:text-slate-100">CRM is not enabled for your account</h1>
          <p className="mt-1 text-sm text-slate-500">Ask your organization's CRM admin to add you as an employee.</p>
        </div>
        <button onClick={() => navigate('/')} className="text-sm font-medium text-brand-600 hover:underline">
          Back to Netvork
        </button>
      </div>
    )
  }

  /**
   * NavLink ignores the query string, so Proforma / Proforma log / Invoices /
   * Invoice log all point at two paths and would light in pairs. The kind
   * decides, and a document page with no kind reads as a tax invoice.
   */
  const isNavActive = (to: string) => {
    const [path, query] = to.split('?')
    if (path === '/crm') return location.pathname === '/crm'
    if (location.pathname !== path && !location.pathname.startsWith(path + '/')) return false
    if (!query) return true
    const want = new URLSearchParams(query)
    const have = new URLSearchParams(location.search)

    return [...want.entries()].every(([key, value]) =>
      (have.get(key) ?? (key === 'kind' ? 'invoice' : null)) === value)
  }

  const linkClass = (isActive: boolean) =>
    clsx(
      'flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm transition-colors',
      isActive
        ? 'bg-emerald-500/15 font-medium text-emerald-400'
        : 'text-slate-300 hover:bg-white/5 hover:text-white',
    )

  /*
   * The menu itself, written once. The desktop rail and the phone drawer
   * are two frames around this same list — so an entry added here appears
   * in both, in the same group and the same order, which is the whole
   * point of a menu somebody has learned.
   */
  const sidebar = (
    <>
        <div className="flex items-center gap-2.5 px-5 pb-3 pt-5">
          <div className="flex size-9 items-center justify-center rounded-xl bg-emerald-500 font-bold text-white">N</div>
          <div className="text-sm font-semibold text-white">Netvork CRM</div>
        </div>

        {/* The workspace switcher: this is the company hat. Clicking it takes
            you back to the personal side. The label says what YOUR window
            is — company for admins, team for team heads, employee otherwise. */}
        <button
          onClick={() => navigate('/')}
          title="Switch to your personal workspace"
          className="mx-3 flex items-center gap-2.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-left transition-colors hover:bg-emerald-500/20"
        >
          <Building2 className="size-4 shrink-0 text-emerald-400" />
          <span className="min-w-0 flex-1">
            <span className="block truncate text-xs font-medium text-emerald-300">
              {me?.organization?.name ?? 'No organization'}
            </span>
            <span className="block truncate text-[11px] text-emerald-500/80">
              {/* The person's own name rides with the label, so two people
                  on one machine always know whose window this is. */}
              {me?.member?.is_oversight ? 'Viewing as Super Admin'
                : me?.member?.crm_role === 'admin' ? 'Company workspace'
                  : me?.member?.crm_role === 'subadmin' ? `${me?.member?.name ? me.member.name + ' · ' : ''}Subadmin workspace`
                    : me?.has_team ? `${me?.member?.name ? me.member.name + ' · ' : ''}Team workspace`
                      : `${me?.member?.name ? me.member.name + ' · ' : ''}Employee workspace`}
            </span>
          </span>
          <ArrowLeftRight className="size-3.5 shrink-0 text-emerald-500/80" />
        </button>

        {/* The way back: the Super Admin inside a company always sees the
            exit — no more being stuck wearing a company's hat. */}
        {me?.member?.is_oversight && (
          <button
            onClick={exitOversight}
            className="mx-3 mt-2 flex items-center justify-center gap-2 rounded-xl border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs font-semibold text-amber-300 transition-colors hover:bg-amber-500/20"
          >
            <ArrowLeft className="size-3.5" />
            Exit to Super Admin
          </button>
        )}

        <nav className="scroll-pane mt-2 flex-1 overflow-y-auto px-3 pb-3">
          {SECTIONS.map((section) => {
            const items = section.items.filter((i) => !i.to || visible(me, i))
            if (!me?.enabled) return null
            return (
              <div key={section.label}>
                <p className="mb-0.5 mt-3 px-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                  {section.label}
                </p>
                {items.map((item) =>
                  item.to ? (
                    <NavLink
                      key={item.label}
                      to={item.to}
                      end={item.to === '/crm'}
                      className={linkClass(isNavActive(item.to!))}
                    >
                      <item.icon className="size-4 shrink-0" />
                      <span className="flex-1">{item.label}</span>
                      {/* Work waiting on you is red; what colleagues did
                          while you were away is quieter. */}
                      {item.badge && (attend[item.badge] ?? 0) > 0 ? (
                        <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 text-[11px] font-semibold tabular-nums text-white">
                          {attend[item.badge]! > 99 ? '99+' : attend[item.badge]}
                        </span>
                      ) : item.section && (badges?.sections?.[item.section] ?? 0) > 0 ? (
                        <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-emerald-500/20 px-1.5 text-[11px] font-semibold tabular-nums text-emerald-300">
                          {badges!.sections![item.section]! > 99 ? '99+' : badges!.sections![item.section]}
                        </span>
                      ) : null}
                    </NavLink>
                  ) : (
                    <div
                      key={item.label}
                      title="Coming soon"
                      className="flex cursor-default items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm text-slate-600"
                    >
                      <item.icon className="size-4 shrink-0" />
                      <span className="flex-1">{item.label}</span>
                      <span className="rounded-full bg-slate-800 px-1.5 py-0.5 text-[9px] font-medium uppercase tracking-wide text-slate-500">
                        Soon
                      </span>
                    </div>
                  ),
                )}
              </div>
            )
          })}
          {/* Super Admin tools belong to the Super Admin's own context. While
              wearing a company's hat you are that company's admin — the way
              out is the Exit button, not a stray Organizations link. */}
          {me?.is_super_admin && !me?.member?.is_oversight && (
            <div>
              <p className="mb-0.5 mt-3 px-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                Super admin
              </p>
              <NavLink to="/crm/organizations" className={({ isActive }) => linkClass(isActive)}>
                <Building2 className="size-4 shrink-0" />
                Organizations
              </NavLink>
              <NavLink to="/crm/field-requests" className={({ isActive }) => linkClass(isActive)}>
                <Sparkles className="size-4 shrink-0" />
                Field requests
              </NavLink>
            </div>
          )}
        </nav>
    </>
  )

  return (
    // CallProvider here too: the Connect suite lives inside the CRM shell,
    // so calls must ring and connect without leaving it.
    <CallProvider>
    {/* Above everything, because forgetting whose account you are working in
        is how a record gets changed by the wrong hand. Draws nothing at all
        when the session is an ordinary one. */}
    <ImpersonationBanner />
    {/*
      * A shell the height of the window, scrolling inside itself.
      *
      * It used to be min-h-dvh and let the document scroll, which meant
      * <main> had no height of its own — so a page asking for h-full got
      * auto, and the chat grew as tall as its thread instead of scrolling
      * inside it: the box you type into ended up below the fold. Now <main>
      * owns the scrolling, exactly as the personal shell does, and the two
      * headers above it stay put for free.
      */}
    <div data-print-root className="flex h-dvh overflow-hidden bg-slate-100 dark:bg-slate-950">
      <aside data-print-chrome className="fixed inset-y-0 left-0 z-30 hidden w-60 flex-col border-r border-slate-800 bg-slate-900 text-slate-300 md:flex">
        {sidebar}
      </aside>

      {/* The same menu on a phone, behind the three lines — one list, one
          order, whichever size the screen is. */}
      {menuOpen && (
        <div className="fixed inset-0 z-40 md:hidden">
          <div className="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" onClick={() => setMenuOpen(false)} />
          <aside
            onClick={(e) => { if ((e.target as HTMLElement).closest('a')) setMenuOpen(false) }}
            className="pt-safe absolute inset-y-0 left-0 flex h-full w-72 max-w-[85vw] flex-col overflow-hidden bg-slate-900 text-slate-300 shadow-lift"
          >
            <button
              onClick={() => setMenuOpen(false)}
              aria-label="Close menu"
              className="absolute right-3 top-4 z-10 rounded-lg p-2 text-slate-400 hover:bg-white/10 hover:text-white"
            >
              <X className="size-5" />
            </button>
            {sidebar}
          </aside>
        </div>
      )}


      {/* Phone: the three lines, the bell, and the workspace switch — the
          menu itself lives in the drawer, as it does on the personal side.
          The old scrolling strip of thirty pills could only ever show four
          of them, so the rest of the CRM was off the side of the screen. */}
      <div data-print-root className="flex min-h-0 min-w-0 flex-1 flex-col md:pl-60">
        <header data-print-chrome className="pt-safe sticky top-0 z-30 flex items-center gap-2 border-b border-slate-200 bg-white px-3 py-2 dark:border-slate-800 dark:bg-slate-900 md:hidden">
          <button
            onClick={() => setMenuOpen(true)}
            aria-label="Open menu"
            className="relative rounded-lg p-2 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
          >
            <Menu className="size-5" />
            {/* Work waiting behind a closed menu still says so. */}
            {attendTotal > 0 && (
              <span className="absolute right-1 top-1 size-2 rounded-full bg-red-500" />
            )}
          </button>
          <span className="min-w-0 flex-1 truncate text-sm font-semibold text-slate-800 dark:text-slate-100">
            {me?.organization?.name ?? 'Netvork CRM'}
          </span>
          {me?.member?.is_oversight && (
            <button
              onClick={exitOversight}
              className="shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-400"
            >
              Exit
            </button>
          )}
          <button
            onClick={() => navigate('/')}
            aria-label="Switch to personal workspace"
            className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
          >
            <ArrowLeftRight className="size-4" />
          </button>
          <NotificationBell />
        </header>
        {/* The CRM has its own shell, so it needs its own bell — the same
            one the rest of Netvork uses, reading the same notifications. */}
        <div data-print-chrome className="sticky top-0 z-20 hidden items-center justify-end gap-1 border-b border-slate-200 bg-white/90 px-6 py-2 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90 md:flex">
          <NotificationBell />
        </div>
        <main className="scroll-pane min-h-0 min-w-0 flex-1 overflow-y-auto p-4 sm:p-6">
          <Outlet context={{ me }} />
          {/* The follow-up nag rides the shell so it fires on every screen. */}
          <LeadFollowUpAlerts me={me} />
          <NewLeadAlerts me={me} />
          <ComplaintAlerts me={me} />
          <BirthdayVibes me={me} />
          <FestivalVibes me={me} />
        </main>
      </div>
    </div>
    </CallProvider>
  )
}
