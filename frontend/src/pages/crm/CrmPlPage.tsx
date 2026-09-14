import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileSpreadsheet, Link2, Plus, Scale, Settings2, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmPlConfig, type CrmPlFigure, type CrmPlMonth } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Input, Label, Modal, Select, Spinner } from '../../components/ui'
import { saveBlob } from '../../lib/download'

const inr = (v: number) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })

/**
 * The monthly P&L — the Admin's page alone. Income (gross sales, taxes
 * included, universal INR) on the left; expenses (the book by category,
 * payroll, manual lines) on the right; profit at the foot. The setup
 * decides what flows in automatically; the Add buttons cover what the
 * system does not know.
 */
export default function CrmPlPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const thisMonth = new Date().toISOString().slice(0, 7)
  const [monthFrom, setMonthFrom] = useState(thisMonth)
  const [monthTo, setMonthTo] = useState(thisMonth)
  const [showConfig, setShowConfig] = useState(false)
  const [adding, setAdding] = useState<{ month: string; side: 'income' | 'expense' } | null>(null)

  const { data, isLoading, refetch, isFetching } = useQuery({
    queryKey: ['crm', 'pl', monthFrom, monthTo],
    queryFn: () => crm.pl.statement(monthFrom, monthTo),
    enabled: !!monthFrom && !!monthTo && monthTo >= monthFrom,
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'pl'] })

  const [exportingPl, setExportingPl] = useState(false)
  const downloadExcel = async () => {
    setExportingPl(true)
    try {
      const blob = await crm.pl.exportExcel(monthFrom, monthTo)
      saveBlob(blob, `profit-and-loss-${monthFrom}${monthTo !== monthFrom ? `-to-${monthTo}` : ''}.xlsx`)
    } catch (err) {
      toastError(errorMessage(err))
    } finally {
      setExportingPl(false)
    }
  }

  const deleteLine = useMutation({
    mutationFn: (id: number) => crm.pl.deleteLine(id),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-white">
            <Scale className="size-5 text-emerald-500" /> Profit &amp; Loss
          </h1>
          <p className="text-sm text-slate-500">
            Month by month — income from sales on the left, the company&rsquo;s spending on the right. Admin only.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <div>
            <Label>From month</Label>
            <Input type="month" value={monthFrom} onChange={(e) => setMonthFrom(e.target.value)} className="w-40" />
          </div>
          <div>
            <Label>To month</Label>
            <Input type="month" value={monthTo} onChange={(e) => setMonthTo(e.target.value)} className="w-40" />
          </div>
          <Button variant="secondary" onClick={downloadExcel} disabled={!data || data.months.length === 0 || exportingPl}>
            <FileSpreadsheet className="size-4" /> {exportingPl ? 'Preparing…' : 'Excel'}
          </Button>
          <Button variant="secondary" onClick={() => setShowConfig(true)}>
            <Settings2 className="size-4" /> Setup
          </Button>
          <Button onClick={() => refetch()} disabled={isFetching}>
            {isFetching ? 'Computing…' : 'Calculate P&L'}
          </Button>
        </div>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-16"><Spinner /></div>
      ) : !data || data.months.length === 0 ? (
        <Card><EmptyState title="Pick a month range" hint="The statement computes from invoices, expenses and payroll." /></Card>
      ) : (
        <>
          {data.months.length > 1 && (
            <div className="grid grid-cols-3 gap-3">
              {[
                ['Income (span)', data.totals.income, 'text-slate-900 dark:text-white'],
                ['Expenses (span)', data.totals.expense, 'text-red-500'],
                [data.totals.profit >= 0 ? 'Profit (span)' : 'Loss (span)', data.totals.profit,
                  data.totals.profit >= 0 ? 'text-emerald-600' : 'text-red-600'],
              ].map(([label, value, tone]) => (
                <Card key={label as string} className="py-3">
                  <div className={clsx('text-lg font-semibold', tone as string)}>{inr(value as number)}</div>
                  <div className="text-xs text-slate-500">{label as string}</div>
                </Card>
              ))}
            </div>
          )}

          {data.months.map((m) => (
            <MonthCard
              key={m.month}
              m={m}
              onAdd={(side) => setAdding({ month: m.month, side })}
              onDeleteLine={(id) => { if (confirm('Remove this line?')) deleteLine.mutate(id) }}
            />
          ))}
        </>
      )}

      {showConfig && <ConfigModal onClose={() => setShowConfig(false)} onDone={() => { setShowConfig(false); refresh() }} />}
      {adding && (
        <AddLineModal
          month={adding.month}
          side={adding.side}
          onClose={() => setAdding(null)}
          onDone={(message) => { setAdding(null); refresh(); toast(message, 'success') }}
        />
      )}
    </div>
  )
}

function MonthCard({ m, onAdd, onDeleteLine }: {
  m: CrmPlMonth
  onAdd: (side: 'income' | 'expense') => void
  onDeleteLine: (id: number) => void
}) {
  const side = (title: string, lines: CrmPlMonth['income'], total: number, sideKey: 'income' | 'expense', tone: string) => (
    <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
      <div className="mb-1 flex items-center justify-between">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</h3>
        <button onClick={() => onAdd(sideKey)} className="flex items-center gap-1 rounded-lg px-2 py-0.5 text-xs text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-500/10">
          <Plus className="size-3.5" /> Add
        </button>
      </div>
      {lines.length === 0
        ? <p className="py-1 text-sm text-slate-400">Nothing this month.</p>
        : lines.map((l, i) => (
          <div key={i} className="flex items-baseline justify-between gap-2 py-1 text-sm">
            <span className="min-w-0 truncate text-slate-600 dark:text-slate-300">
              {l.label}
              {l.source === 'manual' && <span className="ml-1 text-[10px] text-slate-400">(manual)</span>}
              {l.source === 'linked' && (
                <span className="ml-1 inline-flex items-center gap-0.5 text-[10px] text-sky-500" title="Follows this month’s figures">
                  <Link2 className="size-3" /> auto
                </span>
              )}
            </span>
            <span className="flex shrink-0 items-center gap-1 tabular-nums">
              {inr(l.amount)}
              {(l.source === 'manual' || l.source === 'linked') && l.id && (
                <button onClick={() => onDeleteLine(l.id!)} aria-label="Remove line" className="rounded p-0.5 text-slate-300 hover:text-red-500">
                  <Trash2 className="size-3.5" />
                </button>
              )}
            </span>
          </div>
        ))}
      <div className={clsx('mt-1 flex items-baseline justify-between border-t border-slate-200 pt-1 text-sm font-semibold dark:border-slate-700', tone)}>
        <span>Total</span>
        <span className="tabular-nums">{inr(total)}</span>
      </div>
    </div>
  )

  return (
    <Card>
      <div className="mb-2 flex items-center justify-between">
        <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">
          {new Date(m.month + '-01').toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })}
        </h2>
        <span className={clsx('rounded-full px-3 py-1 text-sm font-semibold',
          m.profit >= 0
            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
            : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400')}>
          {m.profit >= 0 ? 'Profit' : 'Loss'} {inr(Math.abs(m.profit))}
        </span>
      </div>
      <div className="grid gap-3 lg:grid-cols-2">
        {side('Income', m.income, m.income_total, 'income', 'text-slate-800 dark:text-slate-100')}
        {side('Expenses', m.expenses, m.expense_total, 'expense', 'text-red-500')}
      </div>
    </Card>
  )
}

/** What flows in automatically: which companies count, which categories. */
function ConfigModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { toast, toastError } = useToast()
  const { data } = useQuery({ queryKey: ['crm', 'pl-config'], queryFn: crm.pl.config })
  const [draft, setDraft] = useState<CrmPlConfig | null>(null)
  const cfg = draft ?? data?.config ?? null

  const save = useMutation({
    mutationFn: () => crm.pl.saveConfig(cfg!),
    onSuccess: (res) => { toast(res.message, 'success'); onDone() },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (!data || !cfg) return <Modal title="P&L setup" onClose={onClose}><div className="flex justify-center py-8"><Spinner /></div></Modal>

  const set = (patch: Partial<CrmPlConfig>) => setDraft({ ...cfg, ...patch })
  const companyTicked = (id: number) => cfg.income_company_ids === null || cfg.income_company_ids.includes(id)
  const catTicked = (c: string) => cfg.expense_categories === null || cfg.expense_categories.includes(c)

  return (
    <Modal title="P&L setup — what flows in automatically" onClose={onClose} wide>
      <div className="space-y-4">
        <div>
          <Label>Income — issuing companies counted (untick to exclude)</Label>
          <div className="mt-1 grid gap-1.5 sm:grid-cols-2">
            {data.companies.map((c) => (
              <label key={c.id} className="flex items-center gap-2 rounded-lg bg-slate-50 px-2 py-1.5 text-sm dark:bg-slate-800/60">
                <input
                  type="checkbox"
                  checked={companyTicked(c.id)}
                  onChange={(e) => {
                    const all = data.companies.map((x) => x.id)
                    const current = cfg.income_company_ids === null ? all : cfg.income_company_ids
                    const next = e.target.checked ? [...current, c.id] : current.filter((x) => x !== c.id)
                    set({ income_company_ids: next.length === all.length ? null : next })
                  }}
                  className="size-4 accent-emerald-600"
                />
                {c.name} {c.currency && c.currency !== 'INR' && <span className="text-xs text-slate-400">({c.currency})</span>}
              </label>
            ))}
          </div>
          <p className="mt-1 text-xs text-slate-400">Gross sales, taxes included. Foreign-currency invoices count at their frozen INR equivalent.</p>
        </div>

        <div>
          <Label>Expenses — categories counted (untick to exclude)</Label>
          <div className="mt-1 grid gap-1.5 sm:grid-cols-3">
            {data.categories.map((c) => (
              <label key={c} className="flex items-center gap-2 rounded-lg bg-slate-50 px-2 py-1.5 text-sm dark:bg-slate-800/60">
                <input
                  type="checkbox"
                  checked={catTicked(c)}
                  onChange={(e) => {
                    const current = cfg.expense_categories === null ? [...data.categories] : cfg.expense_categories
                    const next = e.target.checked ? [...current, c] : current.filter((x) => x !== c)
                    set({ expense_categories: next.length === data.categories.length ? null : next })
                  }}
                  className="size-4 accent-emerald-600"
                />
                <span className="truncate">{c}</span>
              </label>
            ))}
          </div>
        </div>

        <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
          <input type="checkbox" checked={cfg.include_salaries} onChange={(e) => set({ include_salaries: e.target.checked })} className="size-4 accent-emerald-600" />
          Include salaries (the month&rsquo;s CTC &mdash; net payroll plus deductions) in expenses
        </label>
        <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
          <input type="checkbox" checked={!!cfg.include_proformas} onChange={(e) => set({ include_proformas: e.target.checked })} className="size-4 accent-emerald-600" />
          Count proformas as income too (normally invoices only)
        </label>

        <Button className="w-full" disabled={save.isPending} onClick={() => save.mutate()}>
          {save.isPending ? 'Saving…' : 'Save setup'}
        </Button>
      </div>
    </Modal>
  )
}

/**
 * Add a line: one of the month's own figures - CGST, SGST, IGST, TDS,
 * commission, expenses - which then follows the month, or a line typed by hand.
 */
function AddLineModal({ month, side, onClose, onDone }: {
  month: string
  side: 'income' | 'expense'
  onClose: () => void
  onDone: (message: string) => void
}) {
  const { toastError } = useToast()
  const [picked, setPicked] = useState<CrmPlFigure | null>(null)
  const [label, setLabel] = useState('')
  const [amount, setAmount] = useState('')
  const [lineSide, setLineSide] = useState(side)

  const figures = useQuery({
    queryKey: ['crm', 'pl-figures', month],
    queryFn: () => crm.pl.figures(month),
  })

  const pick = (f: CrmPlFigure | null) => {
    setPicked(f)
    if (f) {
      setLabel(f.label)
      setLineSide(f.side)
    } else {
      setLabel('')
    }
  }

  const save = useMutation({
    mutationFn: () => crm.pl.addLine(picked
      ? { month, side: lineSide, label: label.trim() || picked.label, auto_key: picked.key }
      : { month, side: lineSide, label: label.trim(), amount: Number(amount) }),
    onSuccess: (res) => onDone(res.message),
    onError: (err) => toastError(errorMessage(err)),
  })

  const monthName = new Date(month + '-01').toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })

  return (
    <Modal title={`Add a line — ${monthName}`} onClose={onClose} wide>
      <div className="space-y-4">
        <div>
          <Label>From this month&rsquo;s figures</Label>
          {figures.isLoading ? (
            <div className="flex justify-center py-4"><Spinner /></div>
          ) : (
            <div className="mt-1 grid grid-cols-2 gap-2 sm:grid-cols-3">
              {(figures.data ?? []).map((f) => (
                <button
                  key={f.key}
                  type="button"
                  disabled={f.added}
                  onClick={() => pick(picked?.key === f.key ? null : f)}
                  className={clsx(
                    'rounded-xl border px-3 py-2 text-left transition disabled:cursor-not-allowed disabled:opacity-50',
                    picked?.key === f.key
                      ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-500/10'
                      : 'border-slate-200 hover:border-emerald-400 dark:border-slate-700',
                  )}
                >
                  <div className="text-xs text-slate-500">{f.label}</div>
                  <div className="font-semibold tabular-nums text-slate-800 dark:text-slate-100">{inr(f.amount)}</div>
                  {f.added
                    ? <div className="text-[10px] text-slate-400">already added</div>
                    : f.already_counted && <div className="text-[10px] text-amber-600 dark:text-amber-400">already in expenses</div>}
                </button>
              ))}
            </div>
          )}
          {picked && (
            <p className={clsx('mt-2 text-xs', picked.already_counted ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400')}>
              {picked.note} It follows the month&rsquo;s figures, so it updates as invoices and expenses change.
              {picked.already_counted && ' The statement already counts this through the expense categories — adding it again counts it twice, unless you untick those categories in Setup.'}
            </p>
          )}
        </div>

        <div className="flex items-center gap-2 text-xs text-slate-400">
          <span className="h-px flex-1 bg-slate-200 dark:bg-slate-700" />
          {picked ? 'adjust the line' : 'or type a line by hand'}
          <span className="h-px flex-1 bg-slate-200 dark:bg-slate-700" />
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Side</Label>
            <Select value={lineSide} onChange={(e) => setLineSide(e.target.value as 'income' | 'expense')} className="w-full">
              <option value="income">Income</option>
              <option value="expense">Expense</option>
            </Select>
          </div>
          <div>
            <Label>Amount (₹)</Label>
            {picked ? (
              <Input value={inr(picked.amount)} disabled className="w-full" />
            ) : (
              <Input type="number" min="0" value={amount} onChange={(e) => setAmount(e.target.value)} className="w-full" />
            )}
          </div>
        </div>
        <div>
          <Label>Label</Label>
          <Input value={label} onChange={(e) => setLabel(e.target.value)} placeholder="Credit card bill / cash expense / tax provision…" className="w-full" />
        </div>
        <Button
          className="w-full"
          disabled={save.isPending || (picked ? false : !label.trim() || !Number(amount))}
          onClick={() => save.mutate()}
        >
          {save.isPending ? 'Adding…' : picked ? `Add ${label.trim() || picked.label}` : 'Add line'}
        </Button>
      </div>
    </Modal>
  )
}
