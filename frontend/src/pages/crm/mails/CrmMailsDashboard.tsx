import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, Clock, FileEdit, Inbox, Mail, Send } from 'lucide-react'
import { clsx } from 'clsx'
import { mails, type MailSummary } from '../../../api/mails'
import { crmPath } from '../../../lib/crmPath'
import { LoadError, SkeletonCards } from '../../../components/ui'
import { useComposer } from './composeStore'
import { fullDate, mailDate, who } from './mailUtils'

/**
 * Mails at a glance: what is unread, what went out today, what is waiting
 * to go, and whether every mailbox is still connected.
 */
export default function CrmMailsDashboard() {
  const openComposer = useComposer((s) => s.open)
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['mails', 'dashboard'],
    queryFn: mails.dashboard,
    refetchInterval: 60_000,
  })

  if (isLoading) return <div className="p-4 sm:p-6"><SkeletonCards count={4} /></div>
  if (isError || !data) return <div className="p-6"><LoadError onRetry={() => refetch()} /></div>

  const tiles = [
    { label: 'Unread', value: data.unread, icon: Inbox, to: '/crm/mails/inbox', tone: 'text-brand-600 bg-brand-50 dark:bg-brand-500/10 dark:text-brand-300' },
    { label: 'Received today', value: data.received_today, icon: Mail, to: '/crm/mails/inbox', tone: 'text-sky-600 bg-sky-50 dark:bg-sky-500/10 dark:text-sky-300' },
    { label: 'Sent today', value: data.sent_today, icon: Send, to: '/crm/mails/sent', tone: 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10 dark:text-emerald-300' },
    { label: 'Scheduled', value: data.scheduled, icon: Clock, to: '/crm/mails/scheduled', tone: 'text-violet-600 bg-violet-50 dark:bg-violet-500/10 dark:text-violet-300' },
    { label: 'Drafts', value: data.drafts, icon: FileEdit, to: '/crm/mails/drafts', tone: 'text-amber-600 bg-amber-50 dark:bg-amber-500/10 dark:text-amber-300' },
    { label: 'Failed to send', value: data.failed, icon: AlertTriangle, to: '/crm/mails/outbox', tone: 'text-red-600 bg-red-50 dark:bg-red-500/10 dark:text-red-300' },
  ]

  const row = (m: MailSummary, folder: string, when: string | null) => (
    <li key={m.uuid}>
      <Link to={crmPath(`/crm/mails/${folder}?m=${m.uuid}`)} className="flex items-baseline gap-3 rounded-lg px-2 py-2 hover:bg-slate-50 dark:hover:bg-slate-800">
        <span className="w-36 shrink-0 truncate text-sm font-medium text-slate-800 dark:text-slate-100">
          {folder === 'inbox' ? who({ name: m.from_name, email: m.from_email }) : m.to.map((a) => who(a)).join(', ')}
        </span>
        <span className="min-w-0 flex-1 truncate text-sm text-slate-600 dark:text-slate-400">{m.subject || '(no subject)'}</span>
        <span className="shrink-0 text-xs text-slate-400" title={fullDate(when)}>{mailDate(when)}</span>
      </Link>
    </li>
  )

  return (
    <div className="scroll-pane h-full overflow-y-auto p-4 sm:p-6">
      <div className="mb-5 flex flex-wrap items-center gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Mails</h1>
          <p className="text-sm text-slate-500">
            {data.accounts.length} of {data.limit} mailbox{data.limit === 1 ? '' : 'es'} connected
          </p>
        </div>
        <button type="button" onClick={() => openComposer({ mode: 'new' })} className="ml-auto rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
          New mail
        </button>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
        {tiles.map((t) => (
          <Link key={t.label} to={crmPath(t.to)} className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100 transition hover:shadow-md dark:bg-slate-900 dark:ring-slate-800">
            <span className={clsx('mb-2 inline-flex size-8 items-center justify-center rounded-lg', t.tone)}><t.icon className="size-4" /></span>
            <p className="text-2xl font-semibold tabular-nums text-slate-900 dark:text-white">{t.value}</p>
            <p className="text-xs text-slate-500">{t.label}</p>
          </Link>
        ))}
      </div>

      <div className="mt-6 grid gap-4 lg:grid-cols-3">
        <section className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800 lg:col-span-2">
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Waiting to be read</h2>
          {data.recent_unread.length === 0
            ? <p className="py-6 text-center text-sm text-slate-400">All caught up.</p>
            : <ul>{data.recent_unread.map((m) => row(m, 'inbox', m.date))}</ul>}

          {data.upcoming.length > 0 && (
            <>
              <h2 className="mb-2 mt-5 text-sm font-semibold text-slate-800 dark:text-slate-100">Scheduled to go</h2>
              <ul>{data.upcoming.map((m) => row(m, 'scheduled', m.scheduled_for))}</ul>
            </>
          )}
        </section>

        <section className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800">
          <div className="mb-2 flex items-center">
            <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Mailboxes</h2>
            <Link to={crmPath('/crm/mails/settings')} className="ml-auto text-xs font-medium text-brand-600">Manage</Link>
          </div>
          <ul className="space-y-2">
            {data.accounts.map((a) => (
              <li key={a.uuid} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                <div className="flex items-center gap-2">
                  {a.last_error
                    ? <AlertTriangle className="size-4 shrink-0 text-red-500" />
                    : <CheckCircle2 className="size-4 shrink-0 text-emerald-500" />}
                  <span className="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 dark:text-slate-100">{a.label || a.email}</span>
                  {a.unread > 0 && <span className="rounded-full bg-brand-600 px-2 text-xs font-semibold text-white">{a.unread}</span>}
                </div>
                <p className="mt-1 truncate text-xs text-slate-500">
                  {a.last_error
                    ? a.last_error
                    : !a.can_receive
                      ? 'Sending only - no incoming (IMAP) server set.'
                      : a.last_synced_at ? `Checked ${mailDate(a.last_synced_at)}` : 'Not checked yet.'}
                </p>
              </li>
            ))}
          </ul>
        </section>
      </div>
    </div>
  )
}
