import { useEffect, useMemo, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, ChevronDown, Maximize2, Minus, Paperclip, Send, Sparkles, Trash2, Undo2, X } from 'lucide-react'
import { clsx } from 'clsx'
import { mails, type MailAccountInfo, type MailAttachmentInfo } from '../../../api/mails'
import { errorMessage } from '../../../api/client'
import { useToast } from '../../../components/Toast'
import { Button } from '../../../components/ui'
import AddressInput from './AddressInput'
import RichEditor from './RichEditor'
import { useComposer, type ComposeInit } from './composeStore'
import { forwardBlock, quoteForReply, replyRecipients, replySubject, sizeLabel, textToHtml } from './mailUtils'

const TONES = ['professional', 'friendly', 'formal', 'brief', 'apologetic', 'persuasive'] as const
const IMPROVE = [
  ['formal', 'More formal'],
  ['friendly', 'Friendlier'],
  ['shorter', 'Shorter'],
  ['longer', 'A little longer'],
  ['clearer', 'Clearer'],
  ['grammar', 'Fix grammar only'],
] as const

/** The signature block, marked so a change of mailbox can swap it out. */
const signatureBlock = (html: string | null | undefined) =>
  html ? `<br><div class="mail-signature">-- <br>${html}</div>` : ''

/**
 * The signature this mailbox wants here, or nothing.
 *
 * "New mail only" is the setting most people end up on: a signature under
 * every message of a twenty-message thread is twenty copies of a phone
 * number nobody reads.
 */
const signatureFor = (account: MailAccountInfo | undefined, answering: boolean) => {
  const mode = account?.signature_on ?? 'all'
  if (!account || mode === 'none' || (answering && mode === 'new')) return ''

  return signatureBlock(answering ? (account.signature_reply_html || account.signature_html) : account.signature_html)
}

/** Where the compose window starts from: blank, a reply, a forward, or a saved draft. */
function start(init: ComposeInit, accounts: MailAccountInfo[], defaultAccount: string | null) {
  const mine = accounts.map((a) => a.email)
  const sendable = accounts.filter((a) => a.can_send)
  const accountFor = (uuid?: string | null) =>
    sendable.find((a) => a.uuid === uuid) ?? sendable.find((a) => a.uuid === defaultAccount) ?? sendable.find((a) => a.is_default) ?? sendable[0]

  if (init.mode === 'draft' && init.draft) {
    const d = init.draft
    return {
      account: accountFor(d.account_uuid)?.uuid ?? '',
      to: d.to.map((a) => (a.name ? `${a.name} <${a.email}>` : a.email)),
      cc: d.cc.map((a) => (a.name ? `${a.name} <${a.email}>` : a.email)),
      bcc: d.bcc.map((a) => (a.name ? `${a.name} <${a.email}>` : a.email)),
      subject: d.subject ?? '',
      body: d.body_html ?? '',
      draft: d.uuid,
      attachments: d.attachments,
    }
  }

  const src = init.source
  const account = accountFor(init.account ?? src?.account_uuid)
  const answering = init.mode !== 'new'
  const sig = signatureFor(account, answering)
  // Above the quoted mail, or under everything - the mailbox's own choice.
  const below = account?.signature_before_quote === false

  if (src && (init.mode === 'reply' || init.mode === 'replyAll')) {
    const { to, cc } = replyRecipients(src, mine, init.mode === 'replyAll')
    const quote = quoteForReply(src)

    return { account: account?.uuid ?? '', to, cc, bcc: [], subject: replySubject(src.subject, 'Re'), body: `<p><br></p>${below ? quote + sig : sig + quote}`, draft: null, attachments: [] }
  }
  if (src && init.mode === 'forward') {
    const quote = forwardBlock(src)

    return { account: account?.uuid ?? '', to: [], cc: [], bcc: [], subject: replySubject(src.subject, 'Fwd'), body: `<p><br></p>${below ? quote + sig : sig + quote}`, draft: null, attachments: [] }
  }

  return { account: account?.uuid ?? '', to: init.to ?? [], cc: [], bcc: [], subject: '', body: `<p><br></p>${sig}`, draft: null, attachments: [] }
}

/**
 * The compose window.
 *
 * Saves itself as a draft a few seconds after each change, so closing the
 * tab loses nothing. Send puts the mail in the outbox for the person's undo
 * window, and the bar that follows can pull it back.
 */
export default function MailCompose() {
  const { current, close, setUndo } = useComposer()
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const { data: accountsData } = useQuery({ queryKey: ['mails', 'accounts'], queryFn: mails.accounts, enabled: !!current })
  const { data: settings } = useQuery({ queryKey: ['mails', 'settings'], queryFn: mails.settings, enabled: !!current })

  const [form, setForm] = useState<ReturnType<typeof start> | null>(null)
  const [files, setFiles] = useState<File[]>([])
  const [showCc, setShowCc] = useState(false)
  const [minimised, setMinimised] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  const [scheduling, setScheduling] = useState(false)
  const [when, setWhen] = useState('')
  const [ai, setAi] = useState<null | 'write' | 'improve'>(null)
  const [aiText, setAiText] = useState('')
  const [tone, setTone] = useState<(typeof TONES)[number]>('professional')
  const dirty = useRef(false)
  // The draft save in flight, so Send waits for it and reuses its draft
  // rather than leaving a second copy behind in Drafts.
  const saving = useRef<Promise<string | null> | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const accounts = useMemo(() => accountsData?.data ?? [], [accountsData])
  const sendable = accounts.filter((a) => a.can_send)

  // A fresh start each time the window is opened for something new.
  useEffect(() => {
    // Closed: the form goes too, and with it any autosave still pending -
    // a timer firing after Send would file the sent mail back into Drafts.
    if (!current) {
      setForm(null)
      dirty.current = false
      return
    }
    if (!accountsData) return
    const f = start(current, accountsData.data, settings?.prefs.default_account ?? null)
    setForm(f)
    setFiles([])
    setShowCc(f.cc.length > 0 || f.bcc.length > 0)
    setMinimised(false)
    setScheduling(false)
    setAi(null)
    dirty.current = false
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [current, accountsData])

  const set = <K extends keyof NonNullable<typeof form>>(key: K, value: NonNullable<typeof form>[K]) => {
    dirty.current = true
    setForm((f) => (f ? { ...f, [key]: value } : f))
  }

  /** Changing mailbox changes the signature with it. */
  const changeAccount = (uuid: string) => {
    if (!form) return
    const next = accounts.find((a) => a.uuid === uuid)
    const answering = current?.mode !== 'new' && current?.mode !== 'draft'
    const body = form.body.includes('class="mail-signature"')
      ? form.body.replace(/<br><div class="mail-signature">[\s\S]*?<\/div>/, signatureFor(next, answering))
      : form.body
    setForm({ ...form, account: uuid, body })
    dirty.current = true
  }

  const payload = (action: 'draft' | 'send' | 'schedule') => ({
    action,
    draft: form?.draft,
    account: form?.account ?? '',
    to: form?.to ?? [],
    cc: form?.cc ?? [],
    bcc: form?.bcc ?? [],
    subject: form?.subject ?? '',
    body_html: form?.body ?? '',
    reply_to_uuid: current && (current.mode === 'reply' || current.mode === 'replyAll') ? current.source?.uuid : undefined,
    forward_uuid: current?.mode === 'forward' ? current.source?.uuid : undefined,
    scheduled_for: action === 'schedule' && when ? new Date(when).toISOString() : undefined,
    files,
  })

  const saveDraft = async (quiet = true) => {
    if (!form?.account) return
    const run = (async () => {
      try {
        const res = await mails.compose(payload('draft'))
        dirty.current = false
        if (files.length) {
          const full = await mails.show(res.data.uuid)
          setFiles([])
          setForm((f) => (f ? { ...f, draft: res.data.uuid, attachments: full.message.attachments } : f))
        } else {
          setForm((f) => (f ? { ...f, draft: res.data.uuid } : f))
        }
        queryClient.invalidateQueries({ queryKey: ['mails', 'list'] })
        if (!quiet) toast('Draft saved.', 'success')
        return res.data.uuid
      } catch (err) {
        if (!quiet) toastError(errorMessage(err))
        return null
      }
    })()
    saving.current = run
    await run
    if (saving.current === run) saving.current = null
  }

  // A few seconds after the last change, quietly kept as a draft.
  useEffect(() => {
    if (!form) return
    const t = window.setTimeout(() => {
      if (dirty.current && files.length === 0 && (form.subject || form.to.length || form.body.replace(/<[^>]+>/g, '').trim())) {
        void saveDraft()
      }
    }, 4000)
    return () => window.clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [form])

  if (!current || !form) return null

  const send = async (action: 'send' | 'schedule') => {
    setBusy(action)
    try {
      const pendingDraft = saving.current ? await saving.current : null
      const res = await mails.compose({ ...payload(action), draft: form.draft ?? pendingDraft })
      close()
      queryClient.invalidateQueries({ queryKey: ['mails'] })
      if (action === 'send' && (res.data.undo_seconds ?? 0) > 0) {
        setUndo({ uuid: res.data.uuid, until: Date.now() + (res.data.undo_seconds ?? 0) * 1000 })
      } else {
        toast(res.message, 'success')
      }
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setBusy(null)
    }
  }

  const discard = async () => {
    if (form.draft) await mails.bulk([form.draft], 'delete').catch(() => undefined)
    queryClient.invalidateQueries({ queryKey: ['mails'] })
    close()
  }

  const runAi = async (mode: 'compose' | 'reply' | 'improve', action?: string) => {
    setBusy('ai')
    try {
      const plain = form.body.replace(/<div class="mail-quote">[\s\S]*$/, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()
      const result = await mails.write({
        mode,
        instruction: aiText,
        tone,
        to: form.to.join(', '),
        text: mode === 'improve' ? plain : undefined,
        action,
        message_uuid: mode === 'reply' ? current.source?.uuid : undefined,
      })
      // The draft goes in above the signature and the quoted mail, which stay as they were.
      const tail = form.body.match(/<br><div class="mail-signature">[\s\S]*$/)?.[0]
        ?? form.body.match(/<br><br><div class="mail-quote">[\s\S]*$/)?.[0] ?? ''
      setForm({ ...form, body: textToHtml(result.body) + tail, subject: form.subject || result.subject || '' })
      dirty.current = true
      setAi(null)
      setAiText('')
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setBusy(null)
    }
  }

  const title = current.mode === 'forward' ? 'Forward' : current.mode.startsWith('reply') ? 'Reply' : form.draft ? 'Draft' : 'New message'

  if (minimised) {
    return (
      <button
        type="button"
        onClick={() => setMinimised(false)}
        className="fixed bottom-0 right-4 z-40 flex w-72 items-center justify-between rounded-t-xl bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-lift"
      >
        <span className="truncate">{form.subject || title}</span>
        <Maximize2 className="size-4" />
      </button>
    )
  }

  return (
    <div className="fixed inset-0 z-40 flex flex-col bg-white shadow-lift dark:bg-slate-900 sm:inset-auto sm:bottom-0 sm:right-4 sm:h-[min(640px,90vh)] sm:w-[min(640px,calc(100vw-2rem))] sm:rounded-t-2xl sm:ring-1 sm:ring-slate-200 dark:sm:ring-slate-700">
      <div className="flex items-center justify-between rounded-t-2xl bg-slate-900 px-4 py-2.5 text-white">
        <span className="truncate text-sm font-medium">{title}</span>
        <div className="flex items-center gap-1">
          <button type="button" aria-label="Minimise" onClick={() => setMinimised(true)} className="hidden rounded p-1 hover:bg-white/10 sm:block"><Minus className="size-4" /></button>
          <button type="button" aria-label="Close" onClick={() => { if (dirty.current) void saveDraft(); close() }} className="rounded p-1 hover:bg-white/10"><X className="size-4" /></button>
        </div>
      </div>

      <div className="flex min-h-0 flex-1 flex-col overflow-y-auto px-3">
        {sendable.length === 0 ? (
          <p className="m-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
            None of your mailboxes can send yet. Add its outgoing (SMTP) server under Mails &gt; Settings &gt; Mailboxes.
          </p>
        ) : (
          <label className="flex items-center gap-1.5 border-b border-slate-100 px-1 py-1.5 dark:border-slate-800">
            <span className="w-10 shrink-0 text-xs text-slate-400">From</span>
            <select
              value={form.account}
              onChange={(e) => changeAccount(e.target.value)}
              className="min-w-0 flex-1 bg-transparent py-1 text-sm text-slate-800 outline-none dark:text-slate-100"
            >
              {sendable.map((a) => (
                <option key={a.uuid} value={a.uuid}>{a.from_name ? `${a.from_name} <${a.email}>` : a.email}</option>
              ))}
            </select>
          </label>
        )}
        <div className="relative">
          <AddressInput label="To" value={form.to} onChange={(v) => set('to', v)} autoFocus={current.mode === 'new' || current.mode === 'forward'} />
          {!showCc && (
            <button
              type="button"
              // The mouse press is swallowed, so the address half typed into
              // To keeps both the focus and the text - opening Cc must never
              // cost somebody what they were in the middle of writing.
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => setShowCc(true)}
              className="absolute right-1 top-2.5 text-xs text-slate-400 hover:text-slate-600"
            >
              Cc / Bcc
            </button>
          )}
        </div>
        {showCc && (
          <>
            <div className="relative">
              <AddressInput label="Cc" value={form.cc} onChange={(v) => set('cc', v)} />
              {/* Opened by mistake, and empty: it can go away again. */}
              {form.cc.length === 0 && form.bcc.length === 0 && (
                <button
                  type="button"
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => setShowCc(false)}
                  className="absolute right-1 top-2.5 text-xs text-slate-400 hover:text-slate-600"
                >
                  Hide
                </button>
              )}
            </div>
            <AddressInput label="Bcc" value={form.bcc} onChange={(v) => set('bcc', v)} />
          </>
        )}
        <input
          value={form.subject}
          onChange={(e) => set('subject', e.target.value)}
          placeholder="Subject"
          className="border-b border-slate-100 bg-transparent px-1 py-2.5 text-sm font-medium text-slate-800 outline-none dark:border-slate-800 dark:text-slate-100"
        />

        {/* Writing help: drafts into the box, never sends. */}
        {ai && (
          <div className="mt-2 rounded-xl bg-violet-50 p-3 dark:bg-violet-500/10">
            {ai === 'write' ? (
              <>
                <textarea
                  value={aiText}
                  onChange={(e) => setAiText(e.target.value)}
                  rows={2}
                  autoFocus
                  placeholder={current.mode.startsWith('reply') ? 'What should the reply say? (e.g. accept the meeting, ask for Thursday instead)' : 'What is the mail about? (e.g. follow up on our proposal sent last week)'}
                  className="w-full resize-none rounded-lg bg-white p-2 text-sm outline-none ring-1 ring-violet-200 dark:bg-slate-900 dark:ring-violet-500/30"
                />
                <div className="mt-2 flex flex-wrap items-center gap-2">
                  <select value={tone} onChange={(e) => setTone(e.target.value as typeof tone)} className="rounded-lg bg-white px-2 py-1 text-xs ring-1 ring-violet-200 dark:bg-slate-900">
                    {TONES.map((t) => <option key={t} value={t}>{t[0].toUpperCase() + t.slice(1)}</option>)}
                  </select>
                  <Button size="sm" disabled={busy === 'ai' || (!aiText.trim() && !current.mode.startsWith('reply'))} onClick={() => runAi(current.mode.startsWith('reply') ? 'reply' : 'compose')}>
                    <Sparkles className="size-3.5" /> {busy === 'ai' ? 'Writing…' : 'Write it'}
                  </Button>
                  <button type="button" onClick={() => setAi(null)} className="text-xs text-slate-500">Cancel</button>
                </div>
              </>
            ) : (
              <div className="flex flex-wrap gap-1.5">
                {IMPROVE.map(([key, label]) => (
                  <button
                    key={key}
                    type="button"
                    disabled={busy === 'ai'}
                    onClick={() => runAi('improve', key)}
                    className="rounded-full bg-white px-3 py-1 text-xs font-medium text-violet-700 ring-1 ring-violet-200 hover:bg-violet-100 disabled:opacity-50 dark:bg-slate-900 dark:text-violet-300"
                  >
                    {label}
                  </button>
                ))}
                <button type="button" onClick={() => setAi(null)} className="text-xs text-slate-500">Cancel</button>
              </div>
            )}
          </div>
        )}

        <div className="mt-2 flex-1">
          <RichEditor value={form.body} onChange={(v) => set('body', v)} placeholder="Write your message…" minHeight={180} autoFocus={current.mode.startsWith('reply')} />
        </div>

        {(form.attachments.length > 0 || files.length > 0) && (
          <div className="mt-2 flex flex-wrap gap-1.5 pb-2">
            {form.attachments.map((a: MailAttachmentInfo) => (
              <span key={a.id} className="flex items-center gap-1 rounded-lg bg-slate-100 px-2 py-1 text-xs dark:bg-slate-800">
                <Paperclip className="size-3" /> {a.filename} <span className="text-slate-400">{sizeLabel(a.size)}</span>
              </span>
            ))}
            {files.map((f) => (
              <span key={f.name + f.size} className="flex items-center gap-1 rounded-lg bg-slate-100 px-2 py-1 text-xs dark:bg-slate-800">
                <Paperclip className="size-3" /> {f.name} <span className="text-slate-400">{sizeLabel(f.size)}</span>
                <button type="button" aria-label={`Remove ${f.name}`} onClick={() => setFiles(files.filter((x) => x !== f))}><X className="size-3" /></button>
              </span>
            ))}
          </div>
        )}
      </div>

      {scheduling && (
        <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 px-3 py-2 dark:border-slate-800">
          <CalendarClock className="size-4 text-slate-400" />
          <input
            type="datetime-local"
            value={when}
            onChange={(e) => setWhen(e.target.value)}
            min={new Date(Date.now() + 2 * 60_000).toISOString().slice(0, 16)}
            className="rounded-lg bg-white px-2 py-1 text-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700"
          />
          <Button size="sm" disabled={!when || busy !== null} onClick={() => send('schedule')}>{busy === 'schedule' ? 'Scheduling…' : 'Schedule send'}</Button>
          <button type="button" onClick={() => setScheduling(false)} className="text-xs text-slate-500">Cancel</button>
        </div>
      )}

      <div className="flex items-center gap-1 border-t border-slate-100 px-3 py-2.5 dark:border-slate-800">
        <div className="flex">
          <Button disabled={busy !== null || sendable.length === 0} onClick={() => send('send')} className="rounded-r-none">
            <Send className="size-4" /> {busy === 'send' ? 'Sending…' : 'Send'}
          </Button>
          <Button
            disabled={busy !== null || sendable.length === 0}
            onClick={() => setScheduling((s) => !s)}
            className="rounded-l-none border-l border-white/20 px-2"
            title="Schedule send"
            aria-label="Schedule send"
          >
            <ChevronDown className="size-4" />
          </Button>
        </div>
        <button type="button" title="Attach files" aria-label="Attach files" onClick={() => fileInput.current?.click()} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">
          <Paperclip className="size-4" />
        </button>
        <input
          ref={fileInput}
          type="file"
          multiple
          hidden
          onChange={(e) => {
            const picked = Array.from(e.target.files ?? [])
            const tooBig = picked.find((f) => f.size > 25 * 1024 * 1024)
            if (tooBig) toastError(`${tooBig.name} is over 25 MB - most mail servers refuse attachments that large.`)
            setFiles([...files, ...picked.filter((f) => f.size <= 25 * 1024 * 1024)])
            dirty.current = true
            e.target.value = ''
          }}
        />
        {settings?.ai_available && (
          <>
            <button type="button" title="Write with AI" onClick={() => setAi(ai === 'write' ? null : 'write')} className={clsx('flex items-center gap-1 rounded-lg px-2 py-2 text-xs font-medium text-violet-600 hover:bg-violet-50 dark:text-violet-300 dark:hover:bg-violet-500/10', ai === 'write' && 'bg-violet-50 dark:bg-violet-500/10')}>
              <Sparkles className="size-4" /> <span className="hidden sm:inline">Write with AI</span>
            </button>
            <button type="button" title="Improve what is written" onClick={() => setAi(ai === 'improve' ? null : 'improve')} className={clsx('rounded-lg px-2 py-2 text-xs font-medium text-violet-600 hover:bg-violet-50 dark:text-violet-300 dark:hover:bg-violet-500/10', ai === 'improve' && 'bg-violet-50 dark:bg-violet-500/10')}>
              Improve
            </button>
          </>
        )}
        <span className="flex-1" />
        <button type="button" title="Save draft" onClick={() => saveDraft(false)} className="rounded-lg px-2 py-2 text-xs text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">Save</button>
        <button type="button" title="Discard" aria-label="Discard" onClick={discard} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-red-600 dark:hover:bg-slate-800">
          <Trash2 className="size-4" />
        </button>
      </div>
    </div>
  )
}

/**
 * "Sending… Undo" - the few seconds a sent mail can still be pulled back.
 *
 * Counts down the person's undo window; Undo returns the mail to a draft
 * and reopens it, exactly as it was.
 */
export function UndoSendBar() {
  const { undo, setUndo, open } = useComposer()
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [now, setNow] = useState(Date.now())

  useEffect(() => {
    if (!undo) return
    const t = window.setInterval(() => setNow(Date.now()), 250)
    return () => window.clearInterval(t)
  }, [undo])

  useEffect(() => {
    if (undo && now >= undo.until) {
      setUndo(null)
      queryClient.invalidateQueries({ queryKey: ['mails'] })
      toast('Sent.', 'success')
    }
  }, [now, undo, setUndo, queryClient, toast])

  if (!undo) return null
  const left = Math.max(0, Math.ceil((undo.until - now) / 1000))

  return (
    <div className="fixed bottom-4 left-1/2 z-50 flex -translate-x-1/2 items-center gap-4 rounded-xl bg-slate-900 px-4 py-3 text-sm text-white shadow-lift">
      <span>Sending… {left}s</span>
      <button
        type="button"
        onClick={async () => {
          const pending = undo
          setUndo(null)
          try {
            const res = await mails.cancel(pending.uuid)
            queryClient.invalidateQueries({ queryKey: ['mails'] })
            open({ mode: 'draft', draft: res.data })
          } catch (err) {
            toastError(errorMessage(err))
          }
        }}
        className="flex items-center gap-1 font-semibold text-brand-300 hover:text-brand-200"
      >
        <Undo2 className="size-4" /> Undo
      </button>
    </div>
  )
}
