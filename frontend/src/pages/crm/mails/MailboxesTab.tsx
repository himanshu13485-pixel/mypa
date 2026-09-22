import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Copy, Inbox, KeyRound, Plus, RefreshCw, Send, ShieldCheck, Star, Trash2, Unplug, Users, XCircle } from 'lucide-react'
import { clsx } from 'clsx'
import { mails, type MailAccountInfo, type MailPerson, type MailProvider } from '../../../api/mails'
import { errorMessage } from '../../../api/client'
import { useToast } from '../../../components/Toast'
import { Button, Input, Label, Modal, Select, Spinner } from '../../../components/ui'
import { mailDate } from './mailUtils'

const card = 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800 sm:p-5'

type AccountForm = {
  label: string
  tag: string
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
  daily_cap: string
  dkim_selector: string
  verify_cert: boolean
}

const blankForm = (p?: MailProvider): AccountForm => ({
  label: '', tag: '', email: '', from_name: '', reply_to: '',
  provider: p?.key ?? 'custom',
  imap_host: p?.imap_host ?? '', imap_port: p?.imap_port ?? 993, imap_encryption: p?.imap_encryption ?? 'ssl', imap_username: '', imap_password: '',
  smtp_host: p?.smtp_host ?? '', smtp_port: p?.smtp_port ?? 587, smtp_encryption: p?.smtp_encryption ?? 'tls', smtp_username: '', smtp_password: '',
  is_default: false, daily_cap: '', dkim_selector: '', verify_cert: true,
})

/** SPF ✓ / DKIM ✗ - what the receiving world can check, at a glance. */
function DnsChip({ label, ok, note }: { label: string; ok: boolean; note: string }) {
  return (
    <span
      title={note}
      className={clsx('rounded-full px-2 py-0.5 text-[11px] font-semibold',
        ok ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'
          : 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300')}
    >
      {label} {ok ? '✓' : '✗'}
    </span>
  )
}

/**
 * The mailboxes screen.
 *
 * A person's own mailboxes and every company one shared with them, each
 * with what it can do, what the world can check about it, and - for the
 * Company Admin - who else may open it.
 */
export default function MailboxesTab() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const { data, isLoading } = useQuery({ queryKey: ['mails', 'accounts'], queryFn: mails.accounts })
  const [editing, setEditing] = useState<MailAccountInfo | 'new' | null>(null)
  const [copying, setCopying] = useState<MailAccountInfo | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [tests, setTests] = useState<Record<string, { ok: boolean; message: string }[]>>({})

  if (isLoading || !data) return <Spinner />

  const atLimit = data.used >= data.limit
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['mails'] })

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

  const said = (uuid: string, lines: { ok: boolean; message: string }[]) => setTests((t) => ({ ...t, [uuid]: lines }))

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-3">
        <p className="text-sm text-slate-500">
          {data.used} of {data.limit} of your own mailbox{data.limit === 1 ? '' : 'es'} used.
          {atLimit && ' To add more, ask your Company Admin to raise your allowance.'}
          {data.is_admin && ' As Admin you can also set a mailbox up for somebody else and share it with several people.'}
        </p>
        <Button className="ml-auto" disabled={atLimit && !data.is_admin} onClick={() => setEditing('new')}>
          <Plus className="size-4" /> Add mailbox
        </Button>
      </div>

      {data.data.length === 0 && <div className={clsx(card, 'text-center text-sm text-slate-500')}>No mailboxes yet.</div>}

      {data.data.map((a) => {
        const lines = tests[a.uuid]
        const detached = !!a.detached_at

        return (
          <div key={a.uuid} className={card}>
            <div className="flex flex-wrap items-start gap-3">
              <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-center gap-2 font-semibold text-slate-900 dark:text-white">
                  {a.label || a.email}
                  {a.tag && <span className="rounded-full bg-violet-100 px-2 py-0.5 text-[11px] font-medium text-violet-700 dark:bg-violet-500/20 dark:text-violet-300">★ {a.tag}</span>}
                  {a.is_default && <span className="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">Default</span>}
                  {a.is_shared && (
                    <span className="flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
                      <Users className="size-3" /> Shared
                    </span>
                  )}
                  <span className={clsx('rounded-full px-2 py-0.5 text-[11px] font-semibold',
                    detached ? 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300'
                      : a.last_error ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300'
                        : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300')}>
                    {detached ? 'DISCONNECTED' : a.last_error ? 'PROBLEM' : 'ACTIVE'}
                  </span>
                </p>

                <p className="text-sm text-slate-500">
                  {a.email}
                  {' · '}{a.can_send ? 'SMTP' : 'no SMTP'}{a.can_receive ? ' + IMAP' : ''}
                  {a.daily_cap ? ` · cap ${a.daily_cap}/day` : ''}
                  {a.daily_cap ? ` (${a.sends_left ?? a.daily_cap} left today)` : ''}
                  {a.owner && ` · ${a.owner}'s mailbox`}
                </p>

                {a.dns && (
                  <p className="mt-1.5 flex flex-wrap items-center gap-1.5">
                    <DnsChip label="SPF" ok={a.dns.spf.ok} note={a.dns.spf.note} />
                    <DnsChip label="DKIM" ok={a.dns.dkim.ok} note={a.dns.dkim.note} />
                    <DnsChip label="DMARC" ok={a.dns.dmarc.ok} note={a.dns.dmarc.note} />
                    <span className="text-xs text-slate-400">score {a.dns.score}/100 · checked {mailDate(a.dns.checked_at)}</span>
                  </p>
                )}

                <p className="mt-1 text-xs text-slate-400">
                  {a.can_receive ? `${a.imap_host}:${a.imap_port}` : 'no incoming server'}
                  {a.can_send && ` · ${a.smtp_host}:${a.smtp_port}`}
                  {a.last_synced_at && ` · checked ${mailDate(a.last_synced_at)}`}
                </p>

                {a.shared_with.length > 0 && (
                  <p className="mt-1 flex flex-wrap items-center gap-1 text-xs text-slate-500">
                    Shared with:
                    {a.shared_with.map((s) => (
                      <span key={s.uuid} className="rounded-full bg-slate-100 px-2 py-0.5 dark:bg-slate-800">{s.name}</span>
                    ))}
                  </p>
                )}

                {a.verify_cert === false && (
                  <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">
                    Certificate checking is off for this mailbox.
                  </p>
                )}

                {detached && (
                  <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">
                    Disconnected {mailDate(a.detached_at)} - its mail is still here to read. Edit it and enter a password to connect it again.
                  </p>
                )}
                {a.last_error && !detached && <p className="mt-1 text-xs text-red-600">{a.last_error}</p>}
              </div>

              <div className="flex flex-wrap gap-1.5">
                {a.can_manage && <Button size="sm" variant="secondary" onClick={() => setEditing(a)}>Edit</Button>}

                {/*
                  * The mailbox's own password, changed here when it has been
                  * changed at the mail provider - by the person whose mailbox
                  * it is, or by the Company Admin when somebody has left or
                  * lost it. It is what we sign in with, not the password at
                  * the provider itself.
                  */}
                {a.can_manage && (
                  <Button size="sm" variant="secondary" disabled={busy !== null} onClick={() => {
                    const password = window.prompt(`New password for ${a.email}.

This is what Netvork signs in with - change it at your mail provider first, then put the same one here.`)
                    if (!password) return
                    void run(`pwd${a.uuid}`, async () => {
                      const res = await mails.saveAccount(a.uuid, { imap_password: password, smtp_password: password })
                      toast(res.message, 'success')
                      said(a.uuid, [{ ok: true, message: 'Password saved. Use Test connection to check it.' }])
                      refresh()
                    })
                  }}>
                    <KeyRound className="size-3.5" /> Change password
                  </Button>
                )}

                {a.can_manage && (
                  <Button size="sm" variant="secondary" disabled={busy !== null} onClick={() => run(`dns${a.uuid}`, async () => {
                    const res = await mails.checkDns(a.uuid)
                    said(a.uuid, [{ ok: res.spf.ok, message: `SPF: ${res.spf.note}` }, { ok: res.dkim.ok, message: `DKIM: ${res.dkim.note}` }, { ok: res.dmarc.ok, message: `DMARC: ${res.dmarc.note}` }])
                    refresh()
                  })}>
                    <ShieldCheck className="size-3.5" /> {busy === `dns${a.uuid}` ? 'Checking…' : 'Check DNS auth'}
                  </Button>
                )}

                <Button size="sm" variant="secondary" disabled={busy !== null || detached} onClick={() => run(`test${a.uuid}`, async () => {
                  const res = await mails.testAccount(a.uuid)
                  said(a.uuid, [{ ok: res.imap.ok, message: `Incoming (IMAP): ${res.imap.message}` }, { ok: res.smtp.ok, message: `Outgoing (SMTP): ${res.smtp.message}` }])
                })}>
                  {busy === `test${a.uuid}` ? 'Testing…' : 'Test connection'}
                </Button>

                {a.can_receive && (
                  <Button size="sm" variant="secondary" disabled={busy !== null} onClick={() => run(`inbox${a.uuid}`, async () => {
                    const res = await mails.testInbox(a.uuid)
                    said(a.uuid, [{ ok: res.ok, message: res.message }])
                  })}>
                    <Inbox className="size-3.5" /> Test inbox (IMAP)
                  </Button>
                )}

                {a.can_send && (
                  <Button size="sm" variant="secondary" disabled={busy !== null} onClick={() => run(`mail${a.uuid}`, async () => {
                    const to = window.prompt('Send a test message to which address?', a.email)
                    if (!to) return
                    const res = await mails.testEmail(a.uuid, to)
                    said(a.uuid, [{ ok: res.ok, message: res.message }])
                  })}>
                    <Send className="size-3.5" /> Send test email
                  </Button>
                )}

                {a.can_receive && (
                  <Button size="sm" variant="secondary" disabled={busy !== null} onClick={() => run(`sync${a.uuid}`, async () => {
                    const res = await mails.syncAccount(a.uuid, true)
                    toast(res.message, 'success')
                    refresh()
                  })}>
                    <RefreshCw className={clsx('size-3.5', busy === `sync${a.uuid}` && 'animate-spin')} /> Sync now
                  </Button>
                )}

                {data.is_admin && <Button size="sm" variant="secondary" onClick={() => setCopying(a)}><Copy className="size-3.5" /> Replicate</Button>}

                {a.can_manage && !a.is_default && (
                  <Button size="sm" variant="ghost" disabled={busy !== null} onClick={() => run(`def${a.uuid}`, async () => {
                    await mails.saveAccount(a.uuid, { is_default: true })
                    refresh()
                  })}>
                    <Star className="size-3.5" /> Make default
                  </Button>
                )}

                {data.is_admin && (
                  <>
                    {!detached && (
                      <Button size="sm" variant="ghost" disabled={busy !== null} onClick={() => {
                        if (!window.confirm(`Disconnect ${a.email}? Its mail stays here to read, and a new account can take its place.`)) return
                        void run(`off${a.uuid}`, async () => {
                          const res = await mails.removeAccount(a.uuid)
                          toast(res.message, 'success')
                          refresh()
                        })
                      }}>
                        <Unplug className="size-3.5" /> Disconnect
                      </Button>
                    )}
                    <Button size="sm" variant="ghost" className="text-red-600" disabled={busy !== null} onClick={() => {
                      const typed = window.prompt(`This removes ${a.email} AND every message stored for it. Type the address to confirm.`)
                      if (typed !== a.email) return
                      void run(`del${a.uuid}`, async () => {
                        const res = await mails.removeAccount(a.uuid, { confirm: typed })
                        toast(res.message, 'success')
                        refresh()
                      })
                    }}>
                      <Trash2 className="size-3.5" /> Delete
                    </Button>
                  </>
                )}
              </div>
            </div>

            {lines && (
              <div className="mt-3 space-y-1.5 text-xs">
                {lines.map((line, i) => (
                  <p key={i} className={clsx('flex items-start gap-1.5 rounded-lg p-2',
                    line.ok ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200' : 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-200')}>
                    {line.ok ? <CheckCircle2 className="size-4 shrink-0" /> : <XCircle className="size-4 shrink-0" />}
                    <span>{line.message}</span>
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
          people={data.people}
          isAdmin={data.is_admin}
          onClose={() => setEditing(null)}
        />
      )}
      {copying && <ReplicateModal account={copying} people={data.people} onClose={() => setCopying(null)} />}
    </div>
  )
}

function AccountModal({ account, providers, people, isAdmin, onClose }: {
  account: MailAccountInfo | null
  providers: MailProvider[]
  people: MailPerson[]
  isAdmin: boolean
  onClose: () => void
}) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [form, setForm] = useState<AccountForm>(() => account
    ? {
        label: account.label ?? '', tag: account.tag ?? '', email: account.email, from_name: account.from_name ?? '', reply_to: account.reply_to ?? '',
        provider: account.provider,
        imap_host: account.imap_host ?? '', imap_port: account.imap_port, imap_encryption: account.imap_encryption, imap_username: account.imap_username ?? '', imap_password: '',
        smtp_host: account.smtp_host ?? '', smtp_port: account.smtp_port, smtp_encryption: account.smtp_encryption, smtp_username: account.smtp_username ?? '', smtp_password: '',
        is_default: account.is_default, daily_cap: account.daily_cap ? String(account.daily_cap) : '', dkim_selector: account.dkim_selector ?? '',
        verify_cert: account.verify_cert !== false,
      }
    : blankForm(providers.find((p) => p.key === 'gmail')))
  // Only ticked when the two logins really are one - an SES mailbox, say,
  // signs in to send with credentials of its own.
  const [sameLogin, setSameLogin] = useState(() => !account || !account.smtp_username || account.smtp_username === account.imap_username)
  const [owner, setOwner] = useState('')
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
      const body: Record<string, unknown> = { ...form, daily_cap: form.daily_cap ? Number(form.daily_cap) : null }
      if (!form.imap_username) body.imap_username = form.email
      if (sameLogin) {
        body.smtp_username = form.imap_username || form.email
        if (form.imap_password) body.smtp_password = form.imap_password
      }
      if (!body.imap_password) delete body.imap_password
      if (!body.smtp_password) delete body.smtp_password
      // Who else opens this mailbox is settled under Team access, where all
      // of a company's sharing can be seen at once.
      if (isAdmin && !account && owner) {
        body.member = owner
      }

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
    <Modal title={account ? `Edit ${account.email}` : 'Add a mailbox'} onClose={onClose} wide sticky>
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

        {/* The Admin's half: whose mailbox this is, who else opens it, how hard it may be worked. */}
        {isAdmin && (
          <fieldset className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
            <legend className="px-1 text-sm font-semibold text-slate-700 dark:text-slate-200">Company settings</legend>
            <div className="grid gap-3 sm:grid-cols-2">
              {!account && (
                <div>
                  <Label>Whose mailbox is this?</Label>
                  <Select value={owner} onChange={(e) => setOwner(e.target.value)}>
                    <option value="">Mine</option>
                    {people.filter((p) => p.has_mails).map((p) => <option key={p.uuid} value={p.uuid}>{p.name}</option>)}
                  </Select>
                  <p className="mt-1 text-xs text-slate-400">It counts against that person's mailbox allowance.</p>
                </div>
              )}
              <div><Label>Badge (optional)</Label><Input value={form.tag} onChange={(e) => set('tag', e.target.value)} placeholder="e.g. Reports, Support desk" maxLength={60} /></div>
              <div>
                <Label>Daily sending limit (optional)</Label>
                <Input type="number" min={1} value={form.daily_cap} onChange={(e) => set('daily_cap', e.target.value)} placeholder="e.g. 200" />
                <p className="mt-1 text-xs text-slate-400">Over the limit, mail waits for tomorrow instead of failing.</p>
              </div>
              <div><Label>DKIM selector (optional)</Label><Input value={form.dkim_selector} onChange={(e) => set('dkim_selector', e.target.value)} placeholder="e.g. default, google, s1" /></div>
            </div>
          </fieldset>
        )}

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

        {/*
          * Shared hosting reached by the customer's own domain answers with
          * the hosting company's certificate, so a mailbox that is otherwise
          * fine fails a strict check. Connecting to the name on the
          * certificate is the better answer; this is the one for hosts that
          * offer nothing better.
          */}
        <label className="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
          <input type="checkbox" className="mt-0.5" checked={!form.verify_cert} onChange={(e) => set('verify_cert', !e.target.checked)} />
          <span>
            Accept this server's certificate even if it is issued for another name
            <span className="block text-xs text-slate-400">
              For shared hosting that answers with its own certificate (e.g. *.web-hosting.com). The connection stays encrypted,
              but it no longer proves which server it reached - leave this off unless a certificate error says otherwise.
            </span>
          </span>
        </label>

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

/** The same servers under another address - one form, four fewer mistakes. */
function ReplicateModal({ account, people, onClose }: { account: MailAccountInfo; people: MailPerson[]; onClose: () => void }) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [email, setEmail] = useState('')
  const [label, setLabel] = useState(account.label ?? '')
  const [member, setMember] = useState('')
  const [same, setSame] = useState(false)
  const [password, setPassword] = useState('')

  const copy = useMutation({
    mutationFn: () => mails.replicate(account.uuid, {
      email, label, member: member || undefined, same_credentials: same,
      imap_password: same ? undefined : password, smtp_password: same ? undefined : password,
    }),
    onSuccess: (res) => {
      toast(res.message, 'success')
      queryClient.invalidateQueries({ queryKey: ['mails'] })
      onClose()
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <Modal title={`Replicate ${account.email}`} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); copy.mutate() }}>
        <p className="text-sm text-slate-500">
          The same servers, ports and security as {account.email}, under a new address.
        </p>
        <div><Label>New address</Label><Input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} placeholder="support@yourdomain.com" /></div>
        <div><Label>Label</Label><Input value={label} onChange={(e) => setLabel(e.target.value)} /></div>
        <div>
          <Label>Whose mailbox</Label>
          <Select value={member} onChange={(e) => setMember(e.target.value)}>
            <option value="">Mine</option>
            {people.filter((p) => p.has_mails).map((p) => <option key={p.uuid} value={p.uuid}>{p.name}</option>)}
          </Select>
        </div>
        <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
          <input type="checkbox" checked={same} onChange={(e) => setSame(e.target.checked)} />
          Sign in with the same username and password
        </label>
        {!same && (
          <div>
            <Label>Password for the new address</Label>
            <Input type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} />
            <p className="mt-1 text-xs text-slate-400">Its username will be the new address.</p>
          </div>
        )}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={copy.isPending}>{copy.isPending ? 'Copying…' : 'Create the copy'}</Button>
        </div>
      </form>
    </Modal>
  )
}
