import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle, Archive, Clock, Inbox, MailOpen, Mail as MailIcon, Paperclip, RefreshCw, Search, ShieldAlert, Star, Tag, Trash2, Undo2, X,
} from 'lucide-react'
import { clsx } from 'clsx'
import { mails, type MailFolder, type MailSummary } from '../../../api/mails'
import { errorMessage } from '../../../api/client'
import { useToast } from '../../../components/Toast'
import { LoadError, SkeletonList } from '../../../components/ui'
import MailReader from './MailReader'
import { useComposer, useMailView } from './composeStore'
import { ACCENTS, FOLDER_TITLES, fullDate, mailDate, who } from './mailUtils'

const EMPTY: Record<string, string> = {
  inbox: 'Your inbox is empty. New mail appears here as it arrives.',
  starred: 'Star a mail to keep it here.',
  drafts: 'No drafts. Anything you start writing is kept here automatically.',
  scheduled: 'Nothing scheduled. Use the arrow beside Send to pick a time.',
  outbox: 'Nothing waiting to go out.',
  sent: 'Nothing sent yet.',
  archive: 'Archived mail stays here, out of the inbox but never lost.',
  spam: 'No spam. Nicely done.',
  trash: 'Trash is empty.',
}

/**
 * A folder of mail - or a label - with the reader beside it, below it, or
 * in its place, as the person chose. The open mail rides in the address
 * (?m=) so Back closes it and a link opens it.
 */
export default function CrmMailsPage() {
  const params = useParams<{ folder?: string; label?: string }>()
  const [search, setSearch] = useSearchParams()
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const openComposer = useComposer((s) => s.open)
  const account = useMailView((s) => s.account)

  const labelUuid = params.label
  const folder = (labelUuid ? 'inbox' : (params.folder ?? 'inbox')) as MailFolder
  const openUuid = search.get('m')
  /** A mail opened on its own, the list out of the way - double-click. */
  const fullView = search.get('full') === '1'

  const [q, setQ] = useState('')
  const [typed, setTyped] = useState('')
  const [unreadOnly, setUnreadOnly] = useState(false)
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [syncing, setSyncing] = useState(false)
  const [labelMenu, setLabelMenu] = useState(false)
  const searchBox = useRef<HTMLInputElement>(null)

  const { data: settings } = useQuery({ queryKey: ['mails', 'settings'], queryFn: mails.settings })
  const { data: labels = [] } = useQuery({ queryKey: ['mails', 'labels'], queryFn: mails.labels })
  const { data: accounts } = useQuery({ queryKey: ['mails', 'accounts'], queryFn: mails.accounts })
  const prefs = settings?.prefs
  const accent = ACCENTS[prefs?.accent ?? 'brand'] ?? ACCENTS.brand
  const compact = prefs?.density === 'compact'
  const pane = prefs?.reading_pane ?? 'right'
  const wide = useWide()

  // A new folder is a new list: nothing ticked, no search carried over.
  useEffect(() => {
    setSelected(new Set())
    setQ('')
    setTyped('')
    setUnreadOnly(false)
  }, [folder, labelUuid, account])

  const list = useInfiniteQuery({
    queryKey: ['mails', 'list', { folder, labelUuid, q, unreadOnly, account }],
    queryFn: ({ pageParam }) => mails.list({
      folder: labelUuid ? undefined : folder,
      label: labelUuid,
      q: q || undefined,
      unread: unreadOnly,
      page: pageParam,
      account,
    }),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.current_page < last.last_page ? last.current_page + 1 : undefined),
    placeholderData: keepPreviousData,
    /*
     * A minute is right for a mailbox at rest, and far too slow for one
     * with mail on its way out. A send settles about ten seconds after the
     * undo window closes, so at a flat minute a mail that had already gone
     * still sat in the Outbox on screen - which reads as stuck. While
     * anything here is queued or sending, ask every three seconds.
     */
    refetchInterval: (query) => {
      const waiting = query.state.data?.pages.some((p) => p.data.some(
        (m) => m.status === 'queued' || m.status === 'sending',
      ))

      return waiting ? 3_000 : 60_000
    },
  })

  const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data])
  const threaded = list.data?.pages[0]?.threaded ?? false
  const total = list.data?.pages[0]?.total ?? 0
  const title = labelUuid ? (labels.find((l) => l.uuid === labelUuid)?.name ?? 'Label') : FOLDER_TITLES[folder] ?? 'Mail'

  const openMail = async (row: MailSummary) => {
    // A draft opens where it can be finished, not where it can be read.
    if (row.folder === 'drafts') {
      try {
        const full = await mails.show(row.uuid)
        openComposer({ mode: 'draft', draft: full.message })
      } catch (err) {
        toastError(errorMessage(err))
      }
      return
    }
    const next = new URLSearchParams(search)
    next.set('m', row.uuid)
    setSearch(next)
  }

  const closeMail = () => {
    const next = new URLSearchParams(search)
    next.delete('m')
    next.delete('full')
    setSearch(next)
  }

  /**
   * The whole window for one mail.
   *
   * A long thread with three attachments read badly in a third of the
   * screen, and the reading-pane setting is a preference rather than
   * something to change twice a day - so a double-click opens this one mail
   * wide, and closing it puts the list back exactly as it was.
   */
  const openFull = async (row: MailSummary) => {
    if (row.folder === 'drafts') return openMail(row)
    const next = new URLSearchParams(search)
    next.set('m', row.uuid)
    next.set('full', '1')
    setSearch(next)
  }

  const bulk = async (action: string, label?: string) => {
    if (!selected.size) return
    try {
      const res = await mails.bulk([...selected], action, label, threaded)
      toast(res.message, 'success')
      setSelected(new Set())
      setLabelMenu(false)
      if (openUuid && selected.has(openUuid) && ['trash', 'spam', 'archive', 'inbox', 'restore', 'delete'].includes(action)) closeMail()
      queryClient.invalidateQueries({ queryKey: ['mails'] })
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  const quick = async (row: MailSummary, patch: { is_starred?: boolean; is_read?: boolean }) => {
    try {
      const action = patch.is_starred !== undefined ? (patch.is_starred ? 'star' : 'unstar') : (patch.is_read ? 'read' : 'unread')
      await mails.bulk([row.uuid], action, undefined, threaded)
      queryClient.invalidateQueries({ queryKey: ['mails', 'list'] })
      queryClient.invalidateQueries({ queryKey: ['mails', 'counts'] })
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  const refresh = async () => {
    setSyncing(true)
    const targets = (accounts?.data ?? []).filter((a) => a.can_receive && (account === 'all' || a.uuid === account))
    const results = await Promise.allSettled(targets.map((a) => mails.syncAccount(a.uuid, true)))
    const failed = results.filter((r) => r.status === 'rejected') as PromiseRejectedResult[]
    if (failed.length) toastError(errorMessage(failed[0].reason))
    await queryClient.invalidateQueries({ queryKey: ['mails'] })
    setSyncing(false)
  }

  const emptyFolder = async () => {
    if (folder !== 'trash' && folder !== 'spam') return
    if (!window.confirm(`Delete everything in ${FOLDER_TITLES[folder]} for ever? It is removed from the mail server too.`)) return
    try {
      const res = await mails.empty(folder)
      toast(res.message, 'success')
      closeMail()
      queryClient.invalidateQueries({ queryKey: ['mails'] })
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  // The keys mail people already know: c to write, / to search, Esc to close.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const t = e.target as HTMLElement
      if (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName) || e.metaKey || e.ctrlKey || e.altKey) return
      if (e.key === 'c') { e.preventDefault(); openComposer({ mode: 'new', account: account === 'all' ? null : account }) }
      else if (e.key === '/') { e.preventDefault(); searchBox.current?.focus() }
      else if (e.key === 'Escape' && openUuid) closeMail()
      else if ((e.key === 'j' || e.key === 'k') && rows.length) {
        const i = rows.findIndex((r) => r.uuid === openUuid)
        const next = rows[Math.min(rows.length - 1, Math.max(0, i + (e.key === 'j' ? 1 : -1)))]
        if (next && next.folder !== 'drafts') void openMail(next)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [openUuid, rows, account])

  // Waiting far longer than the undo window: something is not collecting it.
  const stalled = rows.filter((r) => (r.status === 'queued' || r.status === 'sending')
    && r.send_after && Date.now() - new Date(r.send_after).getTime() > 5 * 60_000).length

  const allTicked = rows.length > 0 && rows.every((r) => selected.has(r.uuid))
  const inTrash = folder === 'trash' || folder === 'spam'
  const readerOnly = !!openUuid && pane === 'off'

  const btn = 'rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-slate-100 disabled:opacity-40'

  const listPane = (
    <div className="flex h-full min-h-0 flex-col bg-white dark:bg-slate-900">
      <div className="flex items-center gap-2 border-b border-slate-100 px-3 py-2 dark:border-slate-800">
        <h1 className="shrink-0 text-base font-semibold text-slate-900 dark:text-white">{title}</h1>
        <span className="text-xs text-slate-400">{total > 0 && total}</span>
        <form
          className="ml-auto flex min-w-0 max-w-xs flex-1 items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1.5 dark:bg-slate-800"
          onSubmit={(e) => { e.preventDefault(); setQ(typed.trim()) }}
        >
          <Search className="size-3.5 shrink-0 text-slate-400" />
          <input
            ref={searchBox}
            value={typed}
            onChange={(e) => { setTyped(e.target.value); if (!e.target.value) setQ('') }}
            placeholder="Search mail"
            className="min-w-0 flex-1 bg-transparent text-sm outline-none"
          />
          {typed && <button type="button" aria-label="Clear search" onClick={() => { setTyped(''); setQ('') }}><X className="size-3.5 text-slate-400" /></button>}
        </form>
      </div>

      <div className="flex items-center gap-0.5 border-b border-slate-100 px-2 py-1 dark:border-slate-800">
        <input
          type="checkbox"
          aria-label="Select all"
          checked={allTicked}
          onChange={() => setSelected(allTicked ? new Set() : new Set(rows.map((r) => r.uuid)))}
          className="mx-2"
        />
        {selected.size > 0 ? (
          <>
            <span className="mr-1 text-xs text-slate-500">{selected.size} selected</span>
            {inTrash ? (
              <>
                <button type="button" title={folder === 'spam' ? 'Not spam' : 'Restore'} className={btn} onClick={() => bulk(folder === 'spam' ? 'inbox' : 'restore')}><Undo2 className="size-4" /></button>
                <button type="button" title="Delete for ever" className={clsx(btn, 'hover:text-red-600')} onClick={() => { if (window.confirm('Delete these for ever?')) void bulk('delete') }}><Trash2 className="size-4" /></button>
              </>
            ) : (
              <>
                {folder === 'archive'
                  ? <button type="button" title="Move to inbox" className={btn} onClick={() => bulk('inbox')}><Inbox className="size-4" /></button>
                  : <button type="button" title="Archive" className={btn} onClick={() => bulk('archive')}><Archive className="size-4" /></button>}
                <button type="button" title="Report spam" className={btn} onClick={() => bulk('spam')}><ShieldAlert className="size-4" /></button>
                <button type="button" title={folder === 'drafts' ? 'Discard drafts' : 'Delete'} className={clsx(btn, 'hover:text-red-600')} onClick={() => bulk(folder === 'drafts' ? 'delete' : 'trash')}><Trash2 className="size-4" /></button>
              </>
            )}
            <button type="button" title="Mark read" className={btn} onClick={() => bulk('read')}><MailOpen className="size-4" /></button>
            <button type="button" title="Mark unread" className={btn} onClick={() => bulk('unread')}><MailIcon className="size-4" /></button>
            <button type="button" title="Star" className={btn} onClick={() => bulk('star')}><Star className="size-4" /></button>
            <div className="relative">
              <button type="button" title="Label" className={btn} onClick={() => setLabelMenu((v) => !v)}><Tag className="size-4" /></button>
              {labelMenu && (
                <div className="absolute left-0 top-full z-20 mt-1 w-52 rounded-xl bg-white p-1.5 shadow-lift ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                  {labels.length === 0 && <p className="px-2 py-1.5 text-xs text-slate-500">No labels yet.</p>}
                  {labels.map((l) => (
                    <div key={l.uuid} className="flex items-center gap-2 rounded-lg px-2 py-1 text-sm">
                      <span className="size-2.5 rounded-full" style={{ background: l.color }} />
                      <span className="flex-1 truncate">{l.name}</span>
                      <button type="button" className="text-xs text-brand-600" onClick={() => bulk('label', l.uuid)}>Add</button>
                      <button type="button" className="text-xs text-slate-400" onClick={() => bulk('unlabel', l.uuid)}>Remove</button>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </>
        ) : (
          <>
            <button type="button" title="Check for new mail" className={btn} onClick={refresh} disabled={syncing}>
              <RefreshCw className={clsx('size-4', syncing && 'animate-spin')} />
            </button>
            <button
              type="button"
              onClick={() => setUnreadOnly((u) => !u)}
              className={clsx('rounded-lg px-2 py-1 text-xs', unreadOnly ? clsx(accent.soft, accent.text, 'font-semibold') : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800')}
            >
              Unread only
            </button>
            {folder === 'trash' && (
              <span className="ml-auto mr-2 text-xs text-slate-400">
                Emptied after {settings?.trash_days ?? 30} days
              </span>
            )}
            {inTrash && total > 0 && (
              <button type="button" onClick={emptyFolder} className="rounded-lg px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10">
                Empty {FOLDER_TITLES[folder]} now
              </button>
            )}
          </>
        )}
      </div>

      {/*
        * An outbox that is not emptying.
        *
        * Mail goes out through a background worker, and when that worker is
        * not running there is nothing on screen to say so - the message just
        * sits there looking sent. If anything is more than five minutes past
        * its time, say what is probably wrong.
        */}
      {folder === 'outbox' && stalled > 0 && (
        <div className="flex items-start gap-2 border-b border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
          <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
          <span>
            {stalled} message{stalled === 1 ? '' : 's'} {stalled === 1 ? 'has' : 'have'} been waiting more than five minutes.
            Mail is sent by a background worker - if this is your own server, check that the queue worker and the scheduler are
            running, then open a message and press <b>Send now</b>.
          </span>
        </div>
      )}

      <div className="scroll-pane min-h-0 flex-1 overflow-y-auto">
        {list.isLoading ? (
          <div className="p-3"><SkeletonList rows={8} /></div>
        ) : list.isError ? (
          <div className="p-6"><LoadError onRetry={() => list.refetch()} /></div>
        ) : rows.length === 0 ? (
          <p className="p-10 text-center text-sm text-slate-400">{q ? `Nothing matches "${q}".` : EMPTY[labelUuid ? '' : folder] ?? 'Nothing here.'}</p>
        ) : (
          <ul>
            {rows.map((row) => {
              const unread = threaded ? (row.thread_unread ?? 0) > 0 : !row.is_read
              const outgoing = ['sent', 'drafts', 'outbox', 'scheduled'].includes(row.folder)
              const name = outgoing
                ? `To: ${row.to.map((a) => who(a)).join(', ') || '(no recipients)'}`
                : who({ name: row.from_name, email: row.from_email }) || '(unknown sender)'
              const active = row.uuid === openUuid
              return (
                <li
                  key={row.uuid}
                  draggable
                  onDragStart={(e) => {
                    /* Whatever is ticked travels together; a row nobody
                       ticked travels alone. */
                    const moving = selected.has(row.uuid) ? [...selected] : [row.uuid]
                    e.dataTransfer.setData('application/x-netvork-mail', JSON.stringify({ uuids: moving, threaded }))
                    e.dataTransfer.effectAllowed = 'move'
                  }}
                  onClick={() => openMail(row)}
                  onDoubleClick={() => openFull(row)}
                  className={clsx(
                    'group flex cursor-pointer items-start gap-2 border-b border-slate-100 px-2 dark:border-slate-800',
                    compact ? 'py-1.5' : 'py-2.5',
                    active ? accent.soft : unread ? 'bg-white dark:bg-slate-900' : 'bg-slate-50/60 dark:bg-slate-900/60',
                    'hover:bg-slate-100 dark:hover:bg-slate-800',
                  )}
                >
                  <input
                    type="checkbox"
                    aria-label="Select"
                    checked={selected.has(row.uuid)}
                    onClick={(e) => e.stopPropagation()}
                    onChange={() => {
                      const next = new Set(selected)
                      if (next.has(row.uuid)) next.delete(row.uuid)
                      else next.add(row.uuid)
                      setSelected(next)
                    }}
                    className="mx-1.5 mt-1"
                  />
                  <button
                    type="button"
                    aria-label={row.is_starred ? 'Unstar' : 'Star'}
                    onClick={(e) => { e.stopPropagation(); void quick(row, { is_starred: !row.is_starred }) }}
                    className="mt-0.5"
                  >
                    <Star className={clsx('size-4', row.is_starred ? 'fill-amber-400 text-amber-400' : 'text-slate-300 hover:text-slate-500')} />
                  </button>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-baseline gap-2">
                      <span className={clsx('truncate text-sm', unread ? 'font-bold text-slate-900 dark:text-white' : 'text-slate-700 dark:text-slate-300')}>
                        {name}
                      </span>
                      {threaded && (row.thread_count ?? 1) > 1 && <span className="shrink-0 text-xs text-slate-400">{row.thread_count}</span>}
                      <span className={clsx('ml-auto shrink-0 text-xs', unread ? clsx('font-semibold', accent.text) : 'text-slate-400')} title={fullDate(row.date)}>
                        {row.folder === 'scheduled' ? <span className="flex items-center gap-1"><Clock className="size-3" />{mailDate(row.scheduled_for)}</span> : mailDate(row.date)}
                      </span>
                    </div>
                    <div className="flex items-center gap-1.5">
                      {row.status === 'failed' && <AlertTriangle className="size-3.5 shrink-0 text-red-500" />}
                      {/* Doubted, wherever it is sitting. */}
                      {(row.spam_score ?? 0) >= 30 && (
                        <ShieldAlert className="size-3.5 shrink-0 text-amber-500" aria-label="Looks suspicious" />
                      )}
                      {row.labels.map((l) => (
                        <span key={l.uuid} className="shrink-0 rounded px-1.5 text-[10px] font-medium text-white" style={{ background: l.color }}>{l.name}</span>
                      ))}
                      <span className={clsx('truncate text-sm', unread ? 'font-semibold text-slate-800 dark:text-slate-100' : 'text-slate-600 dark:text-slate-400')}>
                        {row.subject || '(no subject)'}
                      </span>
                      {row.has_attachments && <Paperclip className="size-3.5 shrink-0 text-slate-400" />}
                    </div>
                    {/*
                      * Why it did not go, even in the compact list.
                      *
                      * Compact used to drop this line altogether, which left
                      * a failed mail looking exactly like one still on its
                      * way - a small red triangle and nothing else - so the
                      * reason the mail server gave was invisible to the one
                      * person who could act on it.
                      */}
                    {row.status === 'failed'
                      ? <p className="truncate text-xs text-red-500">{row.error || 'The mail server refused it.'}</p>
                      : !compact && <p className="truncate text-xs text-slate-400">{row.snippet}</p>}
                  </div>
                  <button
                    type="button"
                    title={unread ? 'Mark read' : 'Mark unread'}
                    onClick={(e) => { e.stopPropagation(); void quick(row, { is_read: unread }) }}
                    className="mt-0.5 hidden text-slate-400 hover:text-slate-700 group-hover:block"
                  >
                    {unread ? <MailOpen className="size-4" /> : <MailIcon className="size-4" />}
                  </button>
                </li>
              )
            })}
          </ul>
        )}
        {list.hasNextPage && (
          <button type="button" onClick={() => list.fetchNextPage()} disabled={list.isFetchingNextPage} className="w-full py-3 text-sm font-medium text-brand-600 hover:bg-slate-50 dark:hover:bg-slate-800">
            {list.isFetchingNextPage ? 'Loading…' : 'Load more'}
          </button>
        )}
      </div>
    </div>
  )

  const reader = openUuid ? (
    <MailReader
      key={openUuid}
      uuid={openUuid}
      folder={folder}
      labels={labels}
      prefs={prefs}
      full={fullView}
      onToggleFull={() => {
        const next = new URLSearchParams(search)
        if (fullView) next.delete('full')
        else next.set('full', '1')
        setSearch(next)
      }}
      onClose={closeMail}
      onGone={closeMail}
    />
  ) : (
    <div className="flex h-full flex-col items-center justify-center gap-2 text-sm text-slate-400">
      <MailIcon className="size-10 text-slate-300" />
      Pick a mail to read it.
    </div>
  )

  if (fullView && openUuid) {
    return <div className="h-full min-h-0 bg-white dark:bg-slate-900">{reader}</div>
  }

  if (!wide || readerOnly) {
    // Phones always show one pane at a time.
    return <div className="h-full min-h-0">{openUuid ? <div className="h-full bg-white dark:bg-slate-900">{reader}</div> : listPane}</div>
  }
  if (pane === 'off') return <div className="h-full min-h-0">{listPane}</div>
  if (pane === 'bottom') {
    return (
      <div className="flex h-full min-h-0 flex-col">
        <div className="h-[45%] min-h-0 border-b border-slate-200 dark:border-slate-800">{listPane}</div>
        <div className="min-h-0 flex-1 bg-white dark:bg-slate-900">{reader}</div>
      </div>
    )
  }
  return (
    <div className="flex h-full min-h-0">
      <div className="w-[min(420px,42%)] shrink-0 border-r border-slate-200 dark:border-slate-800">{listPane}</div>
      <div className="min-w-0 flex-1 bg-white dark:bg-slate-900">{reader}</div>
    </div>
  )
}

/** Whether the screen is wide enough for the list and the reader side by side. */
function useWide(): boolean {
  const query = '(min-width: 768px)'
  const [wide, setWide] = useState(() => typeof window !== 'undefined' && window.matchMedia(query).matches)
  useEffect(() => {
    const m = window.matchMedia(query)
    const on = () => setWide(m.matches)
    m.addEventListener('change', on)
    return () => m.removeEventListener('change', on)
  }, [])
  return wide
}
