import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Mail, Pencil, Plus, Send, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmNewsletter } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner, Textarea } from '../../components/ui'

const AUDIENCE_LABELS: Record<string, string> = {
  active_clients: 'Active clients',
  all_clients: 'All clients',
  leads: 'Leads',
  custom: 'Custom list',
}

export default function CrmNewslettersPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<CrmNewsletter | null>(null)
  const [form, setForm] = useState({ subject: '', body: '', audience: 'active_clients', custom: '' })
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'newsletters', page],
    queryFn: () => crm.newsletters.list(page),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'newsletters'] })

  const openCreate = () => {
    setEditing(null)
    setForm({ subject: '', body: '', audience: 'active_clients', custom: '' })
    setError(null)
    setShowForm(true)
  }

  const openEdit = (n: CrmNewsletter) => {
    setEditing(n)
    setForm({
      subject: n.subject,
      body: n.body,
      audience: n.audience,
      custom: (n.custom_recipients ?? []).join('\n'),
    })
    setError(null)
    setShowForm(true)
  }

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = {
        subject: form.subject,
        body: form.body,
        audience: form.audience,
        custom_recipients: form.audience === 'custom'
          ? form.custom.split(/[\n,;]+/).map((e) => e.trim()).filter(Boolean)
          : null,
      }
      return editing ? crm.newsletters.update(editing.uuid, payload) : crm.newsletters.create(payload)
    },
    onSuccess: () => {
      refresh()
      setShowForm(false)
      toast('Draft saved.', 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const sendMutation = useMutation({
    mutationFn: (uuid: string) => crm.newsletters.send(uuid),
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => crm.newsletters.remove(uuid),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  const audienceCount = (a: string) => data?.audiences?.[a]

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Newsletters</h1>
          <p className="text-sm text-slate-500">
            {data ? <>Audiences: {data.audiences.active_clients} active clients · {data.audiences.leads} leads with email</> : 'Email your clients and leads.'}
          </p>
        </div>
        <Button onClick={openCreate}><Plus className="size-4" /> New newsletter</Button>
      </div>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No newsletters yet" hint="Draft the first mailer for your clients." />
        ) : (
          <div className="space-y-2">
            {data.data.map((n) => (
              <div key={n.uuid} className="flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
                <Mail className={clsx('size-5 shrink-0', n.status === 'sent' ? 'text-emerald-500' : 'text-slate-400')} />
                <div className="min-w-0 flex-1">
                  <div className="truncate font-medium text-slate-800 dark:text-slate-100">{n.subject}</div>
                  <div className="text-xs text-slate-400">
                    {AUDIENCE_LABELS[n.audience]}
                    {n.status === 'sent'
                      ? <> · sent {n.sent_at?.slice(0, 16)} to {n.sent_count} recipients{n.failed_count > 0 && <span className="text-red-400"> ({n.failed_count} failed)</span>}</>
                      : <> · draft by {n.created_by ?? '—'}</>}
                  </div>
                </div>
                <span className={clsx(
                  'rounded-full px-2 py-0.5 text-[11px] font-medium',
                  n.status === 'sent'
                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                    : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
                )}>
                  {n.status}
                </span>
                {n.status === 'draft' && (
                  <>
                    <Button size="sm" variant="secondary" onClick={() => openEdit(n)}>
                      <Pencil className="size-3.5" /> Edit
                    </Button>
                    <Button
                      size="sm"
                      onClick={() => {
                        const count = n.audience === 'custom' ? (n.custom_recipients?.length ?? 0) : audienceCount(n.audience)
                        if (confirm(`Send "${n.subject}" to ~${count ?? '?'} recipients now?`)) sendMutation.mutate(n.uuid)
                      }}
                      disabled={sendMutation.isPending}
                    >
                      <Send className="size-3.5" /> Send
                    </Button>
                    <button onClick={() => { if (confirm('Delete this draft?')) deleteMutation.mutate(n.uuid) }} aria-label="Delete" className="rounded p-1.5 text-slate-400 hover:text-red-500">
                      <Trash2 className="size-4" />
                    </button>
                  </>
                )}
              </div>
            ))}
          </div>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>

      {showForm && (
        <Modal title={editing ? 'Edit draft' : 'New newsletter'} onClose={() => setShowForm(false)} wide>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div>
              <Label>Subject</Label>
              <Input value={form.subject} onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))} className="w-full" />
            </div>
            <div>
              <Label>Audience</Label>
              <Select value={form.audience} onChange={(e) => setForm((f) => ({ ...f, audience: e.target.value }))} className="w-full">
                {Object.entries(AUDIENCE_LABELS).map(([v, l]) => (
                  <option key={v} value={v}>
                    {l}{audienceCount(v) !== undefined ? ` (${audienceCount(v)})` : ''}
                  </option>
                ))}
              </Select>
            </div>
            {form.audience === 'custom' && (
              <div>
                <Label>Recipients (one per line or comma-separated)</Label>
                <Textarea rows={3} value={form.custom} onChange={(e) => setForm((f) => ({ ...f, custom: e.target.value }))} className="w-full" />
              </div>
            )}
            <div>
              <Label>Body (HTML supported)</Label>
              <Textarea rows={10} value={form.body} onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))} placeholder="<h1>Hello</h1><p>Our new plans are live…</p>" className="w-full font-mono text-xs" />
            </div>
            {form.body && (
              <div>
                <Label>Preview</Label>
                <div className="max-h-48 overflow-y-auto rounded-xl bg-white p-3 text-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700" dangerouslySetInnerHTML={{ __html: form.body }} />
              </div>
            )}
            <Button className="w-full" disabled={!form.subject || !form.body || saveMutation.isPending} onClick={() => saveMutation.mutate()}>
              {saveMutation.isPending ? 'Saving…' : 'Save draft'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}
