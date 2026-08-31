import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlarmClock, ArrowRightLeft, Banknote, Check, Link2, Link2Off, Mail, Phone, Plus, Search, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmOutstandingRow, type CrmOutstandingSummary, type CrmPaymentEntry } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner, Textarea } from '../../components/ui'
import { CHART_COLORS, ColumnChart, DonutChart } from './charts'

const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })

const EMPTY = {
  received_on: new Date().toISOString().slice(0, 10),
  issuing_company_id: '', bank_account_id: '', payment_mode: '',
  amount: '', details: '', reference_no: '', note: '',
}

export default function CrmPaymentsPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  // Two halves of the same job: money that landed, and money still owed.
  const params = new URLSearchParams(window.location.search)
  const [tab, setTab] = useState<'inbox' | 'outstanding'>(
    params.get('tab') === 'outstanding' ? 'outstanding' : 'inbox',
  )
  const [bucket, setBucket] = useState('')
  // One person's money out of either ledger.
  const [owedMember, setOwedMember] = useState('')
  const [inboxMember, setInboxMember] = useState('')
  const [owedSearch, setOwedSearch] = useState(params.get('invoice') ?? '')
  const [chasing, setChasing] = useState<CrmOutstandingRow | null>(null)
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<CrmPaymentEntry | null>(null)
  const [claiming, setClaiming] = useState<CrmPaymentEntry | null>(null)
  const [moving, setMoving] = useState(false)
  const [settling, setSettling] = useState<CrmPaymentEntry | null>(null)
  const [form, setForm] = useState({ ...EMPTY })
  const [error, setError] = useState<string | null>(null)

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data: me } = useQuery({ queryKey: ['crm', 'me'], queryFn: crm.me })
  // Settling and correcting belong to the Company Admin and Subadmin.
  const isManager = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  const settleMutation = useMutation({
    mutationFn: ({ uuid, charge, note }: { uuid: string; charge?: number; note?: string }) =>
      crm.payments.settle(uuid, charge ? { charge_amount: charge, charge_note: note ?? null } : undefined),
    onSuccess: (res) => { toast(res.message, 'success'); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const { data: owed, isLoading: owedLoading } = useQuery({
    queryKey: ['crm', 'outstanding', bucket, owedSearch, owedMember],
    queryFn: () => crm.payments.outstanding({ bucket: bucket || undefined, search: owedSearch || undefined, member: owedMember || undefined }),
    enabled: tab === 'outstanding',
  })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'payments', status, applied, dateFrom, dateTo, inboxMember, page],
    queryFn: () =>
      crm.payments.list({
        status: status || undefined,
        member: inboxMember || undefined,
        search: applied || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        page,
      }),
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'payments'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'invoices'] })
  }

  const openCreate = () => {
    setEditing(null)
    setForm({ ...EMPTY, received_on: new Date().toISOString().slice(0, 10) })
    setError(null)
    setShowForm(true)
  }

  const openEdit = (e: CrmPaymentEntry) => {
    setEditing(e)
    setForm({
      received_on: e.received_on,
      issuing_company_id: e.issuing_company_id ? String(e.issuing_company_id) : '',
      bank_account_id: e.bank_account_id ? String(e.bank_account_id) : '',
      payment_mode: e.payment_mode ?? '',
      amount: String(Number(e.amount)),
      details: e.details ?? '',
      reference_no: e.reference_no ?? '',
      note: e.note ?? '',
    })
    setError(null)
    setShowForm(true)
  }

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = {
        received_on: form.received_on,
        issuing_company_id: form.issuing_company_id ? Number(form.issuing_company_id) : null,
        bank_account_id: form.bank_account_id ? Number(form.bank_account_id) : null,
        payment_mode: form.payment_mode || null,
        amount: Number(form.amount),
        details: form.details || null,
        reference_no: form.reference_no || null,
        note: form.note || null,
      }
      return editing ? crm.payments.update(editing.uuid, payload) : crm.payments.create(payload)
    },
    onSuccess: () => {
      refresh()
      setShowForm(false)
      toast('Payment saved.', 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const unclaimMutation = useMutation({
    mutationFn: (uuid: string) => crm.payments.unclaim(uuid),
    onSuccess: (res) => { refresh(); toast(res.message, 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => crm.payments.remove(uuid),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  const set = (key: keyof typeof EMPTY, value: string) => setForm((f) => ({ ...f, [key]: value }))

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Payments</h1>
          <p className="text-sm text-slate-500">Every credit that lands, logged first — then claimed against an invoice.</p>
        </div>
        <Button onClick={openCreate}><Plus className="size-4" /> Log payment</Button>
      </div>

      {/* Two halves of one job: money that landed, and money still owed. */}
      <div className="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm dark:bg-slate-800/60">
        {([['inbox', 'Bank inbox'], ['outstanding', 'Outstanding']] as const).map(([key, label]) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={clsx(
              'flex items-center gap-2 rounded-lg px-3 py-1.5 font-medium transition',
              tab === key
                ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white'
                : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300',
            )}
          >
            {label}
            {key === 'outstanding' && (owed?.summary.due_for_follow_up ?? 0) > 0 && (
              <span className="rounded-full bg-amber-100 px-1.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">
                {owed!.summary.due_for_follow_up}
              </span>
            )}
          </button>
        ))}
      </div>

      {tab === 'outstanding' ? (
        <OutstandingLedger
          data={owed}
          isLoading={owedLoading}
          bucket={bucket}
          member={owedMember}
          onMember={setOwedMember}
          members={(masters?.members ?? []).filter((m) => (m.crm_role ?? 'employee') !== 'admin')}
          onBucket={setBucket}
          search={owedSearch}
          onSearch={setOwedSearch}
          onChase={setChasing}
        />
      ) : (
      <>

      {data && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[
            { label: 'Unclaimed', value: inr(data.summary.unclaimed_amount), sub: `${data.summary.unclaimed_count} entries`, alert: data.summary.unclaimed_count > 0 },
            { label: 'Claimed', value: inr(data.summary.claimed_amount), sub: 'allocated to invoices' },
            { label: 'Total in range', value: inr(data.summary.total_amount), sub: 'all entries' },
            { label: 'Top mode', value: data.summary.by_mode[0]?.mode ?? '—', sub: data.summary.by_mode[0] ? inr(data.summary.by_mode[0].amount) : '' },
          ].map((s) => (
            <Card key={s.label} className="py-3">
              <div className={clsx('text-lg font-semibold', s.alert ? 'text-amber-600' : 'text-slate-900 dark:text-white')}>{s.value}</div>
              <div className="text-xs font-medium text-slate-600 dark:text-slate-300">{s.label}</div>
              <div className="text-xs text-slate-400">{s.sub}</div>
            </Card>
          ))}
        </div>
      )}

      {data && data.summary.by_mode.length > 0 && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Received by mode</h2>
            <DonutChart
              data={data.summary.by_mode.slice(0, 5).map((m, i) => ({ label: m.mode, value: m.amount, color: CHART_COLORS[i % CHART_COLORS.length] }))}
              centerLabel="₹ received"
            />
          </Card>
          <Card>
            <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Received by month</h2>
            <ColumnChart data={data.summary.by_month.map((m) => ({ label: m.month.slice(2), value: m.amount }))} color={CHART_COLORS[0]} />
          </Card>
        </div>
      )}

      <Card>
        <form
          className="mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-[240px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Details, reference, invoice…" className="w-full pl-9" />
          </div>
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">All</option>
            <option value="unclaimed">Unclaimed</option>
            <option value="claimed">Claimed</option>
          </Select>
          <Select value={inboxMember} onChange={(e) => { setInboxMember(e.target.value); setPage(1) }} title="Whose money">
            <option value="">Any salesperson</option>
            {(masters?.members ?? []).filter((m) => (m.crm_role ?? 'employee') !== 'admin')
              .map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
          </Select>
          <Input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1) }} aria-label="From" />
          <Input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1) }} aria-label="To" />
          <Button type="submit" variant="secondary" size="sm">Search</Button>
        </form>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No payments logged" hint="Incoming credits appear here once logged." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[820px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Received</th>
                  <th className="py-2 pr-3 font-medium">Details</th>
                  <th className="py-2 pr-3 font-medium">Mode</th>
                  <th className="py-2 pr-3 text-right font-medium">Amount</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {data.data.map((e) => (
                  <tr key={e.uuid} className={clsx(
                    'border-b border-slate-50 last:border-0 dark:border-slate-800/50',
                    e.status === 'unclaimed' && 'bg-amber-50/40 dark:bg-amber-500/5',
                  )}>
                    <td className="whitespace-nowrap py-2.5 pr-3">{e.received_on}</td>
                    <td className="max-w-[320px] py-2.5 pr-3">
                      <button onClick={() => e.status === 'unclaimed' && openEdit(e)} className="block max-w-full truncate text-left" title={e.details ?? ''}>
                        {e.details || e.reference_no || '—'}
                      </button>
                      <div className="text-xs text-slate-400">{[e.bank_account, e.issuing_company].filter(Boolean).join(' · ')}</div>
                    </td>
                    <td className="py-2.5 pr-3">{e.payment_mode ?? '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right font-medium">{inr(e.amount)}</td>
                    <td className="py-2.5 pr-3">
                      {e.claimed_invoice ? (
                        <span className="text-xs">
                          <Link to={`/crm/invoices/${e.claimed_invoice.uuid}`} className="font-medium text-emerald-600 hover:underline">
                            {e.claimed_invoice.number}
                          </Link>
                          {/* Money in against a proforma keeps saying so. */}
                          {e.from_proforma && <span className="text-slate-400"> · from {e.from_proforma}</span>}
                          {e.claimed_member && <span className="text-slate-400"> · {e.claimed_member}</span>}
                          {e.status === 'pending' && (
                            <span className="ml-1.5 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">
                              Waiting to be settled
                            </span>
                          )}
                        </span>
                      ) : (
                        <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">
                          Unclaimed
                        </span>
                      )}
                    </td>
                    <td className="py-2.5 text-right">
                      {e.status === 'unclaimed' ? (
                        <div className="flex justify-end gap-1">
                          <Button size="sm" onClick={() => { setMoving(false); setClaiming(e) }}>
                            <Link2 className="size-3.5" /> Match
                          </Button>
                          <button onClick={() => { if (confirm('Delete this payment entry?')) deleteMutation.mutate(e.uuid) }} aria-label="Delete" className="rounded p-1.5 text-slate-400 hover:text-red-500">
                            <Trash2 className="size-4" />
                          </button>
                        </div>
                      ) : (
                        <div className="flex flex-wrap justify-end gap-1">
                          {/* Settling is the Admin's; matching was anyone's. */}
                          {e.status === 'pending' && isManager && (
                            <Button size="sm" onClick={() => setSettling(e)} disabled={settleMutation.isPending}>
                              <Check className="size-3.5" /> Settle
                            </Button>
                          )}
                          {isManager && (
                            <Button size="sm" variant="secondary" onClick={() => { setMoving(true); setClaiming(e) }}>
                              <ArrowRightLeft className="size-3.5" /> Change
                            </Button>
                          )}
                          {(isManager || e.status === 'pending') && (
                            <Button
                              size="sm"
                              variant="secondary"
                              onClick={() => {
                                const msg = e.status === 'claimed'
                                  ? 'Undo this settlement? The receipt is removed from the invoice.'
                                  : 'Withdraw this match?'
                                if (confirm(msg)) unclaimMutation.mutate(e.uuid)
                              }}
                            >
                              <Link2Off className="size-3.5" /> {e.status === 'claimed' ? 'Undo' : 'Withdraw'}
                            </Button>
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>

      </>
      )}

      {chasing && (
        <ReminderModal
          row={chasing}
          onClose={() => setChasing(null)}
          onDone={() => {
            queryClient.invalidateQueries({ queryKey: ['crm', 'outstanding'] })
            setChasing(null)
          }}
        />
      )}

      {showForm && (
        <Modal title={editing ? 'Edit payment' : 'Log payment'} onClose={() => setShowForm(false)} wide>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <Label>Received on</Label>
                <Input type="date" value={form.received_on} onChange={(e) => set('received_on', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Amount (₹)</Label>
                <Input type="number" min="0" step="0.01" value={form.amount} onChange={(e) => set('amount', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Into bank account</Label>
                <Select value={form.bank_account_id} onChange={(e) => set('bank_account_id', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {masters?.bank_accounts.map((b) => <option key={b.id} value={b.id}>{b.label}</option>)}
                </Select>
              </div>
              <div>
                <Label>For company</Label>
                <Select value={form.issuing_company_id} onChange={(e) => set('issuing_company_id', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {masters?.issuing_companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </Select>
              </div>
              <div>
                <Label>Mode</Label>
                <Select value={form.payment_mode} onChange={(e) => set('payment_mode', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {masters?.payment_modes.map((m) => <option key={m} value={m}>{m}</option>)}
                </Select>
              </div>
              <div>
                <Label>Reference no.</Label>
                <Input value={form.reference_no} onChange={(e) => set('reference_no', e.target.value)} className="w-full" />
              </div>
              <div className="sm:col-span-2">
                <Label>Payment details (raw bank/PG line)</Label>
                <Textarea rows={2} value={form.details} onChange={(e) => set('details', e.target.value)} placeholder="NEFT Cr-HSBC0400002-THE BOSTON CONSULTING GROUP…" className="w-full" />
              </div>
              <div className="sm:col-span-2">
                <Label>Note</Label>
                <Input value={form.note} onChange={(e) => set('note', e.target.value)} className="w-full" />
              </div>
            </div>
            <Button className="w-full" disabled={!form.amount || !form.received_on || saveMutation.isPending} onClick={() => saveMutation.mutate()}>
              {saveMutation.isPending ? 'Saving…' : 'Save payment'}
            </Button>
          </div>
        </Modal>
      )}

      {settling && (
        <SettleModal
          entry={settling}
          pending={settleMutation.isPending}
          onClose={() => setSettling(null)}
          onSettle={(charge, note) => {
            settleMutation.mutate({ uuid: settling.uuid, charge, note })
            setSettling(null)
          }}
        />
      )}

      {claiming && (
        <ClaimModal
          entry={claiming}
          isManager={isManager}
          defaultMode={data?.summary.settlement_mode ?? 'manual'}
          moving={moving}
          onClose={() => setClaiming(null)}
          onDone={() => { setClaiming(null); refresh() }}
        />
      )}
    </div>
  )
}

function ClaimModal({ entry, isManager, defaultMode, moving, onClose, onDone }: {
  entry: CrmPaymentEntry
  isManager: boolean
  defaultMode: 'auto' | 'manual'
  moving?: boolean
  onClose: () => void
  onDone: () => void
}) {
  const { toast, toastError } = useToast()
  const [search, setSearch] = useState('')
  const [invoiceUuid, setInvoiceUuid] = useState('')
  // Money usually arrives against a proforma, so both kinds are on offer.
  const [kind, setKind] = useState<'proforma' | 'invoice'>('invoice')
  const [mode, setMode] = useState<'auto' | 'manual'>(isManager ? defaultMode : 'manual')
  const [reason, setReason] = useState('')

  const { data: invoices } = useQuery({
    queryKey: ['crm', 'claim-invoices', kind, search],
    queryFn: () => crm.invoices.list({ kind, search: search || undefined, payment_status: undefined }),
  })

  const claimMutation = useMutation({
    mutationFn: () => (moving
      ? crm.payments.reclaim(entry.uuid, { invoice_uuid: invoiceUuid, reason: reason || undefined })
      : crm.payments.claim(entry.uuid, { invoice_uuid: invoiceUuid, mode })),
    onSuccess: (res) => { toast(res.message, 'success'); onDone() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const candidates = invoices?.data.filter((i) => i.status !== 'cancelled'
    && (kind === 'proforma' ? !i.converted_to_doc : ['due', 'partial'].includes(i.payment_status))) ?? []

  return (
    <Modal
      title={`${moving ? 'Move' : 'Match'} ${'₹' + Number(entry.amount).toLocaleString('en-IN')}`}
      onClose={onClose}
      wide
    >
      <div className="space-y-3">
        <p className="text-sm text-slate-500">
          <Banknote className="mr-1 inline size-4 text-emerald-500" />
          {entry.details || entry.reference_no || 'This payment'} · received {entry.received_on}
          {moving && entry.claimed_invoice && <> · currently on {entry.claimed_invoice.number}</>}
        </p>

        <div className="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm dark:bg-slate-800/60">
          {([['proforma', 'Against a proforma'], ['invoice', 'Against an invoice']] as const).map(([key, label]) => (
            <button
              key={key}
              onClick={() => { setKind(key); setInvoiceUuid('') }}
              className={clsx(
                'flex-1 rounded-lg px-3 py-1.5 font-medium transition',
                kind === key
                  ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white'
                  : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300',
              )}
            >
              {label}
            </button>
          ))}
        </div>

        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search invoice number or client…" className="w-full pl-9" />
        </div>
        <div className="max-h-64 space-y-1 overflow-y-auto">
          {candidates.length === 0 ? (
            <p className="py-4 text-center text-sm text-slate-400">No open invoices match.</p>
          ) : candidates.map((i) => (
            <label key={i.uuid} className={clsx(
              'flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2 ring-1 ring-inset transition-colors',
              invoiceUuid === i.uuid
                ? 'bg-emerald-50 ring-emerald-300 dark:bg-emerald-500/10 dark:ring-emerald-500/40'
                : 'ring-slate-200 hover:bg-slate-50 dark:ring-slate-700 dark:hover:bg-slate-800',
            )}>
              <input type="radio" name="claim-invoice" checked={invoiceUuid === i.uuid} onChange={() => setInvoiceUuid(i.uuid)} className="size-4 accent-emerald-600" />
              <span className="min-w-0 flex-1">
                <span className="font-medium">{i.number}</span>
                <span className="ml-2 text-sm text-slate-500">{i.client?.company_name}</span>
              </span>
              <span className="text-sm font-medium">₹{Number(i.total).toLocaleString('en-IN')}</span>
              <span className="text-xs text-slate-400">{i.payment_status}</span>
            </label>
          ))}
        </div>
        {kind === 'proforma' && invoiceUuid && (
          <p className="rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/60">
            Settling turns this proforma into a tax invoice and pays it — the two stay linked.
          </p>
        )}

        {moving ? (
          <div>
            <Label>Why is it moving? (optional)</Label>
            <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Bank narration named the wrong order" className="w-full" />
          </div>
        ) : isManager ? (
          <div>
            <Label>How to settle</Label>
            <Select value={mode} onChange={(e) => setMode(e.target.value as 'auto' | 'manual')} className="w-full">
              <option value="auto">Settle now — write the receipt straight away</option>
              <option value="manual">Send for confirmation — an Admin checks it first</option>
            </Select>
            <p className="mt-1 text-xs text-slate-400">
              Your company's rule is “{defaultMode === 'auto' ? 'settle now' : 'confirm first'}”.
            </p>
          </div>
        ) : (
          <p className="rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/60">
            This goes to your Company Admin to check and settle.
          </p>
        )}

        <Button className="w-full" disabled={!invoiceUuid || claimMutation.isPending} onClick={() => claimMutation.mutate()}>
          {claimMutation.isPending
            ? 'Saving…'
            : moving ? 'Move the payment'
              : mode === 'auto' ? 'Settle now' : 'Send for confirmation'}
        </Button>
      </div>
    </Modal>
  )
}

/**
 * What is still owed, oldest first, with what has already been said to whom.
 * The figures come from the receipts themselves, so this cannot drift from
 * the invoices.
 */
function OutstandingLedger({ data, isLoading, bucket, onBucket, member, onMember, members, search, onSearch, onChase }: {
  data?: { data: CrmOutstandingRow[]; summary: CrmOutstandingSummary }
  isLoading: boolean
  bucket: string
  onBucket: (value: string) => void
  member: string
  onMember: (value: string) => void
  members: { uuid: string; name: string | null }[]
  search: string
  onSearch: (value: string) => void
  onChase: (row: CrmOutstandingRow) => void
}) {
  const [typed, setTyped] = useState(search)
  const summary = data?.summary

  return (
    <>
      {summary && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[
            { label: 'Outstanding', value: inr(summary.outstanding), sub: `${summary.count} invoices` },
            { label: 'Overdue', value: inr(summary.overdue), sub: 'past their due date', alert: summary.overdue > 0 },
            { label: 'Never chased', value: String(summary.never_chased), sub: 'no reminder sent' },
            { label: 'To follow up', value: String(summary.due_for_follow_up), sub: 'due today or earlier', alert: summary.due_for_follow_up > 0 },
          ].map((s) => (
            <Card key={s.label} className="py-3">
              <div className={clsx('text-lg font-semibold', s.alert ? 'text-amber-600' : 'text-slate-900 dark:text-white')}>{s.value}</div>
              <div className="text-xs font-medium text-slate-600 dark:text-slate-300">{s.label}</div>
              <div className="text-xs text-slate-400">{s.sub}</div>
            </Card>
          ))}
        </div>
      )}

      {summary && summary.outstanding > 0 && (
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">How old the money is</h2>
          <ColumnChart
            data={summary.by_bucket.map((b) => ({ label: b.label, value: b.amount }))}
            color={CHART_COLORS[4]}
          />
        </Card>
      )}

      <Card>
        <form
          className="mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); onSearch(typed) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-[240px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={typed} onChange={(e) => setTyped(e.target.value)} placeholder="Invoice or client…" className="w-full pl-9" />
          </div>
          <Select value={member} onChange={(e) => onMember(e.target.value)} title="Whose dues">
            <option value="">Any salesperson</option>
            {members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
          </Select>
          <Select value={bucket} onChange={(e) => onBucket(e.target.value)}>
            <option value="">Everything owed</option>
            <option value="due_today">Due for follow-up</option>
            {(summary?.by_bucket ?? []).map((b) => (
              <option key={b.key} value={b.key}>{b.label}</option>
            ))}
          </Select>
          <Button type="submit" variant="secondary" size="sm">Search</Button>
        </form>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="Nothing outstanding" hint="Every invoice in this window is settled." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[900px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Invoice</th>
                  <th className="py-2 pr-3 font-medium">Client</th>
                  <th className="py-2 pr-3 font-medium">Salesperson</th>
                  <th className="py-2 pr-3 font-medium">Due</th>
                  <th className="py-2 pr-3 text-right font-medium">Total</th>
                  <th className="py-2 pr-3 text-right font-medium">Received</th>
                  <th className="py-2 pr-3 text-right font-medium">Balance</th>
                  <th className="py-2 pr-3 font-medium">Chased</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {data.data.map((row) => (
                  <tr key={row.uuid} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40">
                    <td className="py-2.5 pr-3">
                      <Link to={`/crm/invoices/${row.uuid}`} className="font-medium text-emerald-600 hover:underline">{row.number}</Link>
                      <div className="text-xs text-slate-400">{row.invoice_date}</div>
                    </td>
                    <td className="max-w-[200px] py-2.5 pr-3">
                      <div className="truncate">{row.client?.company_name ?? '—'}</div>
                      <div className="truncate text-xs text-slate-400">{row.client?.email ?? row.client?.mobile ?? 'no contact on file'}</div>
                    </td>
                    <td className="py-2.5 pr-3">{row.salesperson ?? '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3">
                      <div className="text-slate-500">{row.due_date ?? row.invoice_date}</div>
                      {row.days_overdue > 0 && (
                        <div className="text-xs font-medium text-red-500">{row.days_overdue} days overdue</div>
                      )}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right">{inr(row.total)}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right text-emerald-600">{inr(row.received)}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right font-semibold">{inr(row.balance)}</td>
                    <td className="py-2.5 pr-3">
                      {row.last_reminder ? (
                        <>
                          <div className="whitespace-nowrap text-xs text-slate-500">
                            {row.reminders}× · {row.last_reminder.at.slice(0, 10)}
                          </div>
                          <div className="truncate text-xs text-slate-400">
                            {row.last_reminder.by ?? '—'}
                            {row.last_reminder.status === 'failed' && <span className="text-red-500"> · failed</span>}
                          </div>
                        </>
                      ) : (
                        <span className="text-xs text-slate-300 dark:text-slate-600">never</span>
                      )}
                      {row.next_follow_up && (
                        <div className={clsx(
                          'mt-0.5 inline-flex items-center gap-1 rounded-full px-1.5 text-[11px]',
                          row.next_follow_up <= new Date().toISOString().slice(0, 10)
                            ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'
                            : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
                        )}>
                          <AlarmClock className="size-3" /> {row.next_follow_up}
                        </div>
                      )}
                    </td>
                    <td className="py-2.5 text-right">
                      <Button size="sm" variant="secondary" onClick={() => onChase(row)}>
                        <Mail className="size-3.5" /> Remind
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  )
}

/**
 * Chasing one invoice: a letter the sender can edit, or a note that someone
 * rang — and a date to look again either way.
 */
function ReminderModal({ row, onClose, onDone }: {
  row: CrmOutstandingRow
  onClose: () => void
  onDone: () => void
}) {
  const { toast } = useToast()
  const [channel, setChannel] = useState<'email' | 'note'>('email')
  const [to, setTo] = useState('')
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [note, setNote] = useState('')
  const [followUp, setFollowUp] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [ready, setReady] = useState(false)

  const { data } = useQuery({
    queryKey: ['crm', 'reminders', row.uuid],
    queryFn: () => crm.payments.reminders(row.uuid),
  })

  // The draft is the server's wording; it arrives once and is then the
  // sender's to edit.
  if (data && !ready) {
    setTo(data.draft.to_email ?? '')
    setSubject(data.draft.subject)
    setBody(data.draft.body)
    setReady(true)
  }

  const sendMutation = useMutation({
    mutationFn: () => crm.payments.remind(row.uuid, {
      channel,
      to_email: channel === 'email' ? to : null,
      subject: channel === 'email' ? subject : null,
      body: channel === 'email' ? body : note,
      next_follow_up: followUp || null,
    }),
    onSuccess: (res) => { toast(res.message, 'success'); onDone() },
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={`Chase ${row.number} — ${inr(row.balance)} owed`} onClose={onClose} wide>
      <div className="space-y-3">
        <ErrorNote message={error} />

        <div className="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm dark:bg-slate-800/60">
          {([['email', 'Send an e-mail', Mail], ['note', 'Note a call', Phone]] as const).map(([key, label, Icon]) => (
            <button
              key={key}
              onClick={() => setChannel(key)}
              className={clsx(
                'flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-1.5 font-medium transition',
                channel === key
                  ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white'
                  : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300',
              )}
            >
              <Icon className="size-4" /> {label}
            </button>
          ))}
        </div>

        {channel === 'email' ? (
          <>
            <div>
              <Label>To</Label>
              <Input type="email" value={to} onChange={(e) => setTo(e.target.value)} placeholder="accounts@client.com" className="w-full" />
              {!row.client?.email && (
                <p className="mt-1 text-xs text-amber-600">
                  {row.client?.company_name ?? 'This client'} has no e-mail on file — type one, or note a call instead.
                </p>
              )}
            </div>
            <div>
              <Label>Subject</Label>
              <Input value={subject} onChange={(e) => setSubject(e.target.value)} className="w-full" />
            </div>
            <div>
              <Label>Message</Label>
              <Textarea rows={12} value={body} onChange={(e) => setBody(e.target.value)} className="w-full font-mono text-xs" />
            </div>
          </>
        ) : (
          <div>
            <Label>What happened</Label>
            <Textarea rows={4} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Rang accounts; cheque posted Friday." className="w-full" />
          </div>
        )}

        <div>
          <Label>Look again on (optional)</Label>
          <Input type="date" value={followUp} onChange={(e) => setFollowUp(e.target.value)} className="w-full sm:w-48" />
        </div>

        <Button
          className="w-full"
          disabled={sendMutation.isPending || (channel === 'email' ? !to || !subject : !note)}
          onClick={() => sendMutation.mutate()}
        >
          {sendMutation.isPending ? 'Sending…' : channel === 'email' ? 'Send reminder' : 'Save note'}
        </Button>

        {(data?.data.length ?? 0) > 0 && (
          <div className="border-t border-slate-100 pt-3 dark:border-slate-800">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Already chased</p>
            <ul className="space-y-2">
              {data!.data.map((r) => (
                <li key={r.uuid} className="text-sm">
                  <div className="flex flex-wrap items-baseline gap-x-2">
                    <span className="font-medium text-slate-700 dark:text-slate-200">
                      {r.channel === 'email' ? (r.subject ?? 'E-mail') : 'Call noted'}
                    </span>
                    <span className="text-xs text-slate-400">
                      {[r.by, r.at, r.to_email].filter(Boolean).join(' · ')}
                    </span>
                    {r.status === 'failed' && <span className="text-xs font-medium text-red-500">failed</span>}
                  </div>
                  {r.body && <p className="truncate text-xs text-slate-500">{r.body}</p>}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </Modal>
  )
}

/**
 * Settling a bank credit. The line on the statement is what was LEFT after
 * a gateway took its cut, so this is the moment to say what the cut was —
 * otherwise the invoice sits short by exactly that much for ever.
 */
function SettleModal({ entry, pending, onClose, onSettle }: {
  entry: CrmPaymentEntry
  pending: boolean
  onClose: () => void
  onSettle: (charge: number | undefined, note: string | undefined) => void
}) {
  const [charge, setCharge] = useState('')
  const [note, setNote] = useState('')
  const credited = Number(entry.amount)
  const gross = credited + (Number(charge) || 0)

  return (
    <Modal title="Settle this payment" onClose={onClose}>
      <div className="space-y-3">
        <div className="rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/40">
          <div className="flex justify-between">
            <span className="text-slate-500">Credited to the bank</span>
            <span className="font-semibold">{inr(entry.amount)}</span>
          </div>
          {entry.claimed_invoice && (
            <div className="mt-0.5 text-xs text-slate-400">against {entry.claimed_invoice.number}</div>
          )}
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>Gateway / bank charge (₹)</Label>
            <Input
              type="number"
              min="0"
              step="0.01"
              value={charge}
              onChange={(e) => setCharge(e.target.value)}
              className="w-full"
              placeholder="0"
            />
          </div>
          <div>
            <Label>What the charge was</Label>
            <Input value={note} onChange={(e) => setNote(e.target.value)} className="w-full" placeholder="e.g. Cashfree fee" />
          </div>
        </div>

        <p className="text-xs text-slate-500">
          {Number(charge) > 0 ? (
            <>
              The client paid <strong>{inr(gross)}</strong>; <strong>{inr(charge)}</strong> stayed with the
              gateway. The invoice will be credited the full {inr(gross)} and the charge booked as an expense.
            </>
          ) : (
            'Leave blank if the whole amount reached the bank.'
          )}
        </p>

        <Button
          className="w-full"
          disabled={pending}
          onClick={() => onSettle(Number(charge) || undefined, note || undefined)}
        >
          {pending ? 'Settling…' : `Settle ${inr(gross)}`}
        </Button>
      </div>
    </Modal>
  )
}
