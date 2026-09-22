import { useCallback, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Archive, ArrowLeft, ChevronDown, Code2, Download, Forward, ImageOff, Inbox, Paperclip, Printer,
  Reply, ReplyAll, Send, ShieldAlert, Star, Tag, Trash2, Undo2,
} from 'lucide-react'
import { clsx } from 'clsx'
import { mails, type MailFolder, type MailFull, type MailLabelTag, type MailPrefs } from '../../../api/mails'
import { errorMessage } from '../../../api/client'
import { useToast } from '../../../components/Toast'
import { Button, LoadError, Spinner } from '../../../components/ui'
import MailFrame from './MailFrame'
import { useComposer } from './composeStore'
import { fullDate, sizeLabel, who } from './mailUtils'

const initials = (s: string) => s.split(/[\s@.]+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('') || '?'

/** Download an attachment through the signed-in API, not a bare link that carries no token. */
async function download(uuid: string, id: number, filename: string, onError: (m: string) => void) {
  try {
    const blob = await mails.attachment(uuid, id)
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 10_000)
  } catch (err) {
    onError(errorMessage(err))
  }
}

/** One mail in the thread: collapsed to a line, or opened in full. */
function ThreadItem({ mail, open, onToggle, prefs, onReply }: {
  mail: MailFull
  open: boolean
  onToggle: () => void
  prefs: MailPrefs | undefined
  onReply: (mode: 'reply' | 'replyAll' | 'forward', mail: MailFull) => void
}) {
  const { toastError } = useToast()
  const [images, setImages] = useState(prefs?.load_images === 'always')
  const [blocked, setBlocked] = useState(false)
  const [plain, setPlain] = useState(false)
  const onBlocked = useCallback((b: boolean) => setBlocked(b), [])
  const sender = who({ name: mail.from_name, email: mail.from_email })
  const files = mail.attachments.filter((a) => !a.is_inline)

  return (
    <article className="border-b border-slate-100 last:border-b-0 dark:border-slate-800">
      <button type="button" onClick={onToggle} className="flex w-full items-start gap-3 px-4 py-3 text-left">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700 dark:bg-brand-500/20 dark:text-brand-200">
          {initials(sender)}
        </span>
        <span className="min-w-0 flex-1">
          <span className="flex items-baseline gap-2">
            <span className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{sender}</span>
            {open && mail.from_email && mail.from_name && <span className="truncate text-xs text-slate-400">&lt;{mail.from_email}&gt;</span>}
            <span className="ml-auto shrink-0 text-xs text-slate-400">{fullDate(mail.date)}</span>
          </span>
          {open ? (
            <span className="block truncate text-xs text-slate-500">
              to {mail.to.map((a) => who(a)).join(', ') || '(no recipients)'}
              {mail.cc.length > 0 && <> · cc {mail.cc.map((a) => who(a)).join(', ')}</>}
              {mail.bcc.length > 0 && <> · bcc {mail.bcc.map((a) => who(a)).join(', ')}</>}
            </span>
          ) : (
            <span className="block truncate text-xs text-slate-500">{mail.snippet}</span>
          )}
        </span>
      </button>

      {open && (
        <div className="px-4 pb-4 sm:pl-16">
          <div className="mb-2 flex flex-wrap items-center gap-2">
            {blocked && !images && (
              <button type="button" onClick={() => setImages(true)} className="flex items-center gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                <ImageOff className="size-3.5" /> Images are hidden to protect your privacy. Show images
              </button>
            )}
            {mail.body_html && mail.body_text && (
              <button type="button" onClick={() => setPlain((p) => !p)} className="ml-auto flex items-center gap-1 text-xs text-slate-400 hover:text-slate-600">
                <Code2 className="size-3.5" /> {plain ? 'HTML view' : 'Plain text'}
              </button>
            )}
          </div>
          <MailFrame html={plain ? null : mail.body_html} text={mail.body_text} allowImages={images} onBlockedImages={onBlocked} />

          {files.length > 0 && (
            <div className="mt-3 flex flex-wrap gap-2">
              {files.map((a) => (
                <button
                  key={a.id}
                  type="button"
                  onClick={() => download(mail.uuid, a.id, a.filename, toastError)}
                  className="flex max-w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-xs ring-1 ring-slate-200 hover:bg-slate-50 dark:ring-slate-700 dark:hover:bg-slate-800"
                >
                  <Paperclip className="size-4 shrink-0 text-slate-400" />
                  <span className="min-w-0">
                    <span className="block truncate font-medium text-slate-700 dark:text-slate-200">{a.filename}</span>
                    <span className="text-slate-400">{sizeLabel(a.size)}</span>
                  </span>
                  <Download className="size-3.5 shrink-0 text-slate-400" />
                </button>
              ))}
            </div>
          )}

          {!['drafts', 'outbox', 'scheduled'].includes(mail.folder) && (
            <div className="mt-4 flex flex-wrap gap-2">
              <Button size="sm" variant="secondary" onClick={() => onReply('reply', mail)}><Reply className="size-3.5" /> Reply</Button>
              <Button size="sm" variant="secondary" onClick={() => onReply('replyAll', mail)}><ReplyAll className="size-3.5" /> Reply all</Button>
              <Button size="sm" variant="secondary" onClick={() => onReply('forward', mail)}><Forward className="size-3.5" /> Forward</Button>
            </div>
          )}
        </div>
      )}
    </article>
  )
}

/**
 * An opened mail - or the whole conversation, oldest first, with the
 * newest open and the rest folded to a line each.
 */
export default function MailReader({ uuid, folder, labels, prefs, onClose, onGone }: {
  uuid: string
  folder: MailFolder
  labels: MailLabelTag[]
  prefs: MailPrefs | undefined
  onClose: () => void
  /** The mail left this folder (moved, deleted) - the reader should close. */
  onGone: () => void
}) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const openComposer = useComposer((s) => s.open)
  const [opened, setOpened] = useState<Record<string, boolean>>({})
  const [labelMenu, setLabelMenu] = useState(false)

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['mails', 'message', uuid],
    queryFn: async () => {
      const res = await mails.show(uuid)
      // Opening marked it read; the list and the counts should say so.
      queryClient.invalidateQueries({ queryKey: ['mails', 'list'] })
      queryClient.invalidateQueries({ queryKey: ['mails', 'counts'] })
      return res
    },
  })

  if (isLoading) return <div className="flex h-full items-center justify-center p-10"><Spinner /></div>
  if (isError || !data) return <div className="p-6"><LoadError onRetry={() => refetch()} /></div>

  const { message, thread } = data
  const latest = thread[thread.length - 1] ?? message
  const uuids = thread.map((m) => m.uuid)
  const isOpen = (m: MailFull) => opened[m.uuid] ?? (m.uuid === latest.uuid || !m.is_read || thread.length <= 2)

  const act = async (action: string, label?: string, closeAfter = false) => {
    try {
      const res = await mails.bulk(uuids, action, label)
      queryClient.invalidateQueries({ queryKey: ['mails'] })
      if (closeAfter) {
        toast(res.message, 'success')
        onGone()
      }
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  const reply = (mode: 'reply' | 'replyAll' | 'forward', mail: MailFull) => openComposer({ mode, source: mail })

  const pending = message.folder === 'outbox' || message.folder === 'scheduled'
  const allLabels = new Map<string, MailLabelTag>()
  thread.forEach((m) => m.labels.forEach((l) => allLabels.set(l.uuid, l)))
  const starred = thread.some((m) => m.is_starred)

  const tool = (label: string, icon: React.ReactNode, onClick: () => void, className?: string) => (
    <button type="button" title={label} aria-label={label} onClick={onClick} className={clsx('rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-slate-100', className)}>
      {icon}
    </button>
  )

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex items-center gap-0.5 border-b border-slate-100 px-2 py-1.5 dark:border-slate-800">
        {tool('Back', <ArrowLeft className="size-4" />, onClose)}
        <span className="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700" />
        {folder === 'trash' || folder === 'spam' ? (
          <>
            {tool(folder === 'spam' ? 'Not spam' : 'Restore', <Undo2 className="size-4" />, () => act(folder === 'spam' ? 'inbox' : 'restore', undefined, true))}
            {tool('Delete for ever', <Trash2 className="size-4" />, () => {
              if (window.confirm('Delete for ever? This removes it from the mail server as well.')) void act('delete', undefined, true)
            }, 'hover:text-red-600')}
          </>
        ) : !pending && (
          <>
            {folder !== 'archive' ? tool('Archive', <Archive className="size-4" />, () => act('archive', undefined, true)) : tool('Move to inbox', <Inbox className="size-4" />, () => act('inbox', undefined, true))}
            {tool('Report spam', <ShieldAlert className="size-4" />, () => act('spam', undefined, true))}
            {tool('Delete', <Trash2 className="size-4" />, () => act('trash', undefined, true), 'hover:text-red-600')}
          </>
        )}
        {!pending && (
          <>
            {tool(starred ? 'Unstar' : 'Star', <Star className={clsx('size-4', starred && 'fill-amber-400 text-amber-400')} />, () => act(starred ? 'unstar' : 'star'))}
            <div className="relative">
              {tool('Labels', <Tag className="size-4" />, () => setLabelMenu((v) => !v))}
              {labelMenu && (
                <div className="absolute left-0 top-full z-20 mt-1 w-56 rounded-xl bg-white p-1.5 shadow-lift ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                  {labels.length === 0 && <p className="px-2 py-1.5 text-xs text-slate-500">No labels yet - create them under Manage labels.</p>}
                  {labels.map((l) => {
                    const on = allLabels.has(l.uuid)
                    return (
                      <label key={l.uuid} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-700">
                        <input type="checkbox" checked={on} onChange={() => act(on ? 'unlabel' : 'label', l.uuid)} />
                        <span className="size-2.5 rounded-full" style={{ background: l.color }} />
                        <span className="truncate">{l.name}</span>
                      </label>
                    )
                  })}
                </div>
              )}
            </div>
            <button type="button" onClick={() => { void act('unread'); onClose() }} className="rounded-lg px-2 py-1.5 text-xs text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">
              Mark unread
            </button>
          </>
        )}
        <span className="flex-1" />
        {tool('Print', <Printer className="size-4" />, () => window.print())}
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="px-4 pb-2 pt-4">
          <h2 className="text-lg font-semibold text-slate-900 dark:text-white">{message.subject || '(no subject)'}</h2>
          <div className="mt-1 flex flex-wrap items-center gap-1.5">
            {thread.length > 1 && <span className="text-xs text-slate-400">{thread.length} messages</span>}
            {[...allLabels.values()].map((l) => (
              <span key={l.uuid} className="rounded-full px-2 py-0.5 text-[11px] font-medium text-white" style={{ background: l.color }}>{l.name}</span>
            ))}
          </div>
        </div>

        {pending && (
          <div className="mx-4 mb-3 flex flex-wrap items-center gap-2 rounded-xl bg-sky-50 p-3 text-sm text-sky-800 dark:bg-sky-500/10 dark:text-sky-200">
            <span className="flex-1">
              {message.status === 'failed'
                ? <>Could not be sent: {message.error ?? 'the mail server refused it.'}</>
                : message.folder === 'scheduled'
                  ? <>Scheduled for {fullDate(message.scheduled_for)}.</>
                  : <>Waiting to send.</>}
            </span>
            <Button size="sm" onClick={async () => {
              try {
                const res = await mails.sendNow(message.uuid)
                toast(res.message, 'success')
                queryClient.invalidateQueries({ queryKey: ['mails'] })
                onGone()
              } catch (err) { toastError(errorMessage(err)) }
            }}>
              <Send className="size-3.5" /> {message.status === 'failed' ? 'Try again' : 'Send now'}
            </Button>
            <Button size="sm" variant="secondary" onClick={async () => {
              try {
                const res = await mails.cancel(message.uuid)
                queryClient.invalidateQueries({ queryKey: ['mails'] })
                onGone()
                openComposer({ mode: 'draft', draft: res.data })
              } catch (err) { toastError(errorMessage(err)) }
            }}>
              Cancel &amp; edit
            </Button>
          </div>
        )}

        {thread.length > 3 && !opened.__all && (
          <button type="button" onClick={() => setOpened({ ...Object.fromEntries(thread.map((m) => [m.uuid, true])), __all: true })} className="mx-4 mb-2 flex items-center gap-1 text-xs text-brand-600">
            <ChevronDown className="size-3.5" /> Expand all
          </button>
        )}

        <div className="mx-2 mb-6 rounded-2xl bg-white ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800 sm:mx-4">
          {thread.map((m) => (
            <ThreadItem
              key={m.uuid}
              mail={m}
              open={isOpen(m)}
              onToggle={() => setOpened({ ...opened, [m.uuid]: !isOpen(m) })}
              prefs={prefs}
              onReply={reply}
            />
          ))}
        </div>
      </div>
    </div>
  )
}
