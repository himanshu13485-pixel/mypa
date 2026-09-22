import { useEffect } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  Archive, Clock, FileEdit, Inbox, LayoutDashboard, Mail, PenSquare, Send, Settings2, ShieldAlert, Star, Tag, Trash2, Upload, Users,
} from 'lucide-react'
import { clsx } from 'clsx'
import { crmMeQuery } from '../../../api/crm'
import { mails } from '../../../api/mails'
import { crmPath } from '../../../lib/crmPath'
import { Spinner } from '../../../components/ui'
import MailCompose, { UndoSendBar } from './MailCompose'
import { useComposer, useMailView } from './composeStore'
import { ACCENTS, FOLDER_TITLES } from './mailUtils'
import { localFoldersSupported, localSyncDue, markLocalSync, writeToFolder } from './localArchive'

const FOLDERS = [
  { key: 'inbox', icon: Inbox },
  { key: 'starred', icon: Star },
  { key: 'drafts', icon: FileEdit },
  { key: 'scheduled', icon: Clock },
  { key: 'outbox', icon: Upload },
  { key: 'sent', icon: Send },
  { key: 'archive', icon: Archive },
  { key: 'spam', icon: ShieldAlert },
  { key: 'trash', icon: Trash2 },
] as const

// Unread for inbox and spam, waiting work for the rest - never a count of
// everything ever sent, which is a number nobody acts on.
const COUNTED = new Set(['inbox', 'spam', 'drafts', 'scheduled', 'outbox'])

/**
 * The frame every Mails screen sits in: Compose, the mailbox switcher, the
 * folders with their counts and the person's labels - and the compose
 * window itself, which lives here so it survives moving between folders.
 *
 * Also the door: a company without Mails, or a person without the right,
 * sees why rather than a broken screen.
 */
export default function MailsLayout() {
  const location = useLocation()
  const { data: me, isLoading } = useQuery(crmMeQuery())
  const allowed = !!me?.mails?.allowed
  const { account, setAccount } = useMailView()
  const openComposer = useComposer((s) => s.open)

  const { data: accounts } = useQuery({ queryKey: ['mails', 'accounts'], queryFn: mails.accounts, enabled: allowed })
  const { data: settings } = useQuery({ queryKey: ['mails', 'settings'], queryFn: mails.settings, enabled: allowed })
  const { data: counts } = useQuery({
    queryKey: ['mails', 'counts', account],
    queryFn: () => mails.counts(account),
    enabled: allowed,
    refetchInterval: 60_000,
  })

  /*
   * The copy on this computer, kept up without anybody pressing anything.
   *
   * Only for mailboxes whose owner asked for it, only where the browser
   * still holds permission to that folder, and only once a day. Anything
   * missing - no permission, no folder, another browser - is simply skipped:
   * the server's own archive is unaffected either way.
   */
  const { data: backups } = useQuery({
    queryKey: ['mails', 'backups'],
    queryFn: mails.backups,
    enabled: allowed && localFoldersSupported(),
    staleTime: 10 * 60_000,
  })

  useEffect(() => {
    if (!backups) return
    const due = backups.data.filter((row) => row.destinations.local.enabled && localSyncDue(row.uuid))
    if (!due.length) return

    let cancelled = false
    void (async () => {
      for (const row of due) {
        if (cancelled) return
        try {
          const blob = await mails.exportArchive(row.uuid)
          const name = `mails-${row.email}-${new Date().toISOString().slice(0, 10)}.zip`
          if (await writeToFolder(row.uuid, name, blob)) markLocalSync(row.uuid)
        } catch {
          // A folder that has gone, or permission withdrawn: the person is
          // told when they next open the Backup tab, not with a popup here.
        }
      }
    })()

    return () => { cancelled = true }
  }, [backups])

  if (isLoading) return <div className="flex h-full items-center justify-center"><Spinner /></div>

  if (!allowed) {
    return (
      <div className="flex h-full flex-col items-center justify-center gap-3 p-6 text-center">
        <Mail className="size-10 text-slate-400" />
        <h1 className="text-lg font-semibold text-slate-800 dark:text-slate-100">Mails is not available to you</h1>
        <p className="max-w-md text-sm text-slate-500">
          {me?.mails?.org_enabled
            ? 'Your Company Admin has not given you access to Mails. Ask them to switch it on for you under Mails > Settings > Team access.'
            : 'Mails has not been switched on for your company. The platform administrator can enable it for your organization.'}
        </p>
      </div>
    )
  }

  const accent = ACCENTS[settings?.prefs.accent ?? 'brand'] ?? ACCENTS.brand
  const folderCounts = counts?.folders ?? {}
  const list = accounts?.data ?? []
  const noMailbox = accounts && list.length === 0 && !location.pathname.includes('/mails/settings')

  const railLink = (active: boolean) => clsx(
    'flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm transition-colors',
    active ? clsx(accent.soft, accent.text, 'font-semibold') : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
  )

  return (
    <div className="flex h-full min-h-0 bg-slate-50 dark:bg-slate-950">
      {/* Folders, labels, Compose - the mail client's own rail. */}
      <aside className="hidden w-56 shrink-0 flex-col border-r border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900 lg:flex">
        <button
          type="button"
          onClick={() => openComposer({ mode: 'new', account: account === 'all' ? null : account })}
          className={clsx('mb-3 flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm', accent.solid)}
        >
          <PenSquare className="size-4" /> Compose
        </button>

        {list.length > 1 && (
          <select
            value={account}
            onChange={(e) => setAccount(e.target.value)}
            className="mb-2 w-full rounded-lg bg-slate-50 px-2 py-1.5 text-xs text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700"
          >
            <option value="all">All mailboxes</option>
            {list.map((a) => <option key={a.uuid} value={a.uuid}>{a.label || a.email}</option>)}
          </select>
        )}

        <nav className="scroll-pane min-h-0 flex-1 space-y-0.5 overflow-y-auto">
          <NavLink to={crmPath('/crm/mails')} end className={({ isActive }) => railLink(isActive)}>
            <LayoutDashboard className="size-4" /> <span className="flex-1">Dashboard</span>
          </NavLink>
          {FOLDERS.map(({ key, icon: Icon }) => {
            const n = COUNTED.has(key) ? folderCounts[key] ?? 0 : 0
            return (
              <NavLink key={key} to={crmPath(`/crm/mails/${key}`)} className={({ isActive }) => railLink(isActive)}>
                <Icon className="size-4" />
                <span className="flex-1">{FOLDER_TITLES[key]}</span>
                {n > 0 && <span className="text-xs font-semibold tabular-nums">{n > 999 ? '999+' : n}</span>}
              </NavLink>
            )
          })}

          <p className="mt-4 flex items-center justify-between px-3 pb-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-400">
            Labels
            <NavLink to={crmPath('/crm/mails/labels')} className="normal-case tracking-normal text-slate-400 hover:text-slate-600" title="Manage labels">+ New</NavLink>
          </p>
          {(counts?.labels ?? []).map((l) => (
            <NavLink key={l.uuid} to={crmPath(`/crm/mails/label/${l.uuid}`)} className={({ isActive }) => railLink(isActive)}>
              <span className="size-2.5 shrink-0 rounded-full" style={{ background: l.color }} />
              <span className="flex-1 truncate">{l.name}</span>
              {l.count > 0 && <span className="text-xs tabular-nums">{l.count}</span>}
            </NavLink>
          ))}
          {(counts?.labels ?? []).length === 0 && <p className="px-3 text-xs text-slate-400">No labels yet.</p>}

          <NavLink to={crmPath('/crm/mails/labels')} className={({ isActive }) => clsx(railLink(isActive), 'mt-3')}>
            <Tag className="size-4" /> Manage labels
          </NavLink>
          <NavLink to={crmPath('/crm/mails/settings')} className={({ isActive }) => railLink(isActive)}>
            <Settings2 className="size-4" /> Settings
          </NavLink>
          {/* The Company Admin's switch: who in the company has Mails. */}
          {me?.member?.crm_role === 'admin' && (
            <NavLink to={crmPath('/crm/mails/team')} className={({ isActive }) => railLink(isActive)}>
              <Users className="size-4" /> Team access
            </NavLink>
          )}
        </nav>
      </aside>

      <div className="flex min-h-0 min-w-0 flex-1 flex-col">
        {/* Phones and tablets: the same folders as a strip. */}
        <div className="scroll-pane flex shrink-0 items-center gap-1 overflow-x-auto border-b border-slate-200 bg-white px-2 py-1.5 dark:border-slate-800 dark:bg-slate-900 lg:hidden">
          <NavLink to={crmPath('/crm/mails')} end className={({ isActive }) => clsx(railLink(isActive), 'shrink-0 px-2.5')}>
            <LayoutDashboard className="size-4" />
          </NavLink>
          {FOLDERS.map(({ key, icon: Icon }) => (
            <NavLink key={key} to={crmPath(`/crm/mails/${key}`)} className={({ isActive }) => clsx(railLink(isActive), 'shrink-0 px-2.5')}>
              <Icon className="size-4" />
              <span className="text-xs">{FOLDER_TITLES[key]}</span>
              {COUNTED.has(key) && (folderCounts[key] ?? 0) > 0 && <span className="text-[11px] font-semibold">{folderCounts[key]}</span>}
            </NavLink>
          ))}
          <NavLink to={crmPath('/crm/mails/settings')} className={({ isActive }) => clsx(railLink(isActive), 'shrink-0 px-2.5')}>
            <Settings2 className="size-4" />
          </NavLink>
        </div>

        {noMailbox ? (
          <div className="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
            <Mail className="size-10 text-slate-400" />
            <h2 className="text-lg font-semibold text-slate-800 dark:text-slate-100">Add your first mailbox</h2>
            <p className="max-w-md text-sm text-slate-500">
              Connect a mailbox - Gmail, Outlook, Zoho, Amazon SES, your company's own mail server - and its mail appears here.
              You may add up to {accounts?.limit ?? 3}.
            </p>
            <NavLink to={crmPath('/crm/mails/settings')} className={clsx('rounded-xl px-4 py-2 text-sm font-semibold text-white', accent.solid)}>
              Add a mailbox
            </NavLink>
          </div>
        ) : (
          <div className="min-h-0 flex-1">
            <Outlet />
          </div>
        )}
      </div>

      {/* Phones: Compose floats. */}
      <button
        type="button"
        aria-label="Compose"
        onClick={() => openComposer({ mode: 'new', account: account === 'all' ? null : account })}
        className={clsx('fixed bottom-5 right-5 z-30 flex size-14 items-center justify-center rounded-2xl text-white shadow-lift lg:hidden', accent.solid)}
      >
        <PenSquare className="size-5" />
      </button>

      <MailCompose />
      <UndoSendBar />
    </div>
  )
}
