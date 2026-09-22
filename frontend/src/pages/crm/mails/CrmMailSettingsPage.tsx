import { useEffect, useState } from 'react'
import { useLocation, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Plus, RefreshCw, Star, Trash2, XCircle } from 'lucide-react'
import { clsx } from 'clsx'
import { mails, type MailAccountInfo, type MailPrefs, type MailProvider } from '../../../api/mails'
import { errorMessage } from '../../../api/client'
import { useToast } from '../../../components/Toast'
import { Button, Input, Label, LoadError, Modal, Select, Spinner, Textarea } from '../../../components/ui'
import RichEditor from './RichEditor'
import { ACCENTS, mailDate } from './mailUtils'

type Tab = 'mailboxes' | 'preferences' | 'signatures' | 'autoreply' | 'labels' | 'ai' | 'team'

const LABEL_COLORS = ['#2563eb', '#16a34a', '#dc2626', '#d97706', '#7c3aed', '#db2777', '#0891b2', '#475569']

const card = 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800 sm:p-5'

/**
 * Everything about how Mails works for this person - and, for the Company
 * Admin, for the company: who has Mails, how many mailboxes each may add,
 * and which AI writes the drafts.
 */
export default function CrmMailSettingsPage() {
  const [search, setSearch] = useSearchParams()
  // "Manage labels" is its own address, landing on the Labels tab.
  const initialTab: Tab = useLocation().pathname.endsWith('/mails/labels') ? 'labels' : 'mailboxes'
  const { data: settings, isLoading, isError, refetch } = useQuery({ queryKey: ['mails', 'settings'], queryFn: mails.settings })
  const tab = (search.get('tab') as Tab | null) ?? initialTab

  if (isLoading) return <div className="flex h-full items-center justify-center"><Spinner /></div>
  if (isError || !settings) return <div className="p-6"><LoadError onRetry={() => refetch()} /></div>

  const tabs: [Tab, string][] = [
    ['mailboxes', 'Mailboxes'],
    ['preferences', 'Preferences & theme'],
    ['signatures', 'Signatures'],
    ['autoreply', 'Auto-reply & forwarding'],
    ['labels', 'Labels'],
    ...(settings.is_admin ? ([['ai', 'AI assistant'], ['team', 'Team access']] as [Tab, string][]) : []),
  ]

  return (
    <div className="scroll-pane h-full overflow-y-auto p-4 sm:p-6">
      <h1 className="mb-4 text-xl font-semibold text-slate-900 dark:text-white">Mail settings</h1>
      <div className="scroll-pane mb-5 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800">
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
        {tab === 'labels' && <LabelsTab />}
        {tab === 'ai' && settings.is_admin && <AiTab />}
        {tab === 'team' && settings.is_admin && <TeamTab />}
      </div>
    </div>
  )
}

/* ------------------------------------------------------------ Mailboxes */

type AccountForm = {
  label: string
  email: string
  from_name: string
  reply_to: string
  provider: string
  imap_host: string
  imap_port: number
  imap_encryption: string
  imap_username: string
  imap_password: string
  smtp_host: string
  smtp_port: number
  smtp_encryption: string
  smtp_username: string
  smtp_password: string
  is_default: boolean
}

const blankForm = (p?: MailProvider): AccountForm => ({
  label: '', email: '', from_name: '', reply_to: '',
  provider: p?.key ?? 'custom',
  imap_host: p?.imap_host ?? '', imap_port: p?.imap_port ?? 993, imap_encryption: p?.imap_encryption ?? 'ssl', imap_username: '', imap_password: '',
  smtp_host: p?.smtp_host ?? '', smtp_port: p?.smtp_port ?? 587, smtp_encryption: p?.smtp_encryption ?? 'tls', smtp_username: '', smtp_password: '',
  is_default: false,
})

function MailboxesTab() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const { data, isLoading } = useQuery({ queryKey: ['mails', 'accounts'], queryFn: mails.accounts })
  const [editing, setEditing] = useState<MailAccountInfo | 'new' | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [tests, setTests] = useState<Record<string, { imap: { ok: boolean; message: string }; smtp: { ok: boolean; message: string } }>>({})

  if (isLoading || !data) return <Spinner />
  const atLimit = data.data.length >= data.limit

  const run = async (key: string, fn: () => Promise<unknown>) => {
    setBusy(key)
    try {
      await fn()
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-3">
        <p className="text-sm text-slate-500">
          {data.data.length} of {data.limit} mailbox{data.limit === 1 ? '' : 'es'} used.
          {atLimit && ' To add more, ask your Company Admin to raise your limit.'}
        </p>
        <Button className="ml-auto" disabled={atLimit} onClick={() => setEditing('new')}><Plus className="size-4" /> Add mailbox</Button>
      </div>

      {data.data.length === 0 && <div className={clsx(card, 'text-center text-sm text-slate-500')}>No mailboxes yet.</div>}

      {data.data.map((a) => {
        const test = tests[a.uuid]
        return (
          <div key={a.uuid} className={card}>
            <div className="flex flex-wrap items-start gap-3">
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 font-semibold text-slate-900 dark:text-white">
                  {a.label || a.email}
                  {a.is_default && <span className="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">Default</span>}
                </p>
                <p className="text-sm text-slate-500">{a.email} · {data.providers.find((p) => p.key === a.provider)?.label ?? a.provider}</p>
                <p className="mt-1 text-xs text-slate-400">
                  Incoming: {a.can_receive ? `${a.imap_host}:${a.imap_port}` : 'not set'} · Outgoing: {a.can_send ? `${a.smtp_host}:${a.smtp_port}` : 'not set'}
                  {a.last_synced_at && <> · checked {mailDate(a.last_synced_at)}</>}
                </p>
                {a.last_error && <p className="mt-1 text-xs text-red-600">{a.last_error}</p>}
              </div>
              <div className="flex flex-wrap gap-1.5">
                <Button size="sm" variant="secondary" disabled={busy !== null} onClick={() => run(`test${a.uuid}`, async () => {
                  const res = await mails.testAccount(a.uuid)
                  setTests((t) => ({ ...t, [a.uuid]: res }))
                })}>
                  {busy === `test${a.uuid}` ? 'Testing…' : 'Test connection'}
                </Button>
                {a.can_receive && (
                  <Button size="sm" variant="secondary" disabled={busy !== null} onClick={() => run(`sync${a.uuid}`, async () => {
                    const res = await mails.syncAccount(a.uuid, true)
                    toast(res.message, 'success')
                    queryClient.invalidateQueries({ queryKey: ['mails'] })
                  })}>
                    <RefreshCw className={clsx('size-3.5', busy === `sync${a.uuid}` && 'animate-spin')} /> Sync now
                  </Button>
                )}
                {!a.is_default && (
                  <Button size="sm" variant="ghost" disabled={busy !== null} onClick={() => run(`def${a.uuid}`, async () => {
                    await mails.saveAccount(a.uuid, { is_default: true })
                    queryClient.invalidateQueries({ queryKey: ['mails'] })
                  })}>
                    <Star className="size-3.5" /> Make default
                  </Button>
                )}
                <Button size="sm" variant="secondary" onClick={() => setEditing(a)}>Edit</Button>
                <Button size="sm" variant="ghost" className="text-red-600" disabled={busy !== null} onClick={() => {
                  if (!window.confirm(`Remove ${a.email}? Its mail is removed from Netvork - nothing is deleted on the mail server.`)) return
                  void run(`del${a.uuid}`, async () => {
                    const res = await mails.removeAccount(a.uuid)
                    toast(res.message, 'success')
                    queryClient.invalidateQueries({ queryKey: ['mails'] })
                  })
                }}>
                  <Trash2 className="size-3.5" />
                </Button>
              </div>
            </div>
            {test && (
              <div className="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                {(['imap', 'smtp'] as const).map((k) => (
                  <p key={k} className={clsx('flex items-start gap-1.5 rounded-lg p-2', test[k].ok ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200' : 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-200')}>
                    {test[k].ok ? <CheckCircle2 className="size-4 shrink-0" /> : <XCircle className="size-4 shrink-0" />}
                    <span><b>{k === 'imap' ? 'Incoming (IMAP)' : 'Outgoing (SMTP)'}:</b> {test[k].message}</span>
                  </p>
                ))}
              </div>
            )}
          </div>
        )
      })}

      {editing && (
        <AccountModal
          account={editing === 'new' ? null : editing}
          providers={data.providers}
          onClose={() => setEditing(null)}
        />
      )}
    </div>
  )
}

function AccountModal({ account, providers, onClose }: { account: MailAccountInfo | null; providers: MailProvider[]; onClose: () => void }) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [form, setForm] = useState<AccountForm>(() => account
    ? {
        label: account.label ?? '', email: account.email, from_name: account.from_name ?? '', reply_to: account.reply_to ?? '',
        provider: account.provider,
        imap_host: account.imap_host ?? '', imap_port: account.imap_port, imap_encryption: account.imap_encryption, imap_username: account.imap_username ?? '', imap_password: '',
        smtp_host: account.smtp_host ?? '', smtp_port: account.smtp_port, smtp_encryption: account.smtp_encryption, smtp_username: account.smtp_username ?? '', smtp_password: '',
        is_default: account.is_default,
      }
    : blankForm(providers.find((p) => p.key === 'gmail')))
  // Only ticked when the two logins really are one - an SES mailbox, say,
  // signs in to send with credentials of its own.
  const [sameLogin, setSameLogin] = useState(() => !account || !account.smtp_username || account.smtp_username === account.imap_username)
  const provider = providers.find((p) => p.key === form.provider)
  const set = <K extends keyof AccountForm>(k: K, v: AccountForm[K]) => setForm((f) => ({ ...f, [k]: v }))

  const pickProvider = (key: string) => {
    const p = providers.find((x) => x.key === key)
    setForm((f) => ({
      ...f,
      provider: key,
      ...(p && key !== 'custom' ? {
        imap_host: p.imap_host, imap_port: p.imap_port, imap_encryption: p.imap_encryption,
        smtp_host: p.smtp_host, smtp_port: p.smtp_port, smtp_encryption: p.smtp_encryption,
      } : {}),
    }))
  }

  const save = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = { ...form }
      if (!form.imap_username) body.imap_username = form.email
      if (sameLogin) {
        body.smtp_username = form.imap_username || form.email
        if (form.imap_password) body.smtp_password = form.imap_password
      }
      if (!body.imap_password) delete body.imap_password
      if (!body.smtp_password) delete body.smtp_password
      return account ? mails.saveAccount(account.uuid, body) : mails.addAccount(body)
    },
    onSuccess: (res) => {
      toast(res.message, 'success')
      queryClient.invalidateQueries({ queryKey: ['mails'] })
      onClose()
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const encryption = (value: string, onChange: (v: string) => void) => (
    <Select value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="ssl">SSL</option>
      <option value="tls">STARTTLS</option>
      <option value="none">None</option>
    </Select>
  )

  return (
    <Modal title={account ? `Edit ${account.email}` : 'Add a mailbox'} onClose={onClose} wide>
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        <div>
          <Label>Provider</Label>
          <div className="flex flex-wrap gap-1.5">
            {providers.map((p) => (
              <button
                key={p.key}
                type="button"
                onClick={() => pickProvider(p.key)}
                className={clsx('rounded-full px-3 py-1 text-xs font-medium ring-1', form.provider === p.key ? 'bg-brand-600 text-white ring-brand-600' : 'text-slate-600 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800')}
              >
                {p.label}
              </button>
            ))}
          </div>
          {provider?.note && <p className="mt-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{provider.note}</p>}
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <div><Label>Email address</Label><Input type="email" required value={form.email} onChange={(e) => set('email', e.target.value)} /></div>
          <div><Label>Name on sent mail</Label><Input value={form.from_name} onChange={(e) => set('from_name', e.target.value)} placeholder="e.g. Priya from Acme" /></div>
          <div><Label>Label (optional)</Label><Input value={form.label} onChange={(e) => set('label', e.target.value)} placeholder="e.g. Sales" /></div>
          <div><Label>Reply-To (optional)</Label><Input type="email" value={form.reply_to} onChange={(e) => set('reply_to', e.target.value)} /></div>
        </div>

        <fieldset className="rounded-xl p-3 ring-1 ring-slate-200 dark:ring-slate-700">
          <legend className="px-1 text-sm font-semibold text-slate-700 dark:text-slate-200">Incoming mail (IMAP)</legend>
          <div className="grid gap-3 sm:grid-cols-4">
            <div className="sm:col-span-2"><Label>Server</Label><Input value={form.imap_host} onChange={(e) => set('imap_host', e.target.value)} placeholder="imap.example.com" /></div>
            <div><Label>Port</Label><Input type="number" value={form.imap_port} onChange={(e) => set('imap_port', Number(e.target.value))} /></div>
            <div><Label>Security</Label>{encryption(form.imap_encryption, (v) => set('imap_encryption', v))}</div>
            <div className="sm:col-span-2"><Label>Username</Label><Input value={form.imap_username} onChange={(e) => set('imap_username', e.target.value)} placeholder={form.email || 'Usually the email address'} /></div>
            <div className="sm:col-span-2">
              <Label>Password {account?.has_imap_password && <span className="font-normal text-slate-400">(leave blank to keep)</span>}</Label>
              <Input type="password" autoComplete="new-password" value={form.imap_password} onChange={(e) => set('imap_password', e.target.value)} />
            </div>
          </div>
          <p className="mt-2 text-xs text-slate-400">Leave the server blank for a send-only mailbox (e.g. Amazon SES). POP3 is not supported - use IMAP, which every major provider offers.</p>
        </fieldset>

        <fieldset className="rounded-xl p-3 ring-1 ring-slate-200 dark:ring-slate-700">
          <legend className="px-1 text-sm font-semibold text-slate-700 dark:text-slate-200">Outgoing mail (SMTP)</legend>
          <div className="grid gap-3 sm:grid-cols-4">
            <div className="sm:col-span-2"><Label>Server</Label><Input value={form.smtp_host} onChange={(e) => set('smtp_host', e.target.value)} placeholder="smtp.example.com" /></div>
            <div><Label>Port</Label><Input type="number" value={form.smtp_port} onChange={(e) => set('smtp_port', Number(e.target.value))} /></div>
            <div><Label>Security</Label>{encryption(form.smtp_encryption, (v) => set('smtp_encryption', v))}</div>
          </div>
          <label className="mt-3 flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" checked={sameLogin} onChange={(e) => setSameLogin(e.target.checked)} />
            Same username and password as incoming
          </label>
          {!sameLogin && (
            <div className="mt-3 grid gap-3 sm:grid-cols-2">
              <div><Label>Username</Label><Input value={form.smtp_username} onChange={(e) => set('smtp_username', e.target.value)} placeholder="SMTP username (for SES: the SMTP credential)" /></div>
              <div>
                <Label>Password {account?.has_smtp_password && <span className="font-normal text-slate-400">(leave blank to keep)</span>}</Label>
                <Input type="password" autoComplete="new-password" value={form.smtp_password} onChange={(e) => set('smtp_password', e.target.value)} />
              </div>
            </div>
          )}
        </fieldset>

        <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
          <input type="checkbox" checked={form.is_default} onChange={(e) => set('is_default', e.target.checked)} />
          Use this mailbox by default when writing
        </label>

        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={save.isPending}>{save.isPending ? 'Saving…' : account ? 'Save' : 'Add mailbox'}</Button>
        </div>
      </form>
    </Modal>
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
  const save = useMutation({
    mutationFn: () => mails.saveAccount(account.uuid, { signature_html: html }),
    onSuccess: () => { toast('Signature saved.', 'success'); queryClient.invalidateQueries({ queryKey: ['mails', 'accounts'] }) },
    onError: (err) => toastError(errorMessage(err)),
  })
  return (
    <div className={card}>
      <p className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">{account.email}</p>
      <RichEditor value={html} onChange={setHtml} minHeight={110} placeholder="Name, title, phone - added under every mail from this mailbox" />
      <Button className="mt-3" size="sm" onClick={() => save.mutate()} disabled={save.isPending}>Save signature</Button>
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
  const [forward, setForward] = useState(account.forward_to ?? '')
  const save = useMutation({
    mutationFn: () => mails.saveAccount(account.uuid, {
      auto_reply: { ...reply, from: reply.from || null, until: reply.until || null },
      forward_to: forward.trim() || null,
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
      <div className="max-w-md">
        <Label>Forward a copy of new mail to</Label>
        <Input type="email" value={forward} onChange={(e) => setForward(e.target.value)} placeholder="someone@example.com (blank = off)" />
      </div>
      <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending}>Save</Button>
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
  )
}
