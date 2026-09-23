import { useCallback, useEffect, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Archive, ArrowLeft, ChevronDown, Code2, Download, Forward, ImageOff, Inbox, Paperclip, Printer,
  Maximize2, Minimize2, Reply, ReplyAll, Send, ShieldAlert, Star, Tag, Trash2, Undo2,
} from 'lucide-react'
import { clsx } from 'clsx'
import { mails, type MailFolder, type MailFull, type MailLabelTag, type MailPrefs } from '../../../api/mails'
import { errorMessage } from '../../../api/client'
import { useToast } from '../../../components/Toast'
import { Button, LoadError, Spinner } from '../../../components/ui'
import MailFrame from './MailFrame'
import { useComposer } from './composeStore'
import { fullDate, sizeLabel, who } from './mailUtils'

/** "Kunal Chaudhari <kunal@bcg.com>, hema@bcg.com" - names kept beside addresses. */
const addressList = (people: { email: string; name?: string | null }[]): string =>
  people.map((a) => (a.name ? `${a.name} <${a.email}>` : a.email)).join(', ')

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

/** A blob as a data: URL, so a printed page carries its pictures with it. */
const asDataUrl = (blob: Blob): Promise<string> => new Promise((resolve, reject) => {
  const reader = new FileReader()
  reader.onload = () => resolve(String(reader.result))
  reader.onerror = () => reject(reader.error)
  reader.readAsDataURL(blob)
})

const escapeHtml = (text: string) =>
  text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

/**
 * Print one mail, and nothing else.
 *
 * Printing the page printed the page: the folder rail, the list of a hundred
 * other messages, and the mail somewhere inside it. So the mail is written
 * out as a document of its own - its own headings, its own pictures carried
 * in as data so nothing has to be fetched while the print dialog is open -
 * into a frame that exists for as long as the printing takes.
 */
async function printThread(messages: MailFull[], subject: string): Promise<void> {
  const parts = await Promise.all(messages.map(async (mail) => {
    let body = mail.body_html && mail.body_html.trim() !== ''
      ? mail.body_html
      : `<pre style="white-space:pre-wrap;font:inherit">${escapeHtml(mail.body_text ?? '')}</pre>`

    // Pictures sent inside the message: fetched once, and embedded, because
    // a cid: reference means nothing outside the mailbox it came from.
    for (const picture of mail.attachments.filter((a) => a.is_inline && a.content_id)) {
      try {
        const data = await asDataUrl(await mails.attachment(mail.uuid, picture.id))
        body = body.split('cid:' + (picture.content_id ?? '').replace(/^<|>$/g, '')).join(data)
      } catch {
        // A picture that will not come is not worth refusing to print over.
      }
    }

    const files = mail.attachments.filter((a) => !a.is_inline)

    return `<article>
      <table class="head"><tbody>
        <tr><th>From</th><td>${escapeHtml(who({ name: mail.from_name, email: mail.from_email }))} &lt;${escapeHtml(mail.from_email ?? '')}&gt;</td></tr>
        <tr><th>To</th><td>${escapeHtml(mail.to.map((a) => who(a)).join(', '))}</td></tr>
        ${mail.cc.length ? `<tr><th>Cc</th><td>${escapeHtml(mail.cc.map((a) => who(a)).join(', '))}</td></tr>` : ''}
        <tr><th>Date</th><td>${escapeHtml(fullDate(mail.date))}</td></tr>
      </tbody></table>
      <div class="body">${body}</div>
      ${files.length ? `<p class="files">Attachments: ${escapeHtml(files.map((a) => `${a.filename}${a.size ? ` (${sizeLabel(a.size)})` : ''}`).join(', '))}</p>` : ''}
    </article>`
  }))

  const doc = `<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(subject)}</title>
<style>
  @page { margin: 16mm }
  body { font: 12pt/1.5 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #000; margin: 0 }
  h1 { font-size: 16pt; margin: 0 0 12pt }
  article { page-break-inside: auto; margin-bottom: 18pt }
  article + article { border-top: 1pt solid #999; padding-top: 12pt }
  .head { border-collapse: collapse; margin-bottom: 10pt; font-size: 10pt }
  .head th { text-align: left; padding: 1pt 10pt 1pt 0; color: #555; font-weight: 600; vertical-align: top; white-space: nowrap }
  .head td { padding: 1pt 0 }
  .body img { max-width: 100%; height: auto }
  .body table { max-width: 100%; border-collapse: collapse }
  .body blockquote { margin: 0 0 0 4pt; padding-left: 10pt; border-left: 2pt solid #bbb; color: #444 }
  .files { font-size: 10pt; color: #555; margin-top: 8pt }
</style></head><body><h1>${escapeHtml(subject)}</h1>${parts.join('')}</body></html>`

  const frame = document.createElement('iframe')
  // Off the page rather than hidden: a display:none frame does not print.
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden'

  await new Promise<void>((resolve) => {
    // Listening before the document is given to it: srcdoc can load in the
    // same tick, and a handler attached afterwards would never hear it.
    frame.onload = () => {
      const view = frame.contentWindow
      if (!view) return resolve()

      view.focus()
      view.print()
      // Chrome returns from print() at once, Safari when the dialog closes;
      // a moment either way is enough, and the frame is invisible meanwhile.
      window.setTimeout(() => { frame.remove(); resolve() }, 1000)
    }

    document.body.appendChild(frame)
    frame.srcdoc = doc
  })
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
  const [body, setBody] = useState(mail.body_html)
  const [blocked, setBlocked] = useState(false)
  const [plain, setPlain] = useState(false)
  const onBlocked = useCallback((b: boolean) => setBlocked(b), [])
  const sender = who({ name: mail.from_name, email: mail.from_email })
  const files = mail.attachments.filter((a) => !a.is_inline)

  /*
   * Pictures sent inside the message.
   *
   * They arrive as <img src="cid:something">, which points at a part of the
   * mail rather than anywhere a browser can fetch. Each one is pulled
   * through the signed-in API and swapped for the copy in memory, so the
   * message reads as it was written - and so that the attachment list is not
   * cluttered with the pictures already shown in the body.
   */
  useEffect(() => {
    setBody(mail.body_html)
    const inline = mail.attachments.filter((a) => a.is_inline && a.content_id)
    if (!open || !inline.length || !mail.body_html?.includes('cid:')) return

    let alive = true
    const urls: string[] = []

    void (async () => {
      let html = mail.body_html ?? ''
      for (const picture of inline) {
        try {
          const blob = await mails.attachment(mail.uuid, picture.id)
          if (!alive) return
          const url = URL.createObjectURL(blob)
          urls.push(url)
          const id = (picture.content_id ?? '').replace(/^<|>$/g, '')
          html = html.split('cid:' + id).join(url)
        } catch {
          // One picture that will not come is not worth a warning: the rest
          // of the message is still perfectly readable.
        }
      }
      if (alive) setBody(html)
    })()

    return () => {
      alive = false
      urls.forEach((url) => URL.revokeObjectURL(url))
    }
  }, [mail.uuid, mail.body_html, mail.attachments, open])

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
            /*
             * Names with their addresses, the way a mail program shows them.
             *
             * "to Kunal, Hema, Rahul" hides which Kunal and which Rahul -
             * and on a forwarded thread that is exactly what somebody needs
             * to check before they reply to all.
             */
            <span className="block text-xs text-slate-500">
              <span className="block">to {addressList(mail.to) || '(no recipients)'}</span>
              {mail.cc.length > 0 && <span className="block">cc {addressList(mail.cc)}</span>}
              {mail.bcc.length > 0 && <span className="block">bcc {addressList(mail.bcc)}</span>}
            </span>
          ) : (
            <span className="block truncate text-xs text-slate-500">{mail.snippet}</span>
          )}
        </span>
      </button>

      {open && (
        <div className="px-4 pb-4 sm:pl-16">
          {/*
            * Why this message is doubted, in words.
            *
            * A warning that only says "suspicious" teaches nobody anything.
            * These are the same things a careful person would have noticed -
            * the name showing one address and the mail coming from another,
            * a link that says one place and goes to another - so the reader
            * can judge it rather than guess.
            */}
          {(mail.spam_reasons?.length ?? 0) > 0 && (
            <div className="mb-3 rounded-xl bg-amber-50 p-3 text-xs text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-100 dark:ring-amber-500/30">
              <p className="flex items-center gap-1.5 font-semibold">
                <ShieldAlert className="size-4" />
                {mail.folder === 'spam' ? 'This was put in Spam' : 'Be careful with this one'}
              </p>
              <ul className="mt-1 list-disc space-y-0.5 pl-5">
                {mail.spam_reasons?.map((why) => <li key={why}>{why}</li>)}
              </ul>
              {mail.links_held && (
                <p className="mt-1.5">
                  Its links are shown as text, not links. If you know the sender, copy the address and check it before opening it.
                </p>
              )}
            </div>
          )}
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
          <MailFrame html={plain ? null : body} text={mail.body_text} allowImages={images} onBlockedImages={onBlocked} />

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
export default function MailReader({ uuid, folder, labels, prefs, full, onToggleFull, onClose, onGone }: {
  uuid: string
  folder: MailFolder
  labels: MailLabelTag[]
  prefs: MailPrefs | undefined
  /** Reading this one mail with the list out of the way. */
  full?: boolean
  onToggleFull?: () => void
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
        {onToggleFull && tool(
          full ? 'Back to the list' : 'Open this mail wide',
          full ? <Minimize2 className="size-4" /> : <Maximize2 className="size-4" />,
          onToggleFull,
        )}
        {tool('Print this mail', <Printer className="size-4" />, () => {
          void printThread(thread.filter(isOpen), message.subject || '(no subject)')
            .catch((err) => toastError(errorMessage(err)))
        })}
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
