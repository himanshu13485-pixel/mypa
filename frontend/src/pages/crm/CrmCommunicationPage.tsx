import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Mail, MessageCircle, Send } from 'lucide-react'
import { crm, type CrmCommunicationSettings, type CrmCompanySender } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, Input, Label, Select, Spinner } from '../../components/ui'

/**
 * The Communication setup: which addresses the company's mail goes out
 * from — the general sender, and optional separate senders for invoices
 * and for due-payment follow-ups — plus the channel switches. Configured
 * here, not in a server file, so changing it is an office job.
 */
export default function CrmCommunicationPage() {
  const { toast, toastError } = useToast()
  const { data, isLoading } = useQuery({ queryKey: ['crm', 'communication'], queryFn: crm.masterData.communication })
  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const [draft, setDraft] = useState<CrmCommunicationSettings | null>(null)

  useEffect(() => { if (data && !draft) setDraft(data) }, [data, draft])

  const save = useMutation({
    mutationFn: () => crm.masterData.saveCommunication(draft!),
    onSuccess: (res) => toast(res.message, 'success'),
    onError: (err) => toastError(errorMessage(err)),
  })

  if (isLoading || !draft) return <div className="flex justify-center py-20"><Spinner /></div>

  const set = (patch: Partial<CrmCommunicationSettings>) => setDraft({ ...draft, ...patch })

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <div>
        <h1 className="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-white">
          <Send className="size-5 text-emerald-500" /> Communication
        </h1>
        <p className="text-sm text-slate-500">
          Where the company&rsquo;s outbound mail goes from, and which channels are on. Every invoice sent and
          every due follow-up is recorded — invoices on the Invoice log, follow-ups on the Outstanding screen.
        </p>
      </div>

      <Card>
        <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
          <Mail className="size-4 text-emerald-500" /> Company e-mail senders
        </h2>
        <p className="mt-0.5 text-xs text-slate-400">
          Leave a field blank to fall back to the previous one — dues fall back to the general sender, and the
          general sender to the server default.
        </p>
        <div className="mt-3 grid gap-3 sm:grid-cols-2">
          <div>
            <Label>From name</Label>
            <Input value={draft.from_name ?? ''} onChange={(e) => set({ from_name: e.target.value || null })} placeholder="GRAPOUT Accounts" className="w-full" />
          </div>
          <div>
            <Label>General from address</Label>
            <Input type="email" value={draft.from_address ?? ''} onChange={(e) => set({ from_address: e.target.value || null })} placeholder="accounts@company.com" className="w-full" />
          </div>
          <div>
            <Label>Invoices sent from (optional, separate)</Label>
            <Input type="email" value={draft.invoice_from_address ?? ''} onChange={(e) => set({ invoice_from_address: e.target.value || null })} placeholder="billing@company.com" className="w-full" />
          </div>
          <div>
            <Label>Due follow-ups sent from (optional, separate)</Label>
            <Input type="email" value={draft.dues_from_address ?? ''} onChange={(e) => set({ dues_from_address: e.target.value || null })} placeholder="recovery@company.com" className="w-full" />
          </div>
        </div>
        <p className="mt-2 text-xs text-slate-400">
          Sending an invoice offers the sender choice on the spot; the automatic due-payment chaser (Billing setup →
          Payment rules) uses the dues sender when one is set.
        </p>
      </Card>

      <Card>
        <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
          <MessageCircle className="size-4 text-emerald-500" /> Channels
        </h2>
        <div className="mt-3 space-y-2">
          {([
            ['email_enabled', 'Email', 'Outbound mail — invoices, proformas and due follow-ups. Untick to pause every send.'],
            ['netvork_enabled', 'Netvork alerts', 'In-app + desktop push notifications — live now.'],
            ['whatsapp_enabled', 'WhatsApp alerts', 'Switch reserved — turns live once the WhatsApp business account is connected.'],
            ['telegram_enabled', 'Telegram alerts', 'Switch reserved — turns live once the Telegram bot is connected.'],
          ] as const).map(([key, label, hint]) => (
            <label key={key} className="flex items-start gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60">
              <input
                type="checkbox"
                checked={!!draft[key]}
                onChange={(e) => set({ [key]: e.target.checked })}
                className="mt-0.5 size-4 accent-emerald-600"
              />
              <span>
                <span className="font-medium text-slate-700 dark:text-slate-200">{label}</span>
                <span className="block text-xs text-slate-400">{hint}</span>
              </span>
            </label>
          ))}
        </div>
      </Card>

      {/* One sender + one mailbox per issuing company: Acme Exports'
          invoices leave from ITS address through ITS server (SMTP or AWS
          SES, grapme-mailbox style), never a sister company's. */}
      <Card>
        <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Company senders &amp; mailboxes</h2>
        <p className="mt-0.5 text-xs text-slate-400">
          Each registered company can carry its own from-address and its own mailbox. A company without one falls
          back to the senders above. Passwords and keys are stored encrypted; a saved secret shows as ********.
        </p>
        <div className="mt-3 space-y-4">
          {(masters?.issuing_companies ?? []).map((c) => {
            const sender: CrmCompanySender = draft.company_senders?.[String(c.id)] ?? {}
            const setSender = (patch: Partial<CrmCompanySender>) => set({
              company_senders: { ...(draft.company_senders ?? {}), [String(c.id)]: { ...sender, ...patch } },
            })
            return (
              <div key={c.id} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
                <div className="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">{c.name}</div>
                <div className="grid gap-2 sm:grid-cols-3">
                  <div>
                    <Label>Mailbox label</Label>
                    <Input value={sender.label ?? ''} onChange={(e) => setSender({ label: e.target.value || null })} placeholder={`${c.name} accounts`} className="w-full" />
                  </div>
                  <div>
                    <Label>From name</Label>
                    <Input value={sender.from_name ?? ''} onChange={(e) => setSender({ from_name: e.target.value || null })} className="w-full" />
                  </div>
                  <div>
                    <Label>Protocol</Label>
                    <Select value={sender.mailer ?? 'none'} onChange={(e) => setSender({ mailer: e.target.value as CrmCompanySender['mailer'] })} className="w-full">
                      <option value="none">Server default</option>
                      <option value="smtp">SMTP</option>
                      <option value="ses">AWS SES</option>
                    </Select>
                  </div>
                  <div>
                    <Label>Email address</Label>
                    <Input type="email" value={sender.from_address ?? ''} onChange={(e) => setSender({ from_address: e.target.value || null })} placeholder={`accounts@${c.name.toLowerCase().split(' ')[0]}.com`} className="w-full" />
                  </div>
                  {sender.mailer === 'smtp' && (
                    <>
                      <div><Label>Password / app-password</Label><Input type="password" value={sender.smtp_password ?? ''} onChange={(e) => setSender({ smtp_password: e.target.value || null })} className="w-full" /></div>
                      <div><Label>SMTP username (optional)</Label><Input value={sender.smtp_username ?? ''} onChange={(e) => setSender({ smtp_username: e.target.value || null })} placeholder="Only if login differs from email" className="w-full" /></div>
                      <div><Label>SMTP host</Label><Input value={sender.smtp_host ?? ''} onChange={(e) => setSender({ smtp_host: e.target.value || null })} placeholder="smtp.gmail.com" className="w-full" /></div>
                      <div><Label>SMTP port</Label><Input type="number" value={sender.smtp_port ?? 587} onChange={(e) => setSender({ smtp_port: Number(e.target.value) || 587 })} className="w-full" /></div>
                      <div>
                        <Label>SMTP encryption</Label>
                        <Select value={sender.smtp_encryption ?? 'tls'} onChange={(e) => setSender({ smtp_encryption: e.target.value as CrmCompanySender['smtp_encryption'] })} className="w-full">
                          <option value="tls">STARTTLS (usually port 587)</option>
                          <option value="ssl">SSL / TLS (usually port 465)</option>
                          <option value="none">None (plain — avoid)</option>
                        </Select>
                      </div>
                      <div><Label>IMAP host (for receiving)</Label><Input value={sender.imap_host ?? ''} onChange={(e) => setSender({ imap_host: e.target.value || null })} placeholder="mail.yourdomain.com" className="w-full" /></div>
                      <div><Label>IMAP port</Label><Input type="number" value={sender.imap_port ?? 993} onChange={(e) => setSender({ imap_port: Number(e.target.value) || 993 })} className="w-full" /></div>
                      <div>
                        <Label>IMAP encryption</Label>
                        <Select value={sender.imap_encryption ?? 'ssl'} onChange={(e) => setSender({ imap_encryption: e.target.value as CrmCompanySender['imap_encryption'] })} className="w-full">
                          <option value="ssl">SSL / TLS (usually port 993)</option>
                          <option value="tls">STARTTLS (usually port 143)</option>
                          <option value="none">None (plain — avoid)</option>
                        </Select>
                      </div>
                      <div><Label>IMAP username (optional)</Label><Input value={sender.imap_username ?? ''} onChange={(e) => setSender({ imap_username: e.target.value || null })} placeholder="Defaults to email" className="w-full" /></div>
                      <div><Label>IMAP password (optional)</Label><Input type="password" value={sender.imap_password ?? ''} onChange={(e) => setSender({ imap_password: e.target.value || null })} placeholder="If different from SMTP" className="w-full" /></div>
                      <label className="flex items-center gap-2 self-end pb-2 text-xs text-slate-600 dark:text-slate-300">
                        <input
                          type="checkbox"
                          checked={!!sender.imap_allow_self_signed}
                          onChange={(e) => setSender({ imap_allow_self_signed: e.target.checked })}
                          className="size-4 accent-emerald-600"
                        />
                        Allow self-signed IMAP certificate
                      </label>
                    </>
                  )}
                  {sender.mailer === 'ses' && (
                    <>
                      <div><Label>AWS key</Label><Input value={sender.ses_key ?? ''} onChange={(e) => setSender({ ses_key: e.target.value || null })} className="w-full" /></div>
                      <div><Label>AWS secret</Label><Input type="password" value={sender.ses_secret ?? ''} onChange={(e) => setSender({ ses_secret: e.target.value || null })} className="w-full" /></div>
                      <div><Label>Region</Label><Input value={sender.ses_region ?? 'ap-south-1'} onChange={(e) => setSender({ ses_region: e.target.value || null })} className="w-full" /></div>
                    </>
                  )}
                </div>
                {sender.mailer === 'smtp' && (
                  <p className="mt-2 text-xs text-slate-400">
                    The SMTP login defaults to the email address; set the username only when the host wants a
                    different one. IMAP details are kept for receiving replies into the CRM when that goes live.
                  </p>
                )}
              </div>
            )
          })}
          {(masters?.issuing_companies ?? []).length === 0 && (
            <p className="text-sm text-slate-400">Add issuing companies in Billing setup first.</p>
          )}
        </div>
      </Card>

      <Button className="w-full" disabled={save.isPending} onClick={() => save.mutate()}>
        {save.isPending ? 'Saving…' : 'Save communication setup'}
      </Button>
    </div>
  )
}
