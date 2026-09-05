import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { HandCoins, Plus, Search, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmMeQuery } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Spinner, Textarea } from '../../components/ui'
import { crmPath } from '../../lib/crmPath'

const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })

/**
 * Commission paid to a client out of a sale. The invoice is a tax document
 * and never carries it: each entry here is an expense tied to the sale, and
 * the invoice quietly remembers it in an internal note the client never
 * reads. This screen is a lens over those expenses — the books stay in one
 * place.
 */
export default function CrmCommissionsPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)

  const { data: me } = useQuery(crmMeQuery())
  const isManager = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'commissions', applied, page],
    queryFn: () => crm.commissions.list({ search: applied || undefined, page }),
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'commissions'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'reports'] })
  }

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => crm.commissions.remove(uuid),
    onSuccess: (res) => { toast(res.message, 'success'); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Commissions</h1>
          <p className="text-sm text-slate-500">
            Paid to a client out of a sale — recorded as an expense, never shown on the invoice.
          </p>
        </div>
        <Button onClick={() => setShowForm(true)}><Plus className="size-4" /> Record commission</Button>
      </div>

      {data && (
        <div className="grid grid-cols-3 gap-3">
          {[
            { label: 'Total paid', value: inr(data.summary.total) },
            { label: 'This month', value: inr(data.summary.this_month) },
            { label: 'Entries', value: String(data.summary.count) },
          ].map((s) => (
            <Card key={s.label} className="py-3">
              <div className="text-lg font-semibold text-slate-900 dark:text-white">{s.value}</div>
              <div className="text-xs font-medium text-slate-600 dark:text-slate-300">{s.label}</div>
            </Card>
          ))}
        </div>
      )}

      <Card>
        <form
          className="mb-4 flex flex-wrap items-center gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-xs">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Invoice, payee or note…" className="w-full pl-9" />
          </div>
          <Button type="submit" variant="secondary" size="sm">Search</Button>
        </form>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState
            title="No commissions recorded"
            hint="Record one and the linked invoice quietly remembers it in its internal notes."
          />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[860px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Date</th>
                  <th className="py-2 pr-3 font-medium">Against invoice</th>
                  <th className="py-2 pr-3 font-medium">Client</th>
                  <th className="py-2 pr-3 font-medium">Paid to</th>
                  <th className="py-2 pr-3 font-medium">Salesperson</th>
                  <th className="py-2 pr-3 text-right font-medium">Amount</th>
                  <th className="py-2 pr-3 font-medium">Recorded by</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {data.data.map((c) => (
                  <tr key={c.uuid} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40">
                    <td className="whitespace-nowrap py-2.5 pr-3 text-slate-500">{c.expense_date}</td>
                    <td className="py-2.5 pr-3">
                      {c.invoice ? (
                        <Link to={crmPath(`/crm/invoices/${c.invoice.uuid}`)} className="font-medium text-emerald-600 hover:underline">
                          {c.invoice.number}
                        </Link>
                      ) : '—'}
                      {c.invoice && <div className="text-xs text-slate-400">of {inr(c.invoice.total)}</div>}
                    </td>
                    <td className="max-w-[170px] truncate py-2.5 pr-3">{c.client ?? '—'}</td>
                    <td className="max-w-[170px] truncate py-2.5 pr-3">{c.payee}</td>
                    <td className="py-2.5 pr-3">{c.salesperson ?? '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right font-semibold">{inr(c.amount)}</td>
                    <td className="py-2.5 pr-3 text-slate-500">
                      {c.recorded_by ?? '—'}
                      {c.note && <div className="max-w-[180px] truncate text-xs text-slate-400" title={c.note}>{c.note}</div>}
                    </td>
                    <td className="py-2.5 text-right">
                      {isManager && (
                        <button
                          onClick={() => { if (confirm('Remove this commission entry? The internal note on the invoice stays as history.')) deleteMutation.mutate(c.uuid) }}
                          aria-label="Remove"
                          className="rounded p-1.5 text-slate-400 hover:text-red-500"
                        >
                          <Trash2 className="size-4" />
                        </button>
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

      <p className="flex items-center gap-2 text-xs text-slate-400">
        <HandCoins className="size-4" />
        Each entry is an expense in the category “Client Commission”, so the P&amp;L and Reports already count it —
        the invoice itself stays untouched.
      </p>

      {showForm && (
        <RecordModal onClose={() => setShowForm(false)} onDone={() => { setShowForm(false); refresh() }} />
      )}
    </div>
  )
}

/** Pick the sale, say who and how much — the rest is bookkeeping. */
function RecordModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { toast } = useToast()
  const [invoiceSearch, setInvoiceSearch] = useState('')
  const [invoiceUuid, setInvoiceUuid] = useState('')
  const [payee, setPayee] = useState('')
  const [amount, setAmount] = useState('')
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10))
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)

  const { data: invoices } = useQuery({
    queryKey: ['crm', 'commission-invoices', invoiceSearch],
    queryFn: () => crm.invoices.list({ kind: 'invoice', scope: 'team', search: invoiceSearch || undefined }),
  })

  const saveMutation = useMutation({
    mutationFn: () => crm.commissions.create({
      invoice_uuid: invoiceUuid,
      amount: Number(amount),
      payee: payee || undefined,
      expense_date: date,
      note: note || undefined,
    }),
    onSuccess: (res) => { toast(res.message, 'success'); onDone() },
    onError: (err) => setError(errorMessage(err)),
  })

  const candidates = (invoices?.data ?? []).filter((i) => i.status !== 'cancelled')

  return (
    <Modal title="Record a commission" onClose={onClose} wide>
      <div className="space-y-3">
        <ErrorNote message={error} />
        <p className="text-sm text-slate-500">
          The amount is filed as an expense against the sale. The invoice is not changed — it only remembers
          this in its internal notes, which the client never sees.
        </p>

        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
          <Input value={invoiceSearch} onChange={(e) => setInvoiceSearch(e.target.value)} placeholder="Find the invoice — number or client…" className="w-full pl-9" />
        </div>
        <div className="max-h-48 space-y-1 overflow-y-auto">
          {candidates.length === 0 ? (
            <p className="py-3 text-center text-sm text-slate-400">No invoices match.</p>
          ) : candidates.map((i) => (
            <label key={i.uuid} className={clsx(
              'flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2 ring-1 ring-inset transition-colors',
              invoiceUuid === i.uuid
                ? 'bg-emerald-50 ring-emerald-300 dark:bg-emerald-500/10 dark:ring-emerald-500/40'
                : 'ring-slate-200 hover:bg-slate-50 dark:ring-slate-700 dark:hover:bg-slate-800',
            )}>
              <input
                type="radio"
                name="commission-invoice"
                checked={invoiceUuid === i.uuid}
                onChange={() => { setInvoiceUuid(i.uuid); if (!payee) setPayee(i.client?.company_name ?? '') }}
                className="size-4 accent-emerald-600"
              />
              <span className="min-w-0 flex-1">
                <span className="font-medium">{i.number}</span>
                <span className="ml-2 text-sm text-slate-500">{i.client?.company_name}</span>
              </span>
              <span className="text-sm font-medium">{inr(i.total)}</span>
            </label>
          ))}
        </div>

        <div className="grid gap-3 sm:grid-cols-3">
          <div>
            <Label>Amount</Label>
            <Input type="number" min="0.01" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} className="w-full" />
          </div>
          <div>
            <Label>Paid to</Label>
            <Input value={payee} onChange={(e) => setPayee(e.target.value)} placeholder="Defaults to the client" className="w-full" />
          </div>
          <div>
            <Label>Paid on</Label>
            <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} className="w-full" />
          </div>
        </div>
        <div>
          <Label>Note (optional)</Label>
          <Textarea rows={2} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Agreed on call with accounts…" className="w-full" />
        </div>

        <Button
          className="w-full"
          disabled={!invoiceUuid || !amount || Number(amount) <= 0 || saveMutation.isPending}
          onClick={() => saveMutation.mutate()}
        >
          {saveMutation.isPending ? 'Recording…' : 'Record commission'}
        </Button>
      </div>
    </Modal>
  )
}
