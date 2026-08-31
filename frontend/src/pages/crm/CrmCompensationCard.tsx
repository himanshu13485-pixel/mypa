import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Banknote, HandCoins, Plus, Trash2, TrendingUp, Wallet } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmCompensation, type CrmIncentivePlanRow, type CrmLoanRow } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, ErrorNote, Input, Label, Modal, Select, Spinner } from '../../components/ui'

const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })

/**
 * The employee's terms: their CTC structure, their incentive plan, and the
 * loans working back out of the payroll. Every change is a new dated row —
 * old payslips keep the terms they were computed under.
 */
export default function CrmCompensationCard({ memberUuid }: { memberUuid: string }) {
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'compensation', memberUuid],
    queryFn: () => crm.compensation.show(memberUuid),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'compensation', memberUuid] })

  if (isLoading || !data) {
    return <Card><div className="flex justify-center py-8"><Spinner /></div></Card>
  }

  return (
    <div className="space-y-4">
      <StructureBlock memberUuid={memberUuid} data={data} onChange={refresh} />
      <PlanBlock memberUuid={memberUuid} data={data} onChange={refresh} />
      <LoanBlock memberUuid={memberUuid} data={data} onChange={refresh} />
    </div>
  )
}

// ---- The CTC structure ------------------------------------------------------

function StructureBlock({ memberUuid, data, onChange }: {
  memberUuid: string
  data: CrmCompensation
  onChange: () => void
}) {
  const { toast } = useToast()
  const [open, setOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const current = data.structures[0]

  const [form, setForm] = useState(() => ({
    effective_from: new Date().toISOString().slice(0, 10),
    basic: current ? String(Number(current.basic)) : '',
    hra: current ? String(Number(current.hra)) : '',
    components: Object.fromEntries(
      Object.keys(data.component_labels).map((k) => [k, current?.components?.[k] ? String(current.components[k]) : '']),
    ) as Record<string, string>,
    // A first structure starts inside the HR Policy's standard facilities;
    // an existing one keeps its own choices. Every switch stays individual.
    has_pf: current?.has_pf ?? data.scheme_defaults.has_pf,
    has_edli: current?.has_edli ?? data.scheme_defaults.has_edli,
    has_esi: current?.has_esi ?? data.scheme_defaults.has_esi,
    has_welfare: current?.has_welfare ?? data.scheme_defaults.has_welfare,
    pt_amount: current ? String(Number(current.pt_amount)) : '',
    tds_monthly: current ? String(Number(current.tds_monthly)) : '',
    note: '',
  }))

  const gross = (Number(form.basic) || 0) + (Number(form.hra) || 0)
    + Object.values(form.components).reduce((s, v) => s + (Number(v) || 0), 0)

  const save = useMutation({
    mutationFn: () => crm.compensation.addStructure(memberUuid, {
      effective_from: form.effective_from,
      basic: Number(form.basic) || 0,
      hra: Number(form.hra) || 0,
      components: Object.fromEntries(
        Object.entries(form.components).filter(([, v]) => Number(v) > 0).map(([k, v]) => [k, Number(v)]),
      ),
      has_pf: form.has_pf,
      has_edli: form.has_edli,
      has_esi: form.has_esi,
      has_welfare: form.has_welfare,
      pt_amount: Number(form.pt_amount) || 0,
      tds_monthly: Number(form.tds_monthly) || 0,
      note: form.note || null,
    }),
    onSuccess: (res) => { toast(res.message, 'success'); setOpen(false); onChange() },
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Card>
      <div className="flex items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
          <Wallet className="size-4 text-emerald-500" /> Salary structure (CTC)
        </h2>
        <Button size="sm" variant="secondary" onClick={() => { setError(null); setOpen(true) }}>
          <Plus className="size-4" /> {current ? 'New structure' : 'Set structure'}
        </Button>
      </div>

      {!current ? (
        <p className="mt-2 text-sm text-slate-400">
          No CTC structure yet.
          {data.legacy_salary && <> The single salary figure of {inr(data.legacy_salary)} stands in as basic-only until one is set.</>}
        </p>
      ) : (
        <div className="mt-3 space-y-3">
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {[
              { label: 'Monthly gross', value: inr(current.gross_monthly), strong: true },
              { label: 'Basic', value: inr(current.basic) },
              { label: 'HRA', value: inr(current.hra) },
              {
                label: 'Schemes',
                value: [current.has_pf && 'PF', current.has_edli && 'EDLI', current.has_esi && 'ESI', current.has_welfare && 'EWF']
                  .filter(Boolean).join(' · ') || 'None — in-hand only',
              },
            ].map((c) => (
              <div key={c.label} className="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/40">
                <div className={clsx('text-sm font-semibold text-slate-900 dark:text-white', c.strong && 'text-base')}>{c.value}</div>
                <div className="text-[11px] text-slate-500">{c.label}</div>
              </div>
            ))}
          </div>
          {Object.keys(current.components ?? {}).length > 0 && (
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
              {Object.entries(current.components).map(([k, v]) => (
                <span key={k}>{data.component_labels[k] ?? k}: <span className="font-medium text-slate-700 dark:text-slate-200">{inr(v)}</span></span>
              ))}
            </div>
          )}
          <p className="text-xs text-slate-400">
            Effective {current.effective_from}
            {data.structures.length > 1 && <> · {data.structures.length - 1} earlier structure{data.structures.length > 2 ? 's' : ''} kept for old slips</>}
          </p>
        </div>
      )}

      {open && (
        <Modal title="Salary structure" onClose={() => setOpen(false)} wide>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div className="grid gap-3 sm:grid-cols-3">
              <div>
                <Label>Effective from</Label>
                <Input type="date" value={form.effective_from} onChange={(e) => setForm((f) => ({ ...f, effective_from: e.target.value }))} className="w-full" />
              </div>
              <div>
                <Label>Basic (₹/month)</Label>
                <Input type="number" min="0" value={form.basic} onChange={(e) => setForm((f) => ({ ...f, basic: e.target.value }))} className="w-full" />
              </div>
              <div>
                <Label>HRA</Label>
                <Input type="number" min="0" value={form.hra} onChange={(e) => setForm((f) => ({ ...f, hra: e.target.value }))} className="w-full" />
              </div>
            </div>

            <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
              <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Allowances &amp; fixed pay</div>
              <div className="grid gap-3 sm:grid-cols-3">
                {Object.entries(data.component_labels).map(([key, label]) => (
                  <div key={key}>
                    <Label>{label}</Label>
                    <Input
                      type="number"
                      min="0"
                      value={form.components[key] ?? ''}
                      onChange={(e) => setForm((f) => ({ ...f, components: { ...f.components, [key]: e.target.value } }))}
                      className="w-full"
                      placeholder="0"
                    />
                  </div>
                ))}
              </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <div className="space-y-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Statutory schemes</div>
                {([
                  ['has_pf', `PF — employer ${data.statutory.pf_employer_rate}%, employee ${data.statutory.pf_employee_rate}%, on basic capped at ${data.statutory.pf_wage_cap.toLocaleString('en-IN')}`],
                  ['has_edli', `EDLI — ${data.statutory.edli_rate}% of capped basic, employer side`],
                  ['has_esi', `ESI — ${data.statutory.esi_employer_rate}% employer, ${data.statutory.esi_employee_rate}% employee, on gross`],
                  ['has_welfare', `EWF — ${data.statutory.welfare_employee_rate}% of gross (max ${data.statutory.welfare_employee_cap}), employer pays ${data.statutory.welfare_employer_multiple}×`],
                ] as const).map(([key, label]) => (
                  <label key={key} className="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input
                      type="checkbox"
                      checked={form[key]}
                      onChange={(e) => setForm((f) => ({ ...f, [key]: e.target.checked }))}
                      className="mt-0.5 size-4 accent-emerald-600"
                    />
                    {label}
                  </label>
                ))}
                <p className="text-xs text-slate-400">
                  Every facility is optional — untick all four for an employee who only wants the discussed
                  in-hand salary. Rates live in the HR Policy; the standard prefill comes from there too.
                </p>
              </div>
              <div className="space-y-3">
                <div>
                  <Label>Professional tax (₹/month)</Label>
                  <Input type="number" min="0" value={form.pt_amount} onChange={(e) => setForm((f) => ({ ...f, pt_amount: e.target.value }))} className="w-full" placeholder="0" />
                </div>
                <div>
                  <Label>Standing TDS (₹/month)</Label>
                  <Input type="number" min="0" value={form.tds_monthly} onChange={(e) => setForm((f) => ({ ...f, tds_monthly: e.target.value }))} className="w-full" placeholder="0" />
                </div>
                <div>
                  <Label>Note</Label>
                  <Input value={form.note} onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))} className="w-full" placeholder="e.g. annual revision" />
                </div>
              </div>
            </div>

            <InHandPreview
              basic={Number(form.basic) || 0}
              gross={gross}
              esiBase={gross - (Number(form.components.fix_allowance) || 0)}
              flags={form}
              rates={data.statutory}
            />

            <Button className="w-full" disabled={!form.basic || save.isPending} onClick={() => save.mutate()}>
              {save.isPending ? 'Saving…' : 'Save structure'}
            </Button>
          </div>
        </Modal>
      )}
    </Card>
  )
}

// ---- The incentive plan -----------------------------------------------------

function PlanBlock({ memberUuid, data, onChange }: {
  memberUuid: string
  data: CrmCompensation
  onChange: () => void
}) {
  const { toast } = useToast()
  const [open, setOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const current: CrmIncentivePlanRow | undefined = data.plans[0]

  const [form, setForm] = useState(() => ({
    effective_from: new Date().toISOString().slice(0, 10),
    kind: (current?.kind ?? 'none') as CrmIncentivePlanRow['kind'],
    percent: current?.config?.percent !== undefined ? String(current.config.percent) : '',
    base_amount: current?.config?.base_amount !== undefined ? String(current.config.base_amount) : '',
    team_percent: current?.config?.team_percent !== undefined ? String(current.config.team_percent) : '',
    team_mode: (current?.config?.team_mode ?? 'separate') as 'separate' | 'combined',
    slab_mode: (current?.config?.slab_mode ?? 'whole') as 'whole' | 'marginal',
    spread_months: current?.config?.spread_months !== undefined ? String(current.config.spread_months) : '',
    slabs: (current?.config?.slabs ?? [{ upto: 1000000, percent: 1 }, { upto: null, percent: 2 }])
      .map((s) => ({ upto: s.upto === null ? '' : String(s.upto), percent: String(s.percent) })),
    release_offset_months: String(current?.release_offset_months ?? 1),
    note: current?.note ?? '',
  }))

  const gateMutation = useMutation({
    mutationFn: (mode: 'policy' | 'require' | 'release') => crm.compensation.setPaymentGate(memberUuid, mode),
    onSuccess: (res) => { toast(res.message, 'success'); onChange() },
    onError: (err) => setError(errorMessage(err)),
  })

  // What last month would have paid under this member's standing plan.
  const previewMonth = new Date(Date.now() - 15 * 86400000).toISOString().slice(0, 7)
  const { data: preview } = useQuery({
    queryKey: ['crm', 'incentive-preview', memberUuid, previewMonth],
    queryFn: () => crm.compensation.preview(memberUuid, previewMonth),
    enabled: !!current && current.kind !== 'none',
  })

  const save = useMutation({
    mutationFn: () => crm.compensation.addPlan(memberUuid, {
      effective_from: form.effective_from,
      kind: form.kind,
      config: form.kind === 'none' ? {} : {
        percent: form.percent === '' ? null : Number(form.percent),
        base_amount: form.base_amount === '' ? null : Number(form.base_amount),
        team_percent: form.team_mode === 'separate' && form.team_percent !== '' ? Number(form.team_percent) : null,
        team_mode: form.team_mode,
        spread_months: form.kind === 'spread' && form.spread_months !== '' ? Number(form.spread_months) : null,
        slab_mode: form.slab_mode,
        slabs: form.kind === 'slab'
          ? form.slabs
            .filter((s) => s.percent !== '')
            .map((s) => ({ upto: s.upto === '' ? null : Number(s.upto), percent: Number(s.percent) }))
          : undefined,
      },
      release_offset_months: Number(form.release_offset_months) || 0,
      note: form.note || null,
    }),
    onSuccess: (res) => { toast(res.message, 'success'); setOpen(false); onChange() },
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Card>
      <div className="flex items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
          <TrendingUp className="size-4 text-emerald-500" /> Incentive plan
        </h2>
        <Button size="sm" variant="secondary" onClick={() => { setError(null); setOpen(true) }}>
          <Plus className="size-4" /> {current ? 'New plan' : 'Set plan'}
        </Button>
      </div>

      {!current || current.kind === 'none' ? (
        <p className="mt-2 text-sm text-slate-400">No incentive plan — the slip carries salary only.</p>
      ) : (
        <div className="mt-3 space-y-2 text-sm">
          <div className="font-medium text-slate-800 dark:text-slate-100">{current.kind_label}</div>
          <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
            {current.config.percent !== undefined && current.config.percent !== null && <span>Self: {current.config.percent}%</span>}
            {!!current.config.base_amount && <span>less base {inr(current.config.base_amount)}</span>}
            {current.config.team_mode === 'combined'
              ? <span>Team: combined — self + team through this plan</span>
              : !!current.config.team_percent && <span>Team: {current.config.team_percent}% separate</span>}
            {current.kind === 'spread' && (
              <span>
                Spread over {current.config.spread_months ?? data.incentive_defaults.spread_months} months
                &nbsp;&middot; TDS read off each invoice
              </span>
            )}
            {current.kind === 'slab' && current.config.slabs && (
              <span>
                Slabs ({current.config.slab_mode ?? 'whole'}):{' '}
                {current.config.slabs.map((sl) => `${sl.upto === null ? 'above' : 'to ' + inr(sl.upto)} → ${sl.percent}%`).join(' · ')}
              </span>
            )}
            <span>Released {current.release_offset_months === 0 ? 'the same month' : `${current.release_offset_months} month${current.release_offset_months > 1 ? 's' : ''} later`}</span>
            <span>
              Effective {current.effective_from}
              {current.created_at && <> · changed on {current.created_at}</>}
            </span>
          </div>
          {preview && (
            <div className="rounded-xl bg-slate-50 px-3 py-2 text-xs dark:bg-slate-800/40">
              <span className="text-slate-500">Preview — {preview.incentive_month}: </span>
              {preview.self && (
                <span>
                  sale {inr(preview.self.total)}
                  {preview.self.commission + preview.self.charges > 0 && <> − {inr(preview.self.commission + preview.self.charges)} costs</>}
                  {' → '}<span className="font-semibold text-slate-700 dark:text-slate-200">{inr(preview.self_incentive)}</span>
                </span>
              )}
              {preview.team_incentive > 0 && <span> · team {inr(preview.team_incentive)}</span>}
              <span> · total <span className="font-semibold text-emerald-600">{inr(preview.total)}</span></span>
            </div>
          )}
        </div>
      )}

      {/* Plans are dated rows, never edited in place — so every plan this
          person ever had stays on record, oldest at the bottom. */}
      {data.plans.length > 1 && (
        <div className="mt-3 rounded-xl bg-slate-50 p-3 text-xs dark:bg-slate-800/40">
          <div className="mb-1 font-semibold uppercase tracking-wide text-slate-500">Plan history</div>
          <ul className="divide-y divide-slate-200/70 dark:divide-slate-700/60">
            {data.plans.slice(1).map((p) => (
              <li key={p.uuid} className="flex flex-wrap items-baseline justify-between gap-x-3 py-1.5">
                <span className="text-slate-600 dark:text-slate-300">
                  {p.kind_label}
                  {p.config?.percent !== undefined && p.config?.percent !== null && <> — {p.config.percent}%</>}
                  {!!p.config?.team_percent && <> + team {p.config.team_percent}%</>}
                  {p.kind === 'spread' && <> · over {p.config?.spread_months ?? data.incentive_defaults.spread_months} mo</>}
                  {p.note && <span className="ml-1 text-slate-400">({p.note})</span>}
                </span>
                <span className="text-slate-400">
                  effective {p.effective_from} → superseded
                  {p.created_at && <> · changed on {p.created_at}</>}
                  {p.created_by && <> · by {p.created_by}</>}
                </span>
              </li>
            ))}
          </ul>
          <p className="mt-1 text-[11px] text-slate-400">
            Runs already in flight keep the terms of the plan standing on their own sale date.
          </p>
        </div>
      )}

      {/* The payment gate for THIS person — the same rule the HR Policy
          sets house-wide, overridable here when one employee's incentive
          must be released (or held) regardless. Manager authority only. */}
      <div className="mt-4 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
        <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
          No incentive until the client has paid in full
        </div>
        <p className="mt-1 text-xs text-slate-400">
          An unpaid sale&rsquo;s installments wait, marked &ldquo;awaiting full payment&rdquo;; the moment the
          invoice is settled they release themselves as one arrear — no button, no ruling. Right now this
          employee&rsquo;s incentive{' '}
          <span className="font-medium text-slate-600 dark:text-slate-300">
            {data.payment_gate.effective ? 'waits for full payment' : 'pays without waiting'}
          </span>
          {data.payment_gate.override === null ? ' (following the HR Policy).' : ' (their own override).'}
        </p>
        <div className="mt-2 flex flex-wrap gap-1.5">
          {([
            ['policy', `Follow HR Policy (${data.payment_gate.policy ? 'waits' : 'pays'})`],
            ['require', 'Always wait for full payment'],
            ['release', 'Release — pay without waiting'],
          ] as const).map(([mode, label]) => {
            const active = mode === 'policy'
              ? data.payment_gate.override === null
              : data.payment_gate.override === (mode === 'require')
            return (
              <button
                key={mode}
                disabled={gateMutation.isPending}
                onClick={() => gateMutation.mutate(mode)}
                className={clsx(
                  'rounded-xl border px-3 py-1.5 text-xs transition disabled:opacity-60',
                  active
                    ? 'border-emerald-400 bg-emerald-50 font-medium text-emerald-700 dark:border-emerald-500 dark:bg-emerald-500/10 dark:text-emerald-300'
                    : 'border-slate-200 text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/60',
                )}
              >
                {label}
              </button>
            )
          })}
        </div>
      </div>

      {open && (
        <Modal title="Incentive plan" onClose={() => setOpen(false)} wide>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div className="grid gap-3 sm:grid-cols-3">
              <div>
                <Label>Effective from</Label>
                <Input type="date" value={form.effective_from} onChange={(e) => setForm((f) => ({ ...f, effective_from: e.target.value }))} className="w-full" />
              </div>
              <div>
                <Label>Plan type</Label>
                <Select value={form.kind} onChange={(e) => setForm((f) => ({ ...f, kind: e.target.value as typeof f.kind }))} className="w-full">
                  {Object.entries(data.plan_kinds).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </Select>
              </div>
              <div>
                <Label>Released after (months)</Label>
                <Input type="number" min="0" max="6" value={form.release_offset_months} onChange={(e) => setForm((f) => ({ ...f, release_offset_months: e.target.value }))} className="w-full" />
                <p className="mt-1 text-xs text-slate-400">1 = January&rsquo;s sales pay on February&rsquo;s slip.</p>
              </div>
            </div>

            {form.kind === 'spread' && (
              <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
                <div className="grid gap-3 sm:grid-cols-2">
                  <div>
                    <Label>Percent of effective sale</Label>
                    <Input type="number" min="0" max="100" step="0.1" value={form.percent} onChange={(e) => setForm((f) => ({ ...f, percent: e.target.value }))} className="w-full" />
                  </div>
                  <div>
                    <Label>Spread over — fallback (months)</Label>
                    <Input
                      type="number" min="1" max="60"
                      value={form.spread_months}
                      onChange={(e) => setForm((f) => ({ ...f, spread_months: e.target.value }))}
                      className="w-full"
                      placeholder={`${data.incentive_defaults.spread_months} (HR Policy)`}
                    />
                    <p className="mt-1 text-xs text-slate-400">
                      Each sale spreads over its OWN Work Order validity — a 26 Aug → 26 Nov plan divides over
                      3 months, a one-year plan over 12. This fallback applies only when a sale carries no
                      validity dates.
                    </p>
                  </div>
                </div>
                <SpreadExample
                  percent={Number(form.percent) || 0}
                  months={Number(form.spread_months) || data.incentive_defaults.spread_months}
                />
                <p className="mt-2 text-xs text-slate-400">
                  TDS is never set here: each invoice carries the TDS its client actually deducted — 2%, 10%
                  or none — and the incentive follows that invoice&rsquo;s own net figure automatically.
                </p>
                <p className="mt-2 text-xs text-slate-400">
                  Each sale-month&rsquo;s incentive divides over the spread instead of paying in one go. An
                  installment is recomputed from the ledger the month it is paid — cancel the invoice and the
                  remaining installments simply never happen. No clawback, no loss.
                </p>
              </div>
            )}

            {(form.kind === 'flat_percent' || form.kind === 'percent_minus_base') && (
              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <Label>Percent of effective sale</Label>
                  <Input type="number" min="0" max="100" step="0.1" value={form.percent} onChange={(e) => setForm((f) => ({ ...f, percent: e.target.value }))} className="w-full" />
                </div>
                {form.kind === 'percent_minus_base' && (
                  <div>
                    <Label>Less base amount (₹)</Label>
                    <Input type="number" min="0" value={form.base_amount} onChange={(e) => setForm((f) => ({ ...f, base_amount: e.target.value }))} className="w-full" />
                    <p className="mt-1 text-xs text-slate-400">The guaranteed salary already covered — never goes below zero.</p>
                  </div>
                )}
              </div>
            )}

            {form.kind === 'slab' && (
              <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
                <div className="mb-2 flex items-center justify-between">
                  <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Slabs</div>
                  <Select value={form.slab_mode} onChange={(e) => setForm((f) => ({ ...f, slab_mode: e.target.value as 'whole' | 'marginal' }))}>
                    <option value="whole">Band prices the whole sale</option>
                    <option value="marginal">Each band prices its slice</option>
                  </Select>
                </div>
                {form.slabs.map((slab, i) => (
                  <div key={i} className="mb-1.5 grid grid-cols-[1fr_6rem_2rem] items-center gap-2">
                    <Input
                      type="number"
                      min="0"
                      value={slab.upto}
                      onChange={(e) => setForm((f) => ({ ...f, slabs: f.slabs.map((s, j) => j === i ? { ...s, upto: e.target.value } : s) }))}
                      className="w-full"
                      placeholder="Up to ₹ (blank = and beyond)"
                    />
                    <Input
                      type="number"
                      min="0"
                      max="100"
                      step="0.1"
                      value={slab.percent}
                      onChange={(e) => setForm((f) => ({ ...f, slabs: f.slabs.map((s, j) => j === i ? { ...s, percent: e.target.value } : s) }))}
                      className="w-full text-right"
                      placeholder="%"
                    />
                    <button
                      onClick={() => setForm((f) => ({ ...f, slabs: f.slabs.filter((_, j) => j !== i) }))}
                      className="rounded p-1.5 text-slate-400 hover:text-red-500"
                      aria-label="Remove slab"
                    >
                      <Trash2 className="size-4" />
                    </button>
                  </div>
                ))}
                <Button size="sm" variant="secondary" onClick={() => setForm((f) => ({ ...f, slabs: [...f.slabs, { upto: '', percent: '' }] }))}>
                  <Plus className="size-4" /> Add slab
                </Button>
                <p className="mt-2 text-xs text-slate-400">
                  e.g. up to 10,00,000 → 1% · up to 15,00,000 → 2% · up to 20,00,000 → 2.5% · blank → 3%. In
                  whole mode a ₹19L sale lands in the 15–20L band and all of it pays 2.5%.
                </p>
              </div>
            )}

            {form.kind !== 'none' && (
              <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
                <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Team incentive (Team Heads only)
                </div>
                <div className="space-y-2">
                  {([
                    ['separate', 'Self by this plan + separate team %', 'Own sales run through the plan above; the team\u2019s sales pay a flat percent beside it.'],
                    ['combined', 'Combined — self + team as one figure', 'The whole team\u2019s sales, own desk included, run through the plan above as a single amount.'],
                  ] as const).map(([key, label, hint]) => (
                    <label key={key} className="flex cursor-pointer items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                      <input
                        type="radio"
                        checked={form.team_mode === key}
                        onChange={() => setForm((f) => ({ ...f, team_mode: key }))}
                        className="mt-0.5 accent-emerald-600"
                      />
                      <span>
                        <span className="font-medium text-slate-800 dark:text-slate-100">{label}</span>
                        <span className="block text-xs text-slate-400">{hint}</span>
                      </span>
                    </label>
                  ))}
                </div>
                {form.team_mode === 'separate' && (
                  <div className="mt-2 sm:max-w-[200px]">
                    <Label>Team % (blank = none)</Label>
                    <Input type="number" min="0" max="100" step="0.1" value={form.team_percent} onChange={(e) => setForm((f) => ({ ...f, team_percent: e.target.value }))} className="w-full" placeholder="0" />
                  </div>
                )}
                <div className="mt-2">
                  <Label>Note</Label>
                  <Input value={form.note} onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))} className="w-full" placeholder="the plan in words" />
                </div>
              </div>
            )}

            <Button className="w-full" disabled={save.isPending} onClick={() => save.mutate()}>
              {save.isPending ? 'Saving…' : 'Save plan'}
            </Button>
          </div>
        </Modal>
      )}
    </Card>
  )
}

// ---- Loans and advances -----------------------------------------------------

function LoanBlock({ memberUuid, data, onChange }: {
  memberUuid: string
  data: CrmCompensation
  onChange: () => void
}) {
  const { toast, toastError } = useToast()
  const [open, setOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [form, setForm] = useState({
    kind: 'loan', amount: '', monthly_installment: '',
    taken_on: new Date().toISOString().slice(0, 10), note: '',
  })

  const save = useMutation({
    mutationFn: () => crm.compensation.addLoan(memberUuid, {
      kind: form.kind,
      amount: Number(form.amount),
      monthly_installment: Number(form.monthly_installment) || 0,
      taken_on: form.taken_on,
      note: form.note || null,
    }),
    onSuccess: (res) => { toast(res.message, 'success'); setOpen(false); onChange() },
    onError: (err) => setError(errorMessage(err)),
  })

  const [repaying, setRepaying] = useState<CrmLoanRow | null>(null)
  const [history, setHistory] = useState<CrmLoanRow | null>(null)

  const repay = useMutation({
    mutationFn: ({ uuid, payload }: { uuid: string; payload: Record<string, unknown> }) =>
      crm.compensation.repayLoan(memberUuid, uuid, payload),
    onSuccess: (res) => { toast(res.message, 'success'); setRepaying(null); onChange() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const close = useMutation({
    mutationFn: (uuid: string) => crm.compensation.closeLoan(memberUuid, uuid),
    onSuccess: (res) => { toast(res.message, 'info'); onChange() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const openLoans = data.loans.filter((l) => l.status === 'open')

  return (
    <Card>
      <div className="flex items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
          <HandCoins className="size-4 text-emerald-500" /> Loans &amp; advances
        </h2>
        <Button size="sm" variant="secondary" onClick={() => { setError(null); setOpen(true) }}>
          <Plus className="size-4" /> Give loan / advance
        </Button>
      </div>

      {data.loans.length === 0 ? (
        <p className="mt-2 text-sm text-slate-400">Nothing outstanding.</p>
      ) : (
        <ul className="mt-3 divide-y divide-slate-100 text-sm dark:divide-slate-800">
          {data.loans.map((l: CrmLoanRow) => (
            <li key={l.uuid} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <div className="min-w-0">
                <div className="font-medium text-slate-800 dark:text-slate-100">
                  {l.kind === 'advance' ? 'Salary advance' : 'Loan'} · {inr(l.amount)}
                  {l.status === 'closed'
                    ? <span className="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-slate-800">closed</span>
                    : <span className="ml-2 text-xs text-amber-600 dark:text-amber-400">{inr(l.balance)} left</span>}
                </div>
                <div className="text-xs text-slate-400">
                  {l.taken_on}
                  {Number(l.monthly_installment) > 0
                    ? <> · {inr(l.monthly_installment)}/month off the slip</>
                    : l.kind === 'advance' && l.status === 'open' ? <> · recovered whole next payroll</> : null}
                  {l.note && <> · {l.note}</>}
                </div>
              </div>
              <div className="flex items-center gap-1">
                {l.repayments.length > 0 && (
                  <button
                    onClick={() => setHistory(l)}
                    className="rounded px-2 py-1 text-xs font-medium text-emerald-600 hover:underline"
                  >
                    {l.repayments.length} repayment{l.repayments.length === 1 ? '' : 's'}
                  </button>
                )}
                {l.status === 'open' && (
                  <>
                    <Button size="sm" variant="secondary" onClick={() => setRepaying(l)}>
                      <Banknote className="size-3.5" /> Repay
                    </Button>
                    <Button size="sm" variant="secondary" onClick={() => {
                      if (confirm(l.balance > 0 ? `Close and write off ${inr(l.balance)}?` : 'Close this?')) close.mutate(l.uuid)
                    }}>
                      Close
                    </Button>
                  </>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
      {openLoans.length > 0 && (
        <p className="mt-2 text-xs text-slate-400">
          Open items are recovered automatically on each payroll run and show as deduction lines on the slip.
        </p>
      )}

      {repaying && (
        <RepayDialog
          loan={repaying}
          pending={repay.isPending}
          onClose={() => setRepaying(null)}
          onRepay={(payload) => repay.mutate({ uuid: repaying.uuid, payload })}
        />
      )}

      {history && (
        <Modal title={`Repayments — ${history.kind === 'advance' ? 'advance' : 'loan'} of ${inr(history.amount)}`} onClose={() => setHistory(null)}>
          <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            {history.repayments.map((r, i) => (
              <li key={i} className="flex items-start justify-between gap-3 py-2">
                <div className="min-w-0">
                  <div className="text-slate-700 dark:text-slate-200">{r.repaid_on}</div>
                  {r.note && <div className="text-xs text-slate-400">{r.note}</div>}
                </div>
                <div className="shrink-0 text-right">
                  <div className="font-medium tabular-nums text-slate-800 dark:text-slate-100">{inr(r.amount)}</div>
                  <div className="text-[11px] text-slate-400">{r.via_payroll ? 'off the payslip' : 'paid directly'}</div>
                </div>
              </li>
            ))}
          </ul>
          <div className="mt-2 flex items-baseline justify-between border-t border-slate-100 pt-2 text-sm dark:border-slate-800">
            <span className="text-slate-500">Still owed</span>
            <span className="font-semibold tabular-nums">{inr(history.balance)}</span>
          </div>
        </Modal>
      )}

      {open && (
        <Modal title="Loan or advance" onClose={() => setOpen(false)}>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Type</Label>
                <Select value={form.kind} onChange={(e) => setForm((f) => ({ ...f, kind: e.target.value }))} className="w-full">
                  <option value="loan">Loan — repaid in installments</option>
                  <option value="advance">Advance — against next salary</option>
                </Select>
              </div>
              <div>
                <Label>Amount (₹)</Label>
                <Input type="number" min="1" value={form.amount} onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value }))} className="w-full" />
              </div>
              <div>
                <Label>Monthly installment (₹)</Label>
                <Input type="number" min="0" value={form.monthly_installment} onChange={(e) => setForm((f) => ({ ...f, monthly_installment: e.target.value }))} className="w-full" placeholder={form.kind === 'advance' ? 'blank = whole next month' : ''} />
              </div>
              <div>
                <Label>Given on</Label>
                <Input type="date" value={form.taken_on} onChange={(e) => setForm((f) => ({ ...f, taken_on: e.target.value }))} className="w-full" />
              </div>
              <div className="col-span-2">
                <Label>Note</Label>
                <Input value={form.note} onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))} className="w-full" />
              </div>
            </div>
            <Button className="w-full" disabled={!form.amount || save.isPending} onClick={() => save.mutate()}>
              {save.isPending ? 'Saving…' : 'Record'}
            </Button>
          </div>
        </Modal>
      )}
    </Card>
  )
}

/**
 * The whole month computed live as the Admin types, so the discussed
 * in-hand figure is visible before anything is saved: CTC on the employer
 * side, both halves recovered on the deduction side, net at the foot —
 * exactly the arithmetic the payroll run will do.
 */
function InHandPreview({ basic, gross, esiBase, flags, rates }: {
  basic: number
  gross: number
  esiBase: number
  flags: { has_pf: boolean; has_edli: boolean; has_esi: boolean; has_welfare: boolean }
  rates: CrmCompensation['statutory']
}) {
  const capped = Math.min(basic, rates.pf_wage_cap)
  const pfER = flags.has_pf ? Math.round(capped * rates.pf_employer_rate) / 100 : 0
  const pfEE = flags.has_pf ? Math.round(capped * rates.pf_employee_rate) / 100 : 0
  const edli = flags.has_edli ? Math.round(capped * rates.edli_rate) / 100 : 0
  const esiER = flags.has_esi ? Math.ceil(esiBase * rates.esi_employer_rate / 100) : 0
  const esiEE = flags.has_esi ? Math.ceil(esiBase * rates.esi_employee_rate / 100) : 0
  const ewfEE = flags.has_welfare ? Math.min(Math.round(gross * rates.welfare_employee_rate) / 100, rates.welfare_employee_cap) : 0
  const ewfER = ewfEE * rates.welfare_employer_multiple

  const ctc = gross + pfER + edli + esiER + ewfER
  const deduction = pfER + pfEE + edli + esiER + esiEE + ewfER + ewfEE
  const net = ctc - deduction

  return (
    <div className="rounded-xl bg-slate-50 px-4 py-3 text-sm dark:bg-slate-800/60">
      <div className="grid grid-cols-3 gap-2 text-center">
        {[
          { label: 'Monthly gross', value: gross },
          { label: 'CTC (with employer side)', value: ctc },
          { label: 'Total deduction', value: deduction },
        ].map((c) => (
          <div key={c.label}>
            <div className="font-semibold tabular-nums text-slate-800 dark:text-slate-100">{inr(c.value)}</div>
            <div className="text-[11px] text-slate-500">{c.label}</div>
          </div>
        ))}
      </div>
      <div className="mt-2 flex items-baseline justify-between border-t border-slate-200 pt-2 dark:border-slate-700">
        <span className="text-slate-500">Net in hand</span>
        <span className="text-lg font-semibold tabular-nums text-emerald-600">{inr(net)}</span>
      </div>
      {deduction === 0 && (
        <p className="mt-1 text-xs text-slate-400">No facilities taken — the gross is the in-hand salary.</p>
      )}
      <p className="mt-1 text-[11px] text-slate-400">
        Attendance, incentives, loans, PT and TDS are applied on the actual payroll run.
      </p>
    </div>
  )
}

/** The owner's example, live at whatever is typed above. */
function SpreadExample({ percent, months }: { percent: number; months: number }) {
  const sale = 88200
  const pool = Math.round(sale * percent / 100 * 100) / 100
  const monthly = months > 0 ? Math.round(pool / months * 100) / 100 : 0

  if (!percent) return null

  return (
    <p className="mt-2 rounded-lg bg-white px-3 py-1.5 text-xs text-slate-600 dark:bg-slate-900 dark:text-slate-300">
      e.g. an invoice netting {inr(sale)} (after the client&rsquo;s own TDS) × {percent}% ={' '}
      {inr(pool)} → <span className="font-semibold text-emerald-600">{inr(monthly)}/month</span> for {months} months.
    </p>
  )
}

/**
 * A repayment made outside payroll — with the date it happened and a note
 * saying how, because "cash on the 14th against the festival advance" is
 * exactly what anyone auditing this later needs to read.
 */
function RepayDialog({ loan, pending, onClose, onRepay }: {
  loan: CrmLoanRow
  pending: boolean
  onClose: () => void
  onRepay: (payload: Record<string, unknown>) => void
}) {
  const [amount, setAmount] = useState(String(loan.balance))
  const [repaidOn, setRepaidOn] = useState(new Date().toISOString().slice(0, 10))
  const [note, setNote] = useState('')

  return (
    <Modal title={`Repay — ${inr(loan.balance)} left`} onClose={onClose}>
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>Amount (₹)</Label>
            <Input
              type="number" min="0.01" step="0.01" max={loan.balance}
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              className="w-full"
            />
            <p className="mt-1 text-xs text-slate-400">The full balance is filled in — lower it for a part repayment.</p>
          </div>
          <div>
            <Label>Repaid on</Label>
            <Input type="date" value={repaidOn} onChange={(e) => setRepaidOn(e.target.value)} className="w-full" />
          </div>
          <div className="col-span-2">
            <Label>Note</Label>
            <Input
              value={note}
              onChange={(e) => setNote(e.target.value)}
              className="w-full"
              placeholder="e.g. cash, bank transfer UTR, adjusted against expenses…"
            />
          </div>
        </div>
        <Button
          className="w-full"
          disabled={!amount || Number(amount) <= 0 || pending}
          onClick={() => onRepay({ amount: Number(amount), repaid_on: repaidOn, note: note || null })}
        >
          {pending ? 'Recording…' : 'Record repayment'}
        </Button>
        <p className="text-xs text-slate-400">
          Payroll recoveries need no entry here — each run records its own installment automatically.
        </p>
      </div>
    </Modal>
  )
}
