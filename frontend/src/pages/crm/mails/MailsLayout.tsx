import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Archive, Clock, Contact, FileEdit, Inbox, LayoutDashboard, Mail, MoreHorizontal, PanelLeftClose, PanelLeftOpen, PenSquare, Send, Settings2, ShieldAlert, Star, Tag, Trash2, Upload, Users,
} from 'lucide-react'
import { clsx } from 'clsx'
import { crmMeQuery } from '../../../api/crm'
import { mails } from '../../../api/mails'
import { crmPath } from '../../../lib/crmPath'
import { errorMessage } from '../../../api/client'
import { useToast } from '../../../components/Toast'
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

/** The three a phone shows outright; the rest live behind the button beside them. */
const PHONE_FOLDERS = FOLDERS.filter(({ key }) => ['inbox', 'sent', 'spam'].includes(key))

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
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  /*
   * The rail folds away.
   *
   * A mail with a wide table in it, or a thread being read side by side
   * with its list, wants the 224px this takes - and somebody who folds it
   * away means it, so the choice is remembered on this machine.
   */
  /** The phone's folder menu, for everything the strip has no room for. */
  const [moreOpen, setMoreOpen] = useState(false)
  const [railOpen, setRailOpen] = useState<boolean>(() => {
    try {
      return window.localStorage.getItem('mails.rail') !== 'closed'
    } catch {
      return true
    }
  })

  const toggleRail = () => setRailOpen((open: boolean) => {
    try {
      window.localStorage.setItem('mails.rail', open ? 'closed' : 'open')
    } catch { /* a private window simply forgets */ }

    return !open
  })

  /**
   * Mail dropped on a folder goes there.
   *
   * The same move the toolbar makes, reached the way people expect to reach
   * it - and the whole selection travels when the row being dragged is part
   * of one.
   */
  const dropOn = async (event: React.DragEvent, folder: string) => {
    event.preventDefault()
    const raw = event.dataTransfer.getData('application/x-netvork-mail')
    if (!raw) return

    try {
      const { uuids, threaded } = JSON.parse(raw) as { uuids: string[]; threaded?: boolean }
      if (!uuids?.length) return

      const action = folder === 'trash' ? 'trash' : folder === 'spam' ? 'spam' : folder === 'archive' ? 'archive' : 'inbox'
      const res = await mails.bulk(uuids, action, undefined, !!threaded)
      toast(res.message, 'success')
      queryClient.invalidateQueries({ queryKey: ['mails'] })
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  /** Only the folders a mail can be dropped into mean anything. */
  const takesDrops = (folder: string) => ['inbox', 'archive', 'spam', 'trash'].includes(folder)

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
      <aside className={clsx(
        'hidden shrink-0 flex-col border-r border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900 lg:flex',
        railOpen ? 'w-56' : 'w-0 overflow-hidden border-r-0 p-0',
      )}>
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
              <NavLink
                key={key}
                to={crmPath(`/crm/mails/${key}`)}
                className={({ isActive }) => railLink(isActive)}
                onDragOver={(e) => { if (takesDrops(key)) e.preventDefault() }}
                onDrop={(e) => { if (takesDrops(key)) void dropOn(e, key) }}
              >
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
          <NavLink to={crmPath('/crm/mails/addresses')} className={({ isActive }) => railLink(isActive)}>
            <Contact className="size-4" /> Addresses
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
        {/* The rail folds away for a wider read; the same button brings it back. */}
        <div className="hidden items-center gap-2 border-b border-slate-200 bg-white px-2 py-1 dark:border-slate-800 dark:bg-slate-900 lg:flex">
          <button
            type="button"
            onClick={toggleRail}
            title={railOpen ? 'Hide the folder list' : 'Show the folder list'}
            aria-label={railOpen ? 'Hide the folder list' : 'Show the folder list'}
            className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-slate-100"
          >
            {railOpen ? <PanelLeftClose className="size-4" /> : <PanelLeftOpen className="size-4" />}
          </button>
          {!railOpen && (
            <button
              type="button"
              onClick={() => openComposer({ mode: 'new', account: account === 'all' ? null : account })}
              className={clsx('rounded-lg px-3 py-1 text-xs font-semibold text-white', accent.solid)}
            >
              Compose
            </button>
          )}
        </div>

        {/* Phones and tablets: the same folders as a strip. */}
        {/*
          * On a phone: the three folders people actually live in, and
          * everything else behind one button.
          *
          * A strip of ten that scrolls sideways hides half of itself at any
          * moment and gives no clue which half - so Outbox was reachable
          * only by somebody who thought to swipe a row of icons.
          */}
        <div className="relative flex shrink-0 items-center gap-1 border-b border-slate-200 bg-white px-2 py-1.5 dark:border-slate-800 dark:bg-slate-900 lg:hidden">
          {PHONE_FOLDERS.map(({ key, icon: Icon }) => (
            <NavLink
              key={key}
              to={crmPath(`/crm/mails/${key}`)}
              className={({ isActive }) => clsx(railLink(isActive), 'min-w-0 flex-1 justify-center px-1.5')}
            >
              <Icon className="size-4 shrink-0" />
              <span className="truncate text-xs">{FOLDER_TITLES[key]}</span>
              {COUNTED.has(key) && (folderCounts[key] ?? 0) > 0 && (
                <span className="text-[11px] font-semibold">{folderCounts[key]}</span>
              )}
            </NavLink>
          ))}

          <button
            type="button"
            onClick={() => setMoreOpen((open) => !open)}
            aria-label="More mail folders"
            aria-expanded={moreOpen}
            className={clsx('shrink-0 rounded-lg p-2', moreOpen ? clsx(accent.soft, accent.text) : 'text-slate-500')}
          >
            <MoreHorizontal className="size-4" />
          </button>

          {moreOpen && (
            <>
              {/* A tap anywhere else puts it away, as a menu should. */}
              <button type="button" aria-label="Close" className="fixed inset-0 z-20 cursor-default" onClick={() => setMoreOpen(false)} />
              <div className="absolute right-2 top-full z-30 mt-1 w-56 rounded-xl bg-white p-1.5 shadow-lift ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                <NavLink to={crmPath('/crm/mails')} end className={({ isActive }) => railLink(isActive)} onClick={() => setMoreOpen(false)}>
                  <LayoutDashboard className="size-4" /> <span className="flex-1">Dashboard</span>
                </NavLink>
                {FOLDERS.filter(({ key }) => !PHONE_FOLDERS.some((f) => f.key === key)).map(({ key, icon: Icon }) => (
                  <NavLink
                    key={key}
                    to={crmPath(`/crm/mails/${key}`)}
                    className={({ isActive }) => railLink(isActive)}
                    onClick={() => setMoreOpen(false)}
                  >
                    <Icon className="size-4" />
                    <span className="flex-1">{FOLDER_TITLES[key]}</span>
                    {COUNTED.has(key) && (folderCounts[key] ?? 0) > 0 && (
                      <span className="text-xs font-semibold tabular-nums">{folderCounts[key]}</span>
                    )}
                  </NavLink>
                ))}
                <NavLink to={crmPath('/crm/mails/labels')} className={({ isActive }) => railLink(isActive)} onClick={() => setMoreOpen(false)}>
                  <Tag className="size-4" /> <span className="flex-1">Manage labels</span>
                </NavLink>
                <NavLink to={crmPath('/crm/mails/addresses')} className={({ isActive }) => railLink(isActive)} onClick={() => setMoreOpen(false)}>
                  <Contact className="size-4" /> <span className="flex-1">Addresses</span>
                </NavLink>
                <NavLink to={crmPath('/crm/mails/settings')} className={({ isActive }) => railLink(isActive)} onClick={() => setMoreOpen(false)}>
                  <Settings2 className="size-4" /> <span className="flex-1">Mail settings</span>
                </NavLink>
                {me?.member?.crm_role === 'admin' && (
                  <NavLink to={crmPath('/crm/mails/team')} className={({ isActive }) => railLink(isActive)} onClick={() => setMoreOpen(false)}>
                    <Users className="size-4" /> <span className="flex-1">Team access</span>
                  </NavLink>
                )}
              </div>
            </>
          )}
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
