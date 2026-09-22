import { useEffect, useRef, useState } from 'react'
import { useLocation, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ImagePlus, Plus, ShieldCheck, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { mails, type MailAccountInfo, type MailPrefs, type MailTeamMailbox, type MailTeamRow } from '../../../api/mails'
import { errorMessage } from '../../../api/client'
import { useToast } from '../../../components/Toast'
import { Button, Input, Label, LoadError, Select, Spinner, Textarea } from '../../../components/ui'
import BackupTab from './BackupTab'
import MailboxesTab from './MailboxesTab'
import RichEditor from './RichEditor'
import { ACCENTS } from './mailUtils'

type Tab = 'mailboxes' | 'preferences' | 'signatures' | 'autoreply' | 'backup' | 'labels' | 'ai' | 'team'

const LABEL_COLORS = ['#2563eb', '#16a34a', '#dc2626', '#d97706', '#7c3aed', '#db2777', '#0891b2', '#475569']

const card = 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800 sm:p-5'

/**
 * Everything about how Mails works for this person - and, for the Company
 * Admin, for the company: who has Mails, how many mailboxes each may add,
 * and which AI writes the drafts.
 */
export default function CrmMailSettingsPage() {
  const [search, setSearch] = useSearchParams()
  // Manage labels and Team access are their own addresses, each landing on
  // its own tab - they are what the sidebar points at.
  const path = useLocation().pathname
  const initialTab: Tab = path.endsWith('/mails/labels') ? 'labels' : path.endsWith('/mails/team') ? 'team' : 'mailboxes'
  const { data: settings, isLoading, isError, refetch } = useQuery({ queryKey: ['mails', 'settings'], queryFn: mails.settings })
  const asked = (search.get('tab') as Tab | null) ?? initialTab

  if (isLoading) return <div className="flex h-full items-center justify-center"><Spinner /></div>
  if (isError || !settings) return <div className="p-6"><LoadError onRetry={() => refetch()} /></div>

  // Someone without the company hat asking for an Admin tab gets the one
  // everybody has, not an empty page.
  const tab: Tab = !settings.is_admin && (asked === 'team' || asked === 'ai') ? 'mailboxes' : asked

  const tabs: [Tab, string][] = [
    ['mailboxes', 'Mailboxes'],
    ['preferences', 'Preferences & theme'],
    ['signatures', 'Signatures'],
    ['autoreply', 'Auto-reply & forwarding'],
    ['backup', 'Backup & archive'],
    ['labels', 'Labels'],
    ...(settings.is_admin ? ([['ai', 'AI assistant'], ['team', 'Team access']] as [Tab, string][]) : []),
  ]

  return (
    <div className="scroll-pane h-full overflow-y-auto p-4 sm:p-6">
      <h1 className="mb-4 text-xl font-semibold text-slate-900 dark:text-white">Mail settings</h1>
      {/* Wrapped, not scrolled: a tab nobody can see is a feature nobody
          can find - Team access sat off the right-hand edge. */}
      <div className="mb-5 flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800">
        {tabs.map(([key, label]) => (
          <button
            key={key}
            type="button"
            onClick={() => setSearch({ tab: key })}
            className={clsx(
              '-mb-px shrink-0 border-b-2 px-3 py-2 text-sm',
              tab === key ? 'border-brand-600 font-semibold text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200',
            )}
          >
            {label}
          </button>
        ))}
      </div>

      <div className="max-w-4xl">
        {tab === 'mailboxes' && <MailboxesTab />}
        {tab === 'preferences' && <PreferencesTab prefs={settings.prefs} />}
        {tab === 'signatures' && <SignaturesTab />}
        {tab === 'autoreply' && <AutoReplyTab />}
        {tab === 'backup' && <BackupTab />}
        {tab === 'labels' && <LabelsTab />}
        {tab === 'ai' && settings.is_admin && <AiTab />}
        {tab === 'team' && settings.is_admin && <TeamTab />}
      </div>
    </div>
  )
}

/* ------------------------------------------------------------ Preferences */

function PreferencesTab({ prefs }: { prefs: MailPrefs }) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const { data: accounts } = useQuery({ queryKey: ['mails', 'accounts'], queryFn: mails.accounts })
  const [form, setForm] = useState(prefs)
  useEffect(() => setForm(prefs), [prefs])

  const save = useMutation({
    mutationFn: () => mails.savePrefs(form),
    onSuccess: () => {
      toast('Preferences saved.', 'success')
      queryClient.invalidateQueries({ queryKey: ['mails'] })
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const choice = <K extends keyof MailPrefs>(key: K, options: [MailPrefs[K], string][]) => (
    <div className="flex flex-wrap gap-1.5">
      {options.map(([value, label]) => (
        <button
          key={String(value)}
          type="button"
          onClick={() => setForm({ ...form, [key]: value })}
          className={clsx('rounded-lg px-3 py-1.5 text-sm ring-1', form[key] === value ? 'bg-brand-600 text-white ring-brand-600' : 'text-slate-600 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800')}
        >
          {label}
        </button>
      ))}
    </div>
  )

  return (
    <div className={clsx(card, 'space-y-5')}>
      <div>
        <Label>Undo send - how long a sent mail can be pulled back</Label>
        {choice('undo_seconds', [[0, 'Off'], [5, '5 s'], [10, '10 s'], [20, '20 s'], [30, '30 s']])}
      </div>
      <div>
        <Label>Reading</Label>
        {choice('conversation', [[true, 'Conversation view'], [false, 'Each mail on its own']])}
      </div>
      <div>
        <Label>Reading pane</Label>
        {choice('reading_pane', [['right', 'Right of the list'], ['bottom', 'Below the list'], ['off', 'Full screen']])}
      </div>
      <div>
        <Label>Density</Label>
        {choice('density', [['comfortable', 'Comfortable'], ['compact', 'Compact']])}
      </div>
      <div>
        <Label>Theme colour</Label>
        <div className="flex flex-wrap gap-2">
          {Object.entries(ACCENTS).map(([key, a]) => (
            <button
              key={key}
              type="button"
              title={key}
              aria-label={key}
              onClick={() => setForm({ ...form, accent: key as MailPrefs['accent'] })}
              className={clsx('size-8 rounded-full ring-offset-2 dark:ring-offset-slate-900', a.solid, form.accent === key && 'ring-2 ring-slate-900 dark:ring-white')}
            />
          ))}
        </div>
      </div>
      <div>
        <Label>Remote images in mail</Label>
        {choice('load_images', [['ask', 'Ask first (safer)'], ['always', 'Always show']])}
      </div>
      <div className="max-w-sm">
        <Label>Write from</Label>
        <Select value={form.default_account ?? ''} onChange={(e) => setForm({ ...form, default_account: e.target.value || null })}>
          <option value="">The default mailbox</option>
          {(accounts?.data ?? []).map((a) => <option key={a.uuid} value={a.uuid}>{a.email}</option>)}
        </Select>
      </div>
      <Button onClick={() => save.mutate()} disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save preferences'}</Button>
    </div>
  )
}

/* ------------------------------------------------------------ Signatures */

function SignaturesTab() {
  const { data } = useQuery({ queryKey: ['mails', 'accounts'], queryFn: mails.accounts })
  if (!data) return <Spinner />
  if (!data.data.length) return <p className="text-sm text-slate-500">Add a mailbox first.</p>
  return <div className="space-y-4">{data.data.map((a) => <SignatureCard key={a.uuid} account={a} />)}</div>
}

function SignatureCard({ account }: { account: MailAccountInfo }) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [html, setHtml] = useState(account.signature_html ?? '')
  const [replyHtml, setReplyHtml] = useState(account.signature_reply_html ?? '')
  const [separate, setSeparate] = useState(!!account.signature_reply_html)
  const [on, setOn] = useState<'new' | 'all' | 'none'>(account.signature_on ?? 'all')
  const [beforeQuote, setBeforeQuote] = useState(account.signature_before_quote !== false)
  const [uploading, setUploading] = useState(false)
  const picture = useRef<HTMLInputElement>(null)

  const save = useMutation({
    mutationFn: () => mails.saveAccount(account.uuid, {
      signature_html: html,
      signature_reply_html: separate ? replyHtml : null,
      signature_on: on,
      signature_before_quote: beforeQuote,
    }),
    onSuccess: () => { toast('Signature saved.', 'success'); queryClient.invalidateQueries({ queryKey: ['mails', 'accounts'] }) },
    onError: (err) => toastError(errorMessage(err)),
  })

  /*
   * A logo goes in as a picture at an address, not as embedded data: mail
   * programs refuse data: images, so an embedded one arrives as a blank
   * square in every inbox that receives it.
   */
  const addPicture = async (file: File, into: 'main' | 'reply') => {
    setUploading(true)
    try {
      const { url } = await mails.signatureImage(account.uuid, file)
      const tag = '<p><img src="' + url + '" alt="" style="max-width:220px;height:auto"></p>'
      if (into === 'main') setHtml((h) => h + tag)
      else setReplyHtml((h) => h + tag)
      toast('Picture added to the signature. Save to keep it.', 'success')
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setUploading(false)
    }
  }

  const choice = (value: typeof on, label: string, hint: string) => (
    <label key={value} className="flex items-start gap-2 text-sm">
      <input type="radio" className="mt-1" checked={on === value} onChange={() => setOn(value)} />
      <span>
        {label}
        <span className="block text-xs text-slate-400">{hint}</span>
      </span>
    </label>
  )

  return (
    <div className={clsx(card, 'space-y-3')}>
      <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">{account.email}</p>

      <RichEditor value={html} onChange={setHtml} minHeight={110} placeholder="Name, title, phone - added under mail from this mailbox" />

      <div className="flex flex-wrap items-center gap-2">
        <Button size="sm" variant="secondary" disabled={uploading} onClick={() => picture.current?.click()}>
          <ImagePlus className="size-3.5" /> {uploading ? 'Adding…' : 'Add logo or picture'}
        </Button>
        <input
          ref={picture}
          type="file"
          accept="image/png,image/jpeg,image/gif,image/webp"
          hidden
          onChange={(e) => {
            const file = e.target.files?.[0]
            e.target.value = ''
            if (file) void addPicture(file, 'main')
          }}
        />
        <span className="text-xs text-slate-400">
          PNG or JPG up to 1 MB. The &lt;/&gt; button in the editor opens the HTML itself.
        </span>
      </div>

      <div className="space-y-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
        <p className="text-sm font-medium text-slate-700 dark:text-slate-200">Where it goes</p>
        {choice('all', 'On everything I send', 'New mail, replies and forwards.')}
        {choice('new', 'On new mail only', 'Replies and forwards go without it - what most people prefer on a long thread.')}
        {choice('none', 'Nowhere', 'Kept here, but never added on its own.')}

        {on !== 'none' && (
          <>
            <label className="flex items-start gap-2 pt-1 text-sm">
              <input type="checkbox" className="mt-1" checked={beforeQuote} onChange={(e) => setBeforeQuote(e.target.checked)} />
              <span>
                Put it above the quoted mail in a reply
                <span className="block text-xs text-slate-400">Off puts it at the very bottom, under everything that was quoted.</span>
              </span>
            </label>

            {on === 'all' && (
              <label className="flex items-start gap-2 text-sm">
                <input type="checkbox" className="mt-1" checked={separate} onChange={(e) => setSeparate(e.target.checked)} />
                <span>
                  Use a shorter signature on replies
                  <span className="block text-xs text-slate-400">A name and a number is usually enough once a conversation is going.</span>
                </span>
              </label>
            )}
          </>
        )}
      </div>

      {separate && on === 'all' && (
        <div>
          <Label>Signature on replies and forwards</Label>
          <RichEditor value={replyHtml} onChange={setReplyHtml} minHeight={80} placeholder="e.g. Priya - 98xxxxxx21" />
        </div>
      )}

      <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending}>Save signature</Button>
    </div>
  )
}

/* ------------------------------------------------------------ Auto-reply */

function AutoReplyTab() {
  const { data } = useQuery({ queryKey: ['mails', 'accounts'], queryFn: mails.accounts })
  if (!data) return <Spinner />
  if (!data.data.length) return <p className="text-sm text-slate-500">Add a mailbox first.</p>
  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-500">
        Auto-replies and forwarding run as new mail is fetched (every few minutes). Each sender gets one auto-reply every four days,
        and mailing lists and robots are never answered.
      </p>
      {data.data.map((a) => <AutoReplyCard key={a.uuid} account={a} />)}
    </div>
  )
}

function AutoReplyCard({ account }: { account: MailAccountInfo }) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [reply, setReply] = useState({
    enabled: !!account.auto_reply?.enabled,
    subject: account.auto_reply?.subject ?? '',
    body: account.auto_reply?.body ?? '',
    from: account.auto_reply?.from ?? '',
    until: account.auto_reply?.until ?? '',
  })
  const save = useMutation({
    mutationFn: () => mails.saveAccount(account.uuid, {
      auto_reply: { ...reply, from: reply.from || null, until: reply.until || null },
    }),
    onSuccess: () => { toast('Saved.', 'success'); queryClient.invalidateQueries({ queryKey: ['mails', 'accounts'] }) },
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <div className={clsx(card, 'space-y-3')}>
      <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">{account.email}</p>
      {!account.can_receive && <p className="text-xs text-amber-700">This mailbox has no incoming server, so nothing arrives to answer or forward.</p>}
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={reply.enabled} onChange={(e) => setReply({ ...reply, enabled: e.target.checked })} />
        Send an automatic reply (out of office)
      </label>
      {reply.enabled && (
        <div className="grid gap-3 sm:grid-cols-2">
          <div><Label>From (optional)</Label><Input type="date" value={reply.from} onChange={(e) => setReply({ ...reply, from: e.target.value })} /></div>
          <div><Label>Until (optional)</Label><Input type="date" value={reply.until} onChange={(e) => setReply({ ...reply, until: e.target.value })} /></div>
          <div className="sm:col-span-2"><Label>Subject</Label><Input value={reply.subject} onChange={(e) => setReply({ ...reply, subject: e.target.value })} placeholder="Leave blank for Re: their subject" /></div>
          <div className="sm:col-span-2"><Label>Message</Label><Textarea rows={4} value={reply.body} onChange={(e) => setReply({ ...reply, body: e.target.value })} placeholder="Thanks for your mail. I am away until Monday and will reply then." /></div>
        </div>
      )}
      <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending}>Save auto-reply</Button>

      <Forwarding account={account} />
    </div>
  )
}

/**
 * Forwarding, to addresses that have said yes.
 *
 * Adding one sends it a six-figure code, and nothing is forwarded there
 * until somebody types the code back - a mailbox quietly copying a
 * company's mail to a typo is not a mistake anybody notices on their own.
 */
function Forwarding({ account }: { account: MailAccountInfo }) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [address, setAddress] = useState('')
  const [codeFor, setCodeFor] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['mails', 'accounts'] })

  const run = async (fn: () => Promise<{ message: string }>) => {
    setBusy(true)
    try {
      const res = await fn()
      toast(res.message, 'success')
      refresh()
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  // The single address from before codes existed still forwards, and is
  // shown here so it can be seen and removed like any other.
  const legacy = account.forward_to && !account.forwards.some((f) => f.address === account.forward_to)
    ? [{ address: account.forward_to, verified: true, sent_at: null }]
    : []

  return (
    <div className="space-y-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
      <p className="text-sm font-medium text-slate-700 dark:text-slate-200">Forward a copy of new mail to</p>

      {[...legacy, ...account.forwards].map((f) => (
        <div key={f.address} className="flex flex-wrap items-center gap-2 text-sm">
          <span className="font-medium text-slate-800 dark:text-slate-100">{f.address}</span>
          {f.verified ? (
            <span className="flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
              <ShieldCheck className="size-3" /> Verified
            </span>
          ) : (
            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">
              Waiting for its code
            </span>
          )}
          {!f.verified && (
            <>
              <button type="button" className="text-xs text-brand-600" onClick={() => { setCodeFor(f.address); setCode('') }}>
                Enter code
              </button>
              <button type="button" className="text-xs text-slate-500" disabled={busy} onClick={() => run(() => mails.addForward(account.uuid, f.address))}>
                Send again
              </button>
            </>
          )}
          <button
            type="button"
            className="text-xs text-red-600"
            disabled={busy}
            onClick={() => { if (window.confirm('Stop forwarding to ' + f.address + '?')) void run(() => mails.removeForward(account.uuid, f.address)) }}
          >
            Remove
          </button>

          {codeFor === f.address && (
            <span className="flex w-full items-center gap-2">
              <Input
                value={code}
                onChange={(e) => setCode(e.target.value.replace(/[^0-9]/g, '').slice(0, 6))}
                placeholder="6-figure code"
                inputMode="numeric"
                className="w-40"
              />
              <Button size="sm" disabled={busy || code.length < 6} onClick={() => run(async () => {
                const res = await mails.verifyForward(account.uuid, f.address, code)
                setCodeFor(null)

                return res
              })}>
                Verify
              </Button>
            </span>
          )}
        </div>
      ))}

      {account.forwards.length === 0 && legacy.length === 0 && (
        <p className="text-xs text-slate-400">Nothing is forwarded from this mailbox.</p>
      )}

      <form
        className="flex flex-wrap items-end gap-2 pt-1"
        onSubmit={(e) => {
          e.preventDefault()
          if (!address.trim()) return
          void run(async () => {
            const res = await mails.addForward(account.uuid, address.trim())
            setCodeFor(address.trim().toLowerCase())
            setAddress('')

            return res
          })
        }}
      >
        <Input type="email" value={address} onChange={(e) => setAddress(e.target.value)} placeholder="someone@example.com" className="max-w-xs flex-1" />
        <Button size="sm" type="submit" disabled={busy}>Send code</Button>
      </form>
      <p className="text-xs text-slate-400">
        Up to five addresses. Each is sent a code from this mailbox, and starts receiving only once the code is typed back.
      </p>
    </div>
  )
}

/* ------------------------------------------------------------ Labels */

function LabelsTab() {
  const queryClient = useQueryClient()
  const { toastError } = useToast()
  const { data: labels = [] } = useQuery({ queryKey: ['mails', 'labels'], queryFn: mails.labels })
  const [name, setName] = useState('')
  const [color, setColor] = useState(LABEL_COLORS[0])

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['mails'] })
  const guard = (p: Promise<unknown>) => p.then(refresh).catch((err) => toastError(errorMessage(err)))

  return (
    <div className={clsx(card, 'space-y-4')}>
      <form
        className="flex flex-wrap items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault()
          if (!name.trim()) return
          void guard(mails.addLabel(name.trim(), color).then(() => setName('')))
        }}
      >
        <div className="min-w-[12rem] flex-1"><Label>New label</Label><Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Clients, Urgent, Invoices" maxLength={60} /></div>
        <div className="flex gap-1 pb-2">
          {LABEL_COLORS.map((c) => (
            <button key={c} type="button" aria-label={c} onClick={() => setColor(c)} className={clsx('size-6 rounded-full', color === c && 'ring-2 ring-slate-900 ring-offset-2 dark:ring-white dark:ring-offset-slate-900')} style={{ background: c }} />
          ))}
        </div>
        <Button type="submit"><Plus className="size-4" /> Create label</Button>
      </form>

      {labels.length === 0 && <p className="text-sm text-slate-400">No labels yet.</p>}
      <ul className="divide-y divide-slate-100 dark:divide-slate-800">
        {labels.map((l) => (
          <li key={l.uuid} className="flex items-center gap-3 py-2">
            <input
              type="color"
              value={l.color}
              onChange={(e) => void guard(mails.saveLabel(l.uuid, l.name, e.target.value))}
              className="size-7 cursor-pointer rounded border-0 bg-transparent"
              aria-label="Colour"
            />
            <input
              defaultValue={l.name}
              onBlur={(e) => { if (e.target.value.trim() && e.target.value !== l.name) void guard(mails.saveLabel(l.uuid, e.target.value.trim(), l.color)) }}
              className="min-w-0 flex-1 rounded-lg bg-transparent px-2 py-1 text-sm outline-none focus:ring-1 focus:ring-slate-300"
            />
            <button
              type="button"
              aria-label={`Delete ${l.name}`}
              onClick={() => { if (window.confirm(`Delete the label "${l.name}"? Mail keeps its place - only the label goes.`)) void guard(mails.removeLabel(l.uuid)) }}
              className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10"
            >
              <Trash2 className="size-4" />
            </button>
          </li>
        ))}
      </ul>
    </div>
  )
}

/* ------------------------------------------------------------ AI (admin) */

function AiTab() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const { data } = useQuery({ queryKey: ['mails', 'ai'], queryFn: mails.ai })
  const [form, setForm] = useState({ enabled: false, provider: 'anthropic', model: '', api_key: '' })
  useEffect(() => {
    if (data) setForm({ enabled: data.enabled, provider: data.provider, model: data.model, api_key: '' })
  }, [data])

  const save = useMutation({
    mutationFn: () => mails.saveAi({ ...form, api_key: form.api_key || undefined }),
    onSuccess: (res) => { toast(res.message, 'success'); queryClient.invalidateQueries({ queryKey: ['mails'] }) },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (!data) return <Spinner />

  return (
    <div className={clsx(card, 'space-y-4')}>
      <p className="text-sm text-slate-500">
        Lets people draft, reply and polish mail with AI from the compose window. Nothing is ever sent without the person pressing Send.
        {data.available && !data.enabled && ' The platform AI is available to your company without a key of your own.'}
      </p>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={form.enabled} onChange={(e) => setForm({ ...form, enabled: e.target.checked })} />
        Use our own AI key
      </label>
      {form.enabled && (
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Provider</Label>
            <Select value={form.provider} onChange={(e) => setForm({ ...form, provider: e.target.value })}>
              <option value="anthropic">Claude (Anthropic)</option>
              <option value="openai">ChatGPT (OpenAI)</option>
            </Select>
          </div>
          <div>
            <Label>Model</Label>
            <Input
              value={form.model}
              onChange={(e) => setForm({ ...form, model: e.target.value })}
              placeholder={form.provider === 'anthropic' ? `Blank = ${data.default_claude_model}` : 'Exactly as OpenAI names it'}
            />
          </div>
          <div className="sm:col-span-2">
            <Label>API key {data.has_key && <span className="font-normal text-slate-400">(saved - leave blank to keep)</span>}</Label>
            <Input type="password" autoComplete="new-password" value={form.api_key} onChange={(e) => setForm({ ...form, api_key: e.target.value })} />
          </div>
        </div>
      )}
      <Button onClick={() => save.mutate()} disabled={save.isPending}>Save</Button>
    </div>
  )
}

/* ------------------------------------------------------------ Team (admin) */

function TeamTab() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const { data } = useQuery({ queryKey: ['mails', 'team'], queryFn: mails.team })
  const [filter, setFilter] = useState('')

  if (!data) return <Spinner />
  const rows = data.data.filter((r) => !filter || `${r.name} ${r.email}`.toLowerCase().includes(filter.toLowerCase()))

  const save = async (uuid: string, enabled: boolean, limit?: number) => {
    try {
      const res = await mails.saveTeam(uuid, enabled, limit)
      toast(res.message, 'success')
      queryClient.invalidateQueries({ queryKey: ['mails', 'team'] })
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  return (
    <div className="space-y-4">
    <div className={clsx(card, 'space-y-3')}>
      <p className="text-sm text-slate-500">
        Choose who in the company uses Mails and how many mailboxes each may connect (up to {data.cap}, the limit set for your company).
        People without Mails see the CRM exactly as before.
      </p>
      <Input value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Find a person" className="max-w-xs" />
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs uppercase tracking-wide text-slate-400">
              <th className="py-2 pr-3">Person</th>
              <th className="py-2 pr-3">Mails</th>
              <th className="py-2 pr-3">Mailboxes allowed</th>
              <th className="py-2">In use</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {rows.map((r) => (
              <tr key={r.uuid}>
                <td className="py-2 pr-3">
                  <p className="font-medium text-slate-800 dark:text-slate-100">{r.name || r.email}</p>
                  <p className="text-xs text-slate-400">{r.email} · {r.role}</p>
                </td>
                <td className="py-2 pr-3">
                  {r.locked ? (
                    <span className="text-xs text-slate-400">Always (Admin)</span>
                  ) : (
                    <label className="inline-flex cursor-pointer items-center gap-2">
                      <input type="checkbox" checked={r.has_mails} onChange={(e) => save(r.uuid, e.target.checked, r.limit)} />
                      <span className="text-xs">{r.has_mails ? 'On' : 'Off'}</span>
                    </label>
                  )}
                </td>
                <td className="py-2 pr-3">
                  <Select
                    value={r.limit}
                    disabled={r.locked || !r.has_mails}
                    onChange={(e) => save(r.uuid, r.has_mails, Number(e.target.value))}
                    className="w-20"
                  >
                    {Array.from({ length: data.cap }, (_, i) => i + 1).map((n) => <option key={n} value={n}>{n}</option>)}
                  </Select>
                </td>
                <td className="py-2 tabular-nums text-slate-500">{r.mailboxes}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>

    <MailboxAccess mailboxes={data.mailboxes} people={data.data} />
    </div>
  )
}

/**
 * Who opens which mailbox.
 *
 * Sharing decided in one place rather than inside each mailbox's own
 * settings: a company mailbox - sales@, accounts@ - is read by whoever is
 * ticked here, and they can answer from it without being able to change,
 * share or delete it.
 */
function MailboxAccess({ mailboxes, people }: { mailboxes: MailTeamMailbox[]; people: MailTeamRow[] }) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [busy, setBusy] = useState<string | null>(null)

  const share = async (box: MailTeamMailbox, uuid: string, on: boolean) => {
    setBusy(box.uuid)
    const next = on ? [...box.shared_with, uuid] : box.shared_with.filter((u) => u !== uuid)
    try {
      await mails.saveAccount(box.uuid, { shared_with: next })
      toast(on ? 'Access given.' : 'Access removed.', 'success')
      queryClient.invalidateQueries({ queryKey: ['mails'] })
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className={clsx(card, 'space-y-3')}>
      <div>
        <h3 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Mailbox access</h3>
        <p className="text-sm text-slate-500">
          Everybody ticked reads and answers from that mailbox. They cannot change its settings, share it, or delete it -
          and its owner keeps it whatever you tick.
        </p>
      </div>

      {mailboxes.length === 0 && <p className="text-sm text-slate-400">No mailboxes in the company yet.</p>}

      {mailboxes.map((box) => (
        <div key={box.uuid} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
          <p className="text-sm font-medium text-slate-800 dark:text-slate-100">
            {box.label || box.email}
            <span className="ml-2 font-normal text-slate-500">{box.email}</span>
            {box.owner && <span className="ml-2 text-xs text-slate-400">owner: {box.owner}</span>}
          </p>
          <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1.5">
            {people.filter((p) => p.uuid !== box.owner_uuid).map((p) => (
              <label key={p.uuid} className="flex items-center gap-1.5 text-sm">
                <input
                  type="checkbox"
                  disabled={busy === box.uuid || !p.has_mails}
                  checked={box.shared_with.includes(p.uuid)}
                  onChange={(e) => share(box, p.uuid, e.target.checked)}
                />
                <span className={clsx(!p.has_mails && 'text-slate-400')}>
                  {p.name || p.email}{!p.has_mails && ' (no Mails yet)'}
                </span>
              </label>
            ))}
          </div>
        </div>
      ))}
    </div>
  )
}
