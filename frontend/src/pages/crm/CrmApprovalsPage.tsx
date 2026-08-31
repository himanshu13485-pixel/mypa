import { useState } from 'react'
import { Link, useOutletContext } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarOff, Check, CheckSquare, ClipboardCheck, FileDiff, Plus, Users, X, Search } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmCan, type CrmMe } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner, Textarea } from '../../components/ui'
import { CHART_COLORS, DonutChart, HBarChart } from './charts'
import { decisionBadge } from './CrmLeavesPage'

const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })

export default function CrmApprovalsPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  const decides = crmCan(me, 'approvals', 'edit')
  const decidesInvoices = crmCan(me, 'invoices', 'edit')
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [tab, setTab] = useState<'register' | 'invoice_updates'>('register')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ type: '', scope: 'general', approval_date: new Date().toISOString().slice(0, 10), amount: '', invoice_uuid: '', client_uuid: '', details: '' })
  // What this member may point a request at: their own sales, nobody else's.
  const [optionSearch, setOptionSearch] = useState('')
  const [error, setError] = useState<string | null>(null)

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'approvals', status, page],
    queryFn: () => crm.approvals.list({ status: status || undefined, page }),
  })
  const { data: updates } = useQuery({
    queryKey: ['crm', 'invoice-updates', tab],
    queryFn: () => crm.approvals.invoiceUpdates({}),
    enabled: tab === 'invoice_updates',
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'approvals'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'badges'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'invoice-updates'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'invoices'] })
  }

  const { data: options } = useQuery({
    queryKey: ['crm', 'approval-options', optionSearch],
    queryFn: () => crm.approvals.options(optionSearch || undefined),
    enabled: showForm,
  })

  const createMutation = useMutation({
    mutationFn: () =>
      crm.approvals.create({
        type: form.type,
        approval_date: form.approval_date,
        amount: form.amount ? Number(form.amount) : 0,
        scope: form.scope,
        invoice_uuid: form.scope === 'invoice' ? form.invoice_uuid || null : null,
        client_uuid: form.scope === 'invoice' && !form.invoice_uuid ? form.client_uuid || null : null,
        details: form.details || null,
      }),
    onSuccess: () => {
      refresh()
      setShowForm(false)
      setForm({ type: '', scope: 'general', approval_date: new Date().toISOString().slice(0, 10), amount: '', invoice_uuid: '', client_uuid: '', details: '' })
      toast('Approval requested.', 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const decideMutation = useMutation({
    mutationFn: ({ uuid, verdict }: { uuid: string; verdict: 'approved' | 'rejected' }) => {
      const note = prompt(verdict === 'approved' ? 'Note (optional)' : 'Why rejected?') ?? undefined
      return crm.approvals.decide(uuid, verdict, note)
    },
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  const decideUpdateMutation = useMutation({
    mutationFn: ({ uuid, verdict }: { uuid: string; verdict: 'approved' | 'rejected' }) =>
      crm.approvals.decideInvoiceUpdate(uuid, verdict),
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const inbox = data?.inbox

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Approvals</h1>
          <p className="text-sm text-slate-500">One screen for everything waiting on a decision.</p>
        </div>
        <Button onClick={() => { setError(null); setShowForm(true) }}>
          <Plus className="size-4" /> Request approval
        </Button>
      </div>

      {/* The inbox strip: what needs me, across modules */}
      {inbox && (inbox.leaves !== null || inbox.tasks !== null || inbox.invoice_updates !== null || inbox.client_access !== null) && (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {inbox.leaves !== null && (
            <Link to="/crm/leaves?status=pending" className="flex items-center gap-3 rounded-2xl bg-white p-4 shadow-card ring-1 ring-slate-900/5 transition-transform hover:scale-[1.01] dark:bg-slate-900 dark:ring-white/10">
              <CalendarOff className="size-6 text-purple-500" />
              <div>
                <div className="text-lg font-semibold text-slate-900 dark:text-white">{inbox.leaves}</div>
                <div className="text-xs text-slate-500">Leave requests pending</div>
              </div>
            </Link>
          )}
          {inbox.tasks !== null && (
            <Link to="/crm/tasks?status=submitted" className="flex items-center gap-3 rounded-2xl bg-white p-4 shadow-card ring-1 ring-slate-900/5 transition-transform hover:scale-[1.01] dark:bg-slate-900 dark:ring-white/10">
              <CheckSquare className="size-6 text-sky-500" />
              <div>
                <div className="text-lg font-semibold text-slate-900 dark:text-white">{inbox.tasks}</div>
                <div className="text-xs text-slate-500">Tasks awaiting approval</div>
              </div>
            </Link>
          )}
          {inbox.invoice_updates !== null && (
            <button onClick={() => setTab('invoice_updates')} className="flex items-center gap-3 rounded-2xl bg-white p-4 text-left shadow-card ring-1 ring-slate-900/5 transition-transform hover:scale-[1.01] dark:bg-slate-900 dark:ring-white/10">
              <FileDiff className="size-6 text-amber-500" />
              <div>
                <div className="text-lg font-semibold text-slate-900 dark:text-white">{inbox.invoice_updates}</div>
                <div className="text-xs text-slate-500">Invoice updates pending</div>
              </div>
            </button>
          )}
          {inbox.client_access !== null && (
            <Link to="/crm/clients?tab=requests" className="flex items-center gap-3 rounded-2xl bg-white p-4 shadow-card ring-1 ring-slate-900/5 transition-transform hover:scale-[1.01] dark:bg-slate-900 dark:ring-white/10">
              <Users className="size-6 text-emerald-500" />
              <div>
                <div className="text-lg font-semibold text-slate-900 dark:text-white">{inbox.client_access}</div>
                <div className="text-xs text-slate-500">Client access requests</div>
              </div>
            </Link>
          )}
        </div>
      )}

      {data && data.summary.by_type.length > 0 && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Requests by type</h2>
            <HBarChart data={data.summary.by_type.map((t) => ({ label: t.type, value: t.count }))} color={CHART_COLORS[1]} />
          </Card>
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Decisions</h2>
            <DonutChart
              data={data.summary.by_status.map((s) => ({
                label: s.status,
                value: s.count,
                color: { pending: CHART_COLORS[2], approved: CHART_COLORS[0], rejected: CHART_COLORS[4] }[s.status],
              }))}
              centerLabel="requests"
            />
          </Card>
        </div>
      )}

      <Card>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <div className="mr-auto flex gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
            {([['register', 'Approval register'], ['invoice_updates', 'Invoice updates']] as const).map(([key, label]) => (
              <button
                key={key}
                onClick={() => setTab(key)}
                className={tab === key
                  ? 'rounded-lg bg-white px-3 py-1.5 text-xs font-medium shadow-sm dark:bg-slate-700'
                  : 'rounded-lg px-3 py-1.5 text-xs font-medium text-slate-500'}
              >
                {label}
              </button>
            ))}
          </div>
          {tab === 'register' && (
            <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
              <option value="">All statuses</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </Select>
          )}
        </div>

        {tab === 'register' ? (
          isLoading ? (
            <div className="flex justify-center py-16"><Spinner /></div>
          ) : !data || data.data.length === 0 ? (
            <EmptyState title="No approval requests" hint="Money and error approvals appear here." />
          ) : (
            <>
              <div className="-mx-4 overflow-x-auto px-4">
                <table className="w-full min-w-[820px] text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                      <th className="py-2 pr-3 font-medium">Date</th>
                      <th className="py-2 pr-3 font-medium">Type</th>
                      <th className="py-2 pr-3 font-medium">Details</th>
                      <th className="py-2 pr-3 font-medium">Invoice</th>
                      <th className="py-2 pr-3 text-right font-medium">Amount</th>
                      <th className="py-2 pr-3 font-medium">Requested by</th>
                      <th className="py-2 pr-3 font-medium">Status</th>
                      <th className="py-2 font-medium" />
                    </tr>
                  </thead>
                  <tbody>
                    {data.data.map((a) => (
                      <tr key={a.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                        <td className="whitespace-nowrap py-2.5 pr-3">{a.approval_date}</td>
                        <td className="py-2.5 pr-3">{a.type}</td>
                        <td className="max-w-[240px] truncate py-2.5 pr-3 text-slate-500" title={a.details ?? ''}>{a.details ?? '—'}</td>
                        <td className="py-2.5 pr-3">
                          {/* Whoever decides should read a name, not a number. */}
                          {a.invoice ? (
                            <Link to={`/crm/invoices/${a.invoice.uuid}`} className="font-medium text-emerald-600 hover:underline">{a.invoice.number}</Link>
                          ) : a.client ? (
                            <Link to={`/crm/clients/${a.client.uuid}`} className="font-medium text-emerald-600 hover:underline">{a.client.company_name}</Link>
                          ) : (
                            <span className="text-xs text-slate-400">General</span>
                          )}
                          {a.invoice && a.client && (
                            <div className="truncate text-xs text-slate-400">{a.client.company_name}</div>
                          )}
                        </td>
                        <td className="whitespace-nowrap py-2.5 pr-3 text-right font-medium">{Number(a.amount) ? inr(a.amount) : '—'}</td>
                        <td className="py-2.5 pr-3">{a.requested_by ?? '—'}</td>
                        <td className="py-2.5 pr-3">
                          <span className={decisionBadge(a.status)} title={a.decision_note ?? ''}>
                            {a.status}{a.decided_by && ` · ${a.decided_by}`}
                          </span>
                        </td>
                        <td className="py-2.5 text-right">
                          {a.status === 'pending' && decides && (
                            <div className="flex justify-end gap-1">
                              <Button size="sm" onClick={() => decideMutation.mutate({ uuid: a.uuid, verdict: 'approved' })}>
                                <Check className="size-3.5" />
                              </Button>
                              <Button size="sm" variant="secondary" onClick={() => decideMutation.mutate({ uuid: a.uuid, verdict: 'rejected' })}>
                                <X className="size-3.5" />
                              </Button>
                            </div>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <Pager resp={data} onPage={setPage} />
            </>
          )
        ) : (
          !updates || updates.data.length === 0 ? (
            <EmptyState title="No invoice update requests" hint="Propose a change from any invoice's page." />
          ) : (
            <div className="space-y-2">
              {updates.data.map((u) => (
                <div key={u.uuid} className="rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
                  <div className="flex flex-wrap items-center gap-2">
                    <ClipboardCheck className="size-4 shrink-0 text-amber-500" />
                    {u.invoice && (
                      <Link to={`/crm/invoices/${u.invoice.uuid}`} className="font-medium text-emerald-600 hover:underline">{u.invoice.number}</Link>
                    )}
                    <span className="text-xs text-slate-400">by {u.requested_by ?? '—'} · {u.created_at?.slice(0, 16)}</span>
                    <span className={decisionBadge(u.status)}>{u.status}</span>
                    {u.status === 'pending' && decidesInvoices && (
                      <div className="ml-auto flex gap-1">
                        <Button size="sm" onClick={() => decideUpdateMutation.mutate({ uuid: u.uuid, verdict: 'approved' })}>
                          <Check className="size-3.5" /> Apply
                        </Button>
                        <Button size="sm" variant="secondary" onClick={() => decideUpdateMutation.mutate({ uuid: u.uuid, verdict: 'rejected' })}>
                          <X className="size-3.5" /> Reject
                        </Button>
                      </div>
                    )}
                  </div>
                  <p className="mt-1 text-xs text-slate-500">
                    {Object.entries(u.changes).map(([field, value]) => (
                      <span key={field} className="mr-3 inline-block">{field.replace(/_/g, ' ')} → <span className="font-medium text-slate-700 dark:text-slate-200">{String(value ?? '—')}</span></span>
                    ))}
                  </p>
                  {u.reason && <p className="mt-0.5 text-xs text-slate-400">"{u.reason}"</p>}
                </div>
              ))}
            </div>
          )
        )}
      </Card>

      {showForm && (
        <Modal title="Request approval" onClose={() => setShowForm(false)}>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div>
              <Label>What is this about?</Label>
              <Select value={form.type} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))} className="w-full">
                <option value="">Select</option>
                {(options?.types ?? masters?.approval_types ?? []).map((t) => <option key={t} value={t}>{t}</option>)}
              </Select>
            </div>

            {/* Two shapes of request: one about a document, one about the
                office's own money. The first must name what it concerns. */}
            <div className="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm dark:bg-slate-800/60">
              {([['invoice', 'About an invoice or client'], ['general', 'General (recharge, claim…)']] as const).map(([key, label]) => (
                <button
                  key={key}
                  onClick={() => setForm((f) => ({ ...f, scope: key, invoice_uuid: '', client_uuid: '' }))}
                  className={clsx(
                    'flex-1 rounded-lg px-3 py-1.5 font-medium transition',
                    form.scope === key
                      ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white'
                      : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300',
                  )}
                >
                  {label}
                </button>
              ))}
            </div>

            {form.scope === 'invoice' && (
              <div className="space-y-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
                <div className="relative">
                  <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                  <Input
                    value={optionSearch}
                    onChange={(e) => setOptionSearch(e.target.value)}
                    placeholder="Find your invoice or client…"
                    className="w-full pl-9"
                  />
                </div>
                <div>
                  <Label>Invoice</Label>
                  <Select
                    value={form.invoice_uuid}
                    onChange={(e) => setForm((f) => ({ ...f, invoice_uuid: e.target.value, client_uuid: '' }))}
                    className="w-full"
                  >
                    <option value="">Not about one particular invoice</option>
                    {(options?.invoices ?? []).map((i) => (
                      <option key={i.uuid} value={i.uuid}>
                        {i.number} — {i.client ?? 'no client'} · ₹{Number(i.total).toLocaleString('en-IN')}
                      </option>
                    ))}
                  </Select>
                </div>
                {!form.invoice_uuid && (
                  <div>
                    <Label>Client</Label>
                    <Select
                      value={form.client_uuid}
                      onChange={(e) => setForm((f) => ({ ...f, client_uuid: e.target.value }))}
                      className="w-full"
                    >
                      <option value="">Select the client</option>
                      {(options?.clients ?? []).map((c) => (
                        <option key={c.uuid} value={c.uuid}>{c.company_name}</option>
                      ))}
                    </Select>
                    <p className="mt-1 text-xs text-slate-400">
                      Only your own invoices and clients are listed. Name at least one so whoever decides
                      knows what this is about.
                    </p>
                  </div>
                )}
              </div>
            )}
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Date</Label>
                <Input type="date" value={form.approval_date} onChange={(e) => setForm((f) => ({ ...f, approval_date: e.target.value }))} className="w-full" />
              </div>
              <div>
                <Label>Amount (₹)</Label>
                <Input type="number" min="0" value={form.amount} onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value }))} className="w-full" />
              </div>
            </div>
            <div>
              <Label>Details</Label>
              <Textarea rows={3} value={form.details} onChange={(e) => setForm((f) => ({ ...f, details: e.target.value }))} className="w-full" />
            </div>
            <Button
              className="w-full"
              disabled={
                !form.type
                || (form.scope === 'invoice' && !form.invoice_uuid && !form.client_uuid)
                || createMutation.isPending
              }
              onClick={() => createMutation.mutate()}
            >
              {createMutation.isPending ? 'Requesting…' : 'Submit request'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}
