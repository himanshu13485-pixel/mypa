import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRightLeft, Plus, Search, Download } from 'lucide-react'
import { clsx } from 'clsx'
import { ScopeToggle } from './ScopeToggle'
import { crm, crmMeQuery, CRM_DISPATCH_STATUS_LABELS, CRM_PAYMENT_STATUS_LABELS } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Input, Pager, Select, Spinner } from '../../components/ui'
import { crmPath } from '../../lib/crmPath'

const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })

export default function CrmInvoicesPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [params] = useSearchParams()
  const kind = params.get('kind') === 'proforma' ? 'proforma' : 'invoice'

  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const [paymentStatus, setPaymentStatus] = useState('')
  // The accountant's cuts: GST-wise, TDS-wise, dispatch-wise, due-wise.
  const [gst, setGst] = useState('')
  const [tds, setTds] = useState('')
  const [dispatch, setDispatch] = useState('')
  const [dueOnly, setDueOnly] = useState(false)
  const [dueMin, setDueMin] = useState('')
  const [dueMax, setDueMax] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [company, setCompany] = useState('')
  const [page, setPage] = useState(1)

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })

  /*
   * The Membership column, worded and shown as this company has it.
   *
   * The same question the document asks — a company that renamed it to
   * "Scheme" or switched it off entirely on its work order means that here
   * too, and a list carrying a column its documents do not have is a list
   * about a different company.
   */
  const membershipColumn = (masters?.work_order_method ?? [])
    .find((c) => c.source === 'builtin' && c.key === 'membership')
  const showMembership = !!membershipColumn && !membershipColumn.hidden
  const { data: me } = useQuery(crmMeQuery())

  // One click from the list: the proforma becomes a tax invoice, nothing is
  // retyped, and we land on the new document.
  const convertMutation = useMutation({
    mutationFn: (docUuid: string) => crm.invoices.convert(docUuid),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm'] })
      toast(res.message, 'success')
      navigate(crmPath(`/crm/invoices/${res.data.uuid}`))
    },
    onError: (err) => toastError(errorMessage(err)),
  })
  // Everyone but the Company Admin and a Subadmin sees their own ledger.
  const ownLedger = !(me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin')
  // A Team Head holds two ledgers — their own sales, and the team's. They
  // open on their own, and switch to the combined view on purpose, so the
  // two never mix by accident.
  const teamHead = ownLedger && !!me?.has_team
  const [scope, setScope] = useState<'mine' | 'team'>('mine')
  const effectiveScope = teamHead ? scope : 'team'
  // One person's rows out of the combined view — E-1 looking at E-2 alone.
  const [salesperson, setSalesperson] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'invoices', kind, applied, paymentStatus, gst, tds, dispatch, dueOnly, dueMin, dueMax, dateFrom, dateTo, company, page, effectiveScope, salesperson],
    queryFn: () =>
      crm.invoices.list({
        kind,
        scope: effectiveScope,
        salesperson: effectiveScope === 'team' ? salesperson || undefined : undefined,
        search: applied || undefined,
        payment_status: paymentStatus || undefined,
        gst: gst || undefined,
        tds: tds || undefined,
        dispatch_status: dispatch || undefined,
        due_only: dueOnly ? 1 : undefined,
        due_min: dueMin || undefined,
        due_max: dueMax || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        issuing_company_id: company || undefined,
        page,
      }),
  })

  const title = kind === 'proforma' ? 'Proforma invoices' : 'Invoices'

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">{title}</h1>
          <p className="text-sm text-slate-500">
            {data ? (
              <>
                {data.totals.count} documents · {inr(data.totals.total)} total value
                {data.totals.due > 0 && <> · <span className="font-medium text-red-500">{inr(data.totals.due)} due</span></>}
              </>
            ) : '…'}
            {ownLedger && (
              <> · {teamHead
                ? scope === 'mine' ? 'your own sales' : 'you and your team together'
                : 'your own documents'}</>
            )}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {/* The accounting export: the Admin, plus the Subadmin the Admin
              named. CSV with a BOM, so Excel opens it cleanly. */}
          {me?.member?.can_export && (
            <Button
              variant="secondary"
              onClick={async () => {
                try {
                  const blob = await crm.exports.invoicesCsv({ kind })
                  const url = URL.createObjectURL(blob)
                  const a = document.createElement('a')
                  a.href = url
                  a.download = `${kind}s-export.csv`
                  a.click()
                  URL.revokeObjectURL(url)
                } catch { /* the server names the refusal */ }
              }}
            >
              <Download className="size-4" /> Excel
            </Button>
          )}
          <Button onClick={() => navigate(crmPath(`/crm/invoices/new?kind=${kind}`))}>
            <Plus className="size-4" /> {kind === 'proforma' ? 'New proforma' : 'New invoice'}
          </Button>
        </div>
      </div>

      {/* The two ledgers, kept apart on purpose. */}
      <ScopeToggle scope={scope} onChange={(next) => { setScope(next); setSalesperson(''); setPage(1) }} show={teamHead} />

      {/* The combined view says whose money is whose, before the rows. */}
      {effectiveScope === 'team' && (data?.totals.by_salesperson?.length ?? 0) > 1 && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {data!.totals.by_salesperson!.map((row) => (
            <button
              key={row.name}
              onClick={() => row.uuid && setSalesperson(salesperson === row.uuid ? '' : row.uuid)}
              className="text-left"
              title={row.uuid ? 'Show only this person’s documents' : undefined}
            >
              <Card className={clsx(
                'py-3 transition',
                row.is_me && 'ring-2 ring-emerald-400/60',
                salesperson === row.uuid && row.uuid && 'ring-2 ring-sky-400',
              )}>
                <div className="text-lg font-semibold text-slate-900 dark:text-white">{inr(row.total)}</div>
                <div className="truncate text-xs font-medium text-slate-600 dark:text-slate-300">
                  {row.name}{row.is_me && ' (you)'}
                </div>
                <div className="text-xs text-slate-400">
                  {row.count} document{row.count === 1 ? '' : 's'}
                  {row.due > 0 && <> · <span className="font-medium text-red-500">{inr(row.due)} due</span></>}
                </div>
              </Card>
            </button>
          ))}
        </div>
      )}

      <Card>
        <form
          className="mb-4 flex flex-wrap items-end gap-2"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setApplied(search) }}
        >
          <div className="relative min-w-0 flex-1 sm:max-w-[220px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Number or company…" className="w-full pl-9" />
          </div>
          <Select value={paymentStatus} onChange={(e) => { setPaymentStatus(e.target.value); setPage(1) }}>
            <option value="">All payment states</option>
            {Object.entries(CRM_PAYMENT_STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </Select>
          <Select value={company} onChange={(e) => { setCompany(e.target.value); setPage(1) }}>
            <option value="">All issuing companies</option>
            {masters?.issuing_companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </Select>
          {effectiveScope === 'team' && (data?.totals.by_salesperson?.length ?? 0) > 1 && (
            <Select value={salesperson} onChange={(e) => { setSalesperson(e.target.value); setPage(1) }}>
              <option value="">All salespeople</option>
              {data!.totals.by_salesperson!.filter((r) => r.uuid).map((r) => (
                <option key={r.uuid!} value={r.uuid!}>{r.name}{r.is_me ? ' (you)' : ''}</option>
              ))}
            </Select>
          )}
          <Select value={gst} onChange={(e) => { setGst(e.target.value); setPage(1) }} title="GST-wise">
            <option value="">GST: any</option>
            <option value="with">With GST</option>
            <option value="without">Without GST</option>
            <option value="igst">IGST documents</option>
            <option value="cgst_sgst">CGST + SGST documents</option>
          </Select>
          <Select value={tds} onChange={(e) => { setTds(e.target.value); setPage(1) }} title="TDS-wise">
            <option value="">TDS: any</option>
            <option value="with">TDS deducted</option>
            <option value="without">No TDS</option>
          </Select>
          <Select value={dispatch} onChange={(e) => { setDispatch(e.target.value); setPage(1) }} title="Dispatch-wise">
            <option value="">Dispatch: any</option>
            {Object.entries(CRM_DISPATCH_STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </Select>
          <label className="flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300" title="Only documents still owing money">
            <input type="checkbox" checked={dueOnly} onChange={(e) => { setDueOnly(e.target.checked); setPage(1) }} className="size-4 accent-emerald-600" />
            Due only
          </label>
          <Input type="number" min="0" value={dueMin} onChange={(e) => { setDueMin(e.target.value); setPage(1) }} placeholder="Due ≥ ₹" className="w-24" aria-label="Minimum due amount" />
          <Input type="number" min="0" value={dueMax} onChange={(e) => { setDueMax(e.target.value); setPage(1) }} placeholder="Due ≤ ₹" className="w-24" aria-label="Maximum due amount" />
          <Input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1) }} aria-label="From date" />
          <Input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1) }} aria-label="To date" />
          <Button type="submit" variant="secondary" size="sm">Search</Button>
        </form>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title={`No ${kind === 'proforma' ? 'proforma invoices' : 'invoices'} found`} hint="Adjust the filters or create one." />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className={clsx('w-full text-sm', showMembership ? 'min-w-[980px]' : 'min-w-[820px]')}>
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Number</th>
                  <th className="py-2 pr-3 font-medium">Client</th>
                  {showMembership && (
                    <th className="py-2 pr-3 font-medium">{membershipColumn?.label ?? 'Membership'}</th>
                  )}
                  <th className="py-2 pr-3 font-medium">Issuing company</th>
                  <th className="py-2 pr-3 font-medium">Salesperson</th>
                  <th className="py-2 pr-3 font-medium">Date</th>
                  <th className="py-2 pr-3 text-right font-medium">Total</th>
                  <th className="py-2 pr-3 font-medium">Payment</th>
                  <th className="py-2 pr-3 font-medium">Dispatch</th>
                  {kind === 'proforma' && <th className="py-2 font-medium" />}
                </tr>
              </thead>
              <tbody>
                {data.data.map((i) => (
                  <tr key={i.uuid} className={clsx(
                    'border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40',
                    i.status === 'cancelled' && 'opacity-50',
                  )}>
                    <td className="py-2.5 pr-3">
                      <Link to={crmPath(`/crm/invoices/${i.uuid}`)} className="font-medium text-emerald-600 hover:underline">{i.number}</Link>
                      {i.status === 'cancelled' && <span className="ml-1.5 text-[10px] uppercase text-red-400">cancelled</span>}
                      {kind === 'proforma' && i.converted && <span className="ml-1.5 text-[10px] uppercase text-emerald-500">converted</span>}
                      {/* Raised by a schedule — the office sees it even when
                          the paper stays silent. */}
                      {i.is_recurring && (
                        <span
                          className="ml-1.5 rounded-full bg-violet-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-violet-600 dark:bg-violet-500/15 dark:text-violet-400"
                          title={i.recurring_note ?? 'Raised by a recurring schedule'}
                        >
                          Recurring
                        </span>
                      )}
                    </td>
                    <td className="max-w-[200px] truncate py-2.5 pr-3">{i.client?.company_name ?? '—'}</td>
                    {showMembership && (
                      /* Titled as well as truncated: an invoice against three
                         memberships is exactly the row somebody is looking
                         for, and it is the one that will not fit. */
                      <td
                        className="max-w-[160px] truncate py-2.5 pr-3"
                        title={(i.memberships ?? []).join(', ') || undefined}
                      >
                        {(i.memberships ?? []).join(', ') || '—'}
                      </td>
                    )}
                    <td className="max-w-[160px] truncate py-2.5 pr-3">{i.issuing_company?.name ?? '—'}</td>
                    <td className="py-2.5 pr-3">{i.salesperson?.name ?? '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-slate-500">{i.invoice_date}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right font-medium">
                      {inr(i.total)}
                      {i.total_fx && <div className="text-[11px] font-normal text-slate-400">{i.fx_currency} {Number(i.total_fx).toLocaleString()}</div>}
                    </td>
                    <td className="py-2.5 pr-3">
                      <span className={clsx(
                        'whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium',
                        i.payment_status === 'paid' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
                        i.payment_status === 'partial' && 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
                        i.payment_status === 'due' && 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
                        !['paid', 'partial', 'due'].includes(i.payment_status) && 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
                      )}>
                        {CRM_PAYMENT_STATUS_LABELS[i.payment_status] ?? i.payment_status}
                      </span>
                    </td>
                    <td className="py-2.5 pr-3">
                      <span className="whitespace-nowrap text-xs text-slate-500">
                        {CRM_DISPATCH_STATUS_LABELS[i.dispatch_status] ?? i.dispatch_status}
                      </span>
                    </td>
                    {kind === 'proforma' && (
                      <td className="whitespace-nowrap py-2.5 text-right">
                        {i.converted_to_doc ? (
                          <Link to={crmPath(`/crm/invoices/${i.converted_to_doc.uuid}`)} className="text-xs font-medium text-emerald-600 hover:underline">
                            → {i.converted_to_doc.number}
                          </Link>
                        ) : i.status !== 'cancelled' ? (
                          <Button
                            size="sm"
                            variant="secondary"
                            disabled={convertMutation.isPending}
                            onClick={() => { if (confirm(`Convert ${i.number} into a tax invoice?`)) convertMutation.mutate(i.uuid) }}
                          >
                            <ArrowRightLeft className="size-3.5" /> Convert
                          </Button>
                        ) : null}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager resp={data} onPage={setPage} />

        {/* The consolidated foot: every figure an accountant asks for,
            computed over exactly what the filters selected. */}
        {data?.totals.consolidated && data.data.length > 0 && (
          <div className="mt-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
            <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
              Consolidated — filtered documents
            </p>
            <div className="grid gap-x-8 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
              {([
                ['Basic (taxable) value', data.totals.consolidated.basic],
                ['CGST', data.totals.consolidated.cgst],
                ['SGST', data.totals.consolidated.sgst],
                ['IGST', data.totals.consolidated.igst],
                ['Total GST', data.totals.consolidated.gst_total],
                ['Other tax', data.totals.consolidated.other_tax],
                ['TDS', data.totals.consolidated.tds],
                ['Total value (with tax)', data.totals.consolidated.total],
                ['Received', data.totals.consolidated.received],
                ['Bank / gateway charges', data.totals.consolidated.charges],
                ['Due amount', data.totals.consolidated.due],
              ] as const).map(([label, value]) => (
                <div key={label} className="flex items-baseline justify-between border-b border-slate-100/70 py-1 text-sm last:border-0 dark:border-slate-800/50">
                  <span className="text-slate-500">{label}</span>
                  <span className={
                    label === 'Due amount' && value > 0 ? 'font-semibold tabular-nums text-red-500'
                      : label === 'Total value (with tax)' ? 'font-semibold tabular-nums'
                        : 'tabular-nums text-slate-700 dark:text-slate-200'
                  }>
                    {inr(value)}
                  </span>
                </div>
              ))}
            </div>
          </div>
        )}
      </Card>
    </div>
  )
}
