import { Link } from 'react-router-dom'
import {
  BarChart3, Bell, FileText, FolderOpen, Lock, MessageCircle, Search, ShieldCheck, Users,
} from 'lucide-react'
import SiteShell from './SiteShell'

const CYAN = 'text-[#6CE9FF]'

/** The Netvork landing page — dark console design from the brand index page. */
export default function LandingPage() {
  return (
    <SiteShell>
      {/* ── SEC 01 / HERO ─────────────────────────────────────────────── */}
      <section className="relative overflow-hidden">
        <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,rgba(108,233,255,0.08),transparent_60%)]" />
        <div className="mx-auto max-w-6xl px-4 pb-20 pt-16">
          <p className={`font-mono text-xs uppercase tracking-[0.3em] ${CYAN}`}>SEC-01 / HERO · Client Operations Platform</p>
          <h1 className="mt-5 max-w-3xl text-4xl font-bold leading-tight text-white sm:text-6xl">
            One surface. <span className={CYAN}>Every client.</span> Every task. Every connection.
          </h1>
          <p className="mt-5 max-w-2xl text-base leading-7 text-slate-400">
            Connect <b className="text-slate-200">any client</b>. Assign <b className="text-slate-200">the work</b>.
            Remind <b className="text-slate-200">automatically</b>. Talk <b className="text-slate-200">in real time</b>.
            Share <b className="text-slate-200">every document</b>. Report <b className="text-slate-200">on all of it</b>.
          </p>
          <div className="mt-8 flex flex-wrap items-center gap-3">
            <Link
              to="/register"
              className="rounded-lg bg-[#6CE9FF] px-6 py-3 text-sm font-semibold text-[#070A12] transition-opacity hover:opacity-90"
            >
              Start free
            </Link>
            <Link
              to="/login"
              className="rounded-lg border border-white/20 px-6 py-3 text-sm font-semibold text-slate-200 transition-colors hover:border-[#6CE9FF] hover:text-[#6CE9FF]"
            >
              Sign in
            </Link>
            <span className="font-mono text-[11px] uppercase tracking-widest text-slate-500">
              Free plan · no card required
            </span>
          </div>

          {/* status strip */}
          <div className="mt-14 grid gap-3 sm:grid-cols-3">
            {[
              ['System status', 'ALL SYSTEMS NOMINAL', 'UPTIME 99.98'],
              ['Completion rate', '92.4% ON TIME', 'ACROSS EVERY ACCOUNT'],
              ['Avg. turnaround', '1.8 DAYS', 'CREATED VS COMPLETED · 7D'],
            ].map(([label, value, sub]) => (
              <div key={label} className="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                <p className="font-mono text-[10px] uppercase tracking-widest text-slate-500">{label}</p>
                <p className={`mt-1 font-mono text-lg font-bold ${CYAN}`}>{value}</p>
                <p className="mt-0.5 font-mono text-[10px] uppercase tracking-widest text-slate-600">{sub}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── One system instead of… ───────────────────────────────────── */}
      <section className="border-t border-white/10">
        <div className="mx-auto max-w-6xl px-4 py-16">
          <h2 className="text-2xl font-bold text-white">
            One system instead of <span className="text-slate-500 line-through decoration-red-400/60">eight subscriptions</span>
          </h2>
          <div className="mt-6 flex flex-wrap gap-2">
            {['Task manager', 'Notes app', 'File sharing', 'Team chat', 'Video calls', 'Reminder bot', 'Client notes doc', 'Shared calendar'].map((x) => (
              <span key={x} className="rounded-full border border-white/10 px-3.5 py-1.5 font-mono text-xs text-slate-400 line-through decoration-red-400/50">
                {x}
              </span>
            ))}
            <span className={`rounded-full border border-[#6CE9FF]/50 bg-[#6CE9FF]/10 px-3.5 py-1.5 font-mono text-xs font-semibold ${CYAN}`}>
              → Netvork
            </span>
          </div>
          <p className="mt-6 max-w-2xl text-sm leading-6 text-slate-400">
            One record at the centre. Everything orbits it — tasks &amp; follow-ups, reports, reminders,
            internal notes, chat &amp; calls, documents. Client operations, one object.
          </p>
        </div>
      </section>

      {/* ── SEC / PLATFORM ───────────────────────────────────────────── */}
      <section id="platform" className="border-t border-white/10">
        <div className="mx-auto max-w-6xl px-4 py-16">
          <p className={`font-mono text-xs uppercase tracking-[0.3em] ${CYAN}`}>· Platform</p>
          <h2 className="mt-3 text-3xl font-bold text-white">The whole relationship, rendered as one machine</h2>
          <div className="mt-10 grid gap-8 md:grid-cols-2">
            {[
              {
                n: '01 / Accounts',
                title: 'One page per client, and everything on it',
                points: [
                  'Permanent account IDs that survive name and email changes',
                  'Internal notes are attributed, timestamped and immutable',
                  'Privacy settings decide who can find or message you',
                ],
              },
              {
                n: '02 / Follow-ups',
                title: 'Nothing falls through because nobody remembered',
                points: [
                  'Priorities, checklists, subtasks and recurring schedules',
                  'Reminders by in-app notification, email or web push',
                  'An activity log on every task showing who changed what',
                ],
              },
              {
                n: '03 / Conversations',
                title: 'Talk to them without leaving the record',
                points: [
                  'Messages arrive over WebSockets, with polling as a fallback',
                  'Voice notes and file attachments in the thread',
                  'One-to-one and group audio or video calls',
                ],
              },
              {
                n: '04 / Reporting',
                title: 'Answer “where are we?” without a spreadsheet',
                points: [
                  'Completion and turnaround across every account',
                  'Created versus completed, up to 90 days',
                  'CSV export, UTF-8 and Excel-safe',
                ],
              },
            ].map((f) => (
              <div key={f.n} className="rounded-xl border border-white/10 bg-white/[0.03] p-6">
                <p className={`font-mono text-xs uppercase tracking-widest ${CYAN}`}>{f.n}</p>
                <h3 className="mt-2 text-lg font-semibold text-white">{f.title}</h3>
                <ul className="mt-4 space-y-2">
                  {f.points.map((p) => (
                    <li key={p} className="flex gap-2 text-sm leading-6 text-slate-400">
                      <span className={`mt-1 ${CYAN}`}>▸</span> {p}
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── Reach ────────────────────────────────────────────────────── */}
      <section className="border-t border-white/10">
        <div className="mx-auto max-w-6xl px-4 py-16">
          <p className={`font-mono text-xs uppercase tracking-[0.3em] ${CYAN}`}>· Reach</p>
          <h2 className="mt-3 text-2xl font-bold text-white">Wherever the book is, the record follows.</h2>
          <p className="mt-2 text-sm text-slate-400">
            Server-side scheduler — reminders materialise every minute in the owner&apos;s timezone.
          </p>
          <div className="mt-6 grid gap-3 sm:grid-cols-3">
            {[
              ['Mumbai · IST', '09:00 QUEUE'],
              ['London · GMT', '04:30 QUEUE'],
              ['New York · EST', '23:30 QUEUE'],
            ].map(([city, q]) => (
              <div key={city} className="flex items-center justify-between rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3">
                <span className="text-sm text-slate-300">{city}</span>
                <span className={`font-mono text-xs ${CYAN}`}>{q}</span>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── Capabilities ─────────────────────────────────────────────── */}
      <section id="capabilities" className="border-t border-white/10">
        <div className="mx-auto max-w-6xl px-4 py-16">
          <p className={`font-mono text-xs uppercase tracking-[0.3em] ${CYAN}`}>· Capabilities</p>
          <h2 className="mt-3 text-3xl font-bold text-white">Six capabilities. Six subscriptions you stop paying for.</h2>
          <div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {[
              [Search, 'Accounts you can actually find', 'Every account gets a permanent ID. Search and connect by ID, username or email — privacy settings decide who is allowed to find or message you at all.'],
              [Bell, 'Work that gets followed up', "Assign tasks with priorities, checklists, subtasks and recurring schedules. Reminders are queued server-side in the account owner's timezone."],
              [FileText, 'A private record per account', 'Internal notes your team can see and the client never does. Attributed, timestamped and immutable — authors cannot quietly rewrite history.'],
              [MessageCircle, 'Talk without leaving', 'Real-time chat with attachments, voice messages, replies and reactions — plus audio and video calls over WebRTC, with full call history.'],
              [FolderOpen, 'Documents in context', 'Nested folders, per-account sharing with view or edit rights, storage quotas enforced server-side, and a trash that genuinely restores.'],
              [BarChart3, 'Know where you stand', 'Completion rates, average turnaround, a per-day created-versus-completed trend, and a CSV export when someone wants it in a spreadsheet.'],
            ].map(([Icon, title, body]) => {
              const I = Icon as typeof Search
              return (
                <div key={title as string} className="rounded-xl border border-white/10 bg-white/[0.03] p-6">
                  <I className={`size-6 ${CYAN}`} />
                  <h3 className="mt-3 text-base font-semibold text-white">{title as string}</h3>
                  <p className="mt-2 text-sm leading-6 text-slate-400">{body as string}</p>
                </div>
              )
            })}
          </div>

          <div className="mt-8 grid gap-6 sm:grid-cols-2">
            <div className="rounded-xl border border-white/10 bg-white/[0.03] p-6">
              <ShieldCheck className={`size-6 ${CYAN}`} />
              <h3 className="mt-3 text-base font-semibold text-white">Permissions enforced on the server</h3>
              <p className="mt-2 text-sm leading-6 text-slate-400">
                Roles and the per-module rights matrix are checked by middleware on every request. Hiding a
                button in the interface is not how access control works here.
              </p>
            </div>
            <div className="rounded-xl border border-white/10 bg-white/[0.03] p-6">
              <Lock className={`size-6 ${CYAN}`} />
              <h3 className="mt-3 text-base font-semibold text-white">Private by construction</h3>
              <p className="mt-2 text-sm leading-6 text-slate-400">
                Internal notes never reach the account they describe. Tasks can be confidential, notes can be
                password-locked, and every admin action lands in an audit log.
              </p>
            </div>
          </div>
        </div>
      </section>

      {/* ── CTA ──────────────────────────────────────────────────────── */}
      <section className="border-t border-white/10">
        <div className="mx-auto max-w-6xl px-4 py-20 text-center">
          <Users className={`mx-auto size-8 ${CYAN}`} />
          <h2 className="mt-4 text-3xl font-bold text-white">
            Put the whole relationship <span className={CYAN}>in one place.</span>
          </h2>
          <p className="mt-3 text-sm text-slate-400">Start on the free plan and move up when the book grows.</p>
          <div className="mt-8 flex flex-wrap justify-center gap-3">
            <Link
              to="/register"
              className="rounded-lg bg-[#6CE9FF] px-6 py-3 text-sm font-semibold text-[#070A12] transition-opacity hover:opacity-90"
            >
              Create your account
            </Link>
            <Link
              to="/login"
              className="rounded-lg border border-white/20 px-6 py-3 text-sm font-semibold text-slate-200 transition-colors hover:border-[#6CE9FF] hover:text-[#6CE9FF]"
            >
              Sign in
            </Link>
          </div>
        </div>
      </section>
    </SiteShell>
  )
}
