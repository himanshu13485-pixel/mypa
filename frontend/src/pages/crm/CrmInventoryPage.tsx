import { useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { asList, useFilterAddress, useFiltersInAddress } from '../../lib/useFiltersInAddress'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Boxes, History, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmCan, type CrmInventoryItem, type CrmMe } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner, Textarea } from '../../components/ui'
import { MultiSelect } from '../../components/MultiSelect'
import { listParam } from '../../lib/multiFilter'
import { money } from '../../lib/money'
import TableBox from '../../components/TableBox'

/** A count reads plainly: 12, or 12.5 where somebody sells half a metre. */
const count = (v: number | null) =>
  v === null ? '—' : String(Number(v)).replace(/\.0+$/, '')

const EMPTY = {
  issuing_company_id: '', name: '', code: '', kind: 'product', unit: '',
  description: '', unit_price: '', tax_rate: '', quantity: '', reorder_at: '',
  is_active: true,
}

const REASONS = [
  { value: 'purchase', label: 'Stock came in' },
  { value: 'return', label: 'Returned by a client' },
  { value: 'damage', label: 'Damaged or lost' },
  { value: 'adjustment', label: 'Counted the shelf' },
]

/**
 * What the company sells, and what is left of it.
 *
 * The counts here are never typed over. Every change is a move with a
 * reason behind it, because "we are four short" needs an answer, and a
 * number anybody can quietly overwrite is a number nobody can trust.
 */
export default function CrmInventoryPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  const canCreate = crmCan(me, 'inventory', 'create')
  const canEdit = crmCan(me, 'inventory', 'edit')
  const canDelete = crmCan(me, 'inventory', 'delete')
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const address = useFilterAddress()
  const [search, setSearch] = useState(() => address.text('q'))
  const [applied, setApplied] = useState(() => address.text('q'))
  const [company, setCompany] = useState<string[] | null>(() => address.list('company'))
  const [kind, setKind] = useState<string[] | null>(() => address.list('kind'))
  const [lowOnly, setLowOnly] = useState(() => address.text('low') === '1')
  const [page, setPage] = useState(() => address.page())
  useFiltersInAddress('inventory', {
    q: applied.trim() || null,
    company: asList(company),
    kind: asList(kind),
    low: lowOnly ? '1' : null,
    page: page > 1 ? String(page) : null,
  })

  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<CrmInventoryItem | null>(null)
  const [form, setForm] = useState({ ...EMPTY })
  const [error, setError] = useState<string | null>(null)
  const [moving, setMoving] = useState<CrmInventoryItem | null>(null)
  const [history, setHistory] = useState<CrmInventoryItem | null>(null)

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const companies = masters?.issuing_companies ?? []

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'inventory', applied, company, kind, lowOnly, page],
    queryFn: () => crm.inventory.list({
      search: applied || undefined,
      issuing_company_id: listParam(company),
      kind: listParam(kind),
      low: lowOnly ? 1 : undefined,
      page,
    }),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'inventory'] })
  const set = (key: keyof typeof EMPTY, value: string | boolean) => setForm((f) => ({ ...f, [key]: value }))

  const openCreate = () => {
    setEditing(null)
    // One company, no question worth asking: it is that one.
    const only = companies.length === 1 ? String(companies[0].id) : ''
    setForm({ ...EMPTY, issuing_company_id: only, kind: companies[0]?.sells === 'services' ? 'service' : 'product' })
    setError(null)
    setShowForm(true)
  }

  const openEdit = (item: CrmInventoryItem) => {
    setEditing(item)
    setForm({
      issuing_company_id: String(item.issuing_company_id),
      name: item.name,
      code: item.code ?? '',
      kind: item.kind,
      unit: item.unit ?? '',
      description: item.description ?? '',
      unit_price: String(Number(item.unit_price)),
      tax_rate: item.tax_rate === null ? '' : String(Number(item.tax_rate)),
      quantity: item.quantity === null ? '' : String(item.quantity),
      reorder_at: item.reorder_at === null ? '' : String(item.reorder_at),
      is_active: item.is_active,
    })
    setError(null)
    setShowForm(true)
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        issuing_company_id: Number(form.issuing_company_id),
        name: form.name,
        code: form.code || null,
        kind: form.kind,
        unit: form.unit || null,
        description: form.description || null,
        unit_price: Number(form.unit_price) || 0,
        tax_rate: form.tax_rate === '' ? null : Number(form.tax_rate),
        reorder_at: form.reorder_at === '' ? null : Number(form.reorder_at),
        is_active: form.is_active,
        // Only on the way in. After that the count moves for a reason.
        ...(editing ? {} : { quantity: form.quantity === '' ? 0 : Number(form.quantity) }),
      }

      return editing
        ? crm.inventory.update(editing.uuid, payload)
        : crm.inventory.create(payload)
    },
    onSuccess: (res) => { toast(res.message, 'success'); setShowForm(false); refresh() },
    onError: (err) => setError(errorMessage(err)),
  })

  const removeMutation = useMutation({
    mutationFn: (uuid: string) => crm.inventory.remove(uuid),
    onSuccess: (res) => { toast(res.message, 'success'); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const currencyOf = (item: CrmInventoryItem) => item.currency || 'INR'

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Inventory</h1>
          <p className="text-sm text-slate-500">
            {data
              ? <>{data.summary.products} product{data.summary.products === 1 ? '' : 's'} · {data.summary.services} service{data.summary.services === 1 ? '' : 's'}</>
              : 'What this company sells, and what is left of it.'}
          </p>
        </div>
        {canCreate && <Button onClick={openCreate}><Plus className="size-4" /> Add item</Button>}
      </div>

      {data && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[
            { label: 'On the shelf, at selling price', value: money(data.summary.retail_value, 'INR', { decimals: 0 }) },
            { label: 'Products counted', value: String(data.summary.products) },
            {
              label: 'Low on stock',
              value: String(data.summary.low),
              tone: data.summary.low > 0 ? 'text-amber-600 dark:text-amber-400' : '',
            },
            {
              label: 'Out of stock',
              value: String(data.summary.out_of_stock),
              tone: data.summary.out_of_stock > 0 ? 'text-red-500' : '',
            },
          ].map((c) => (
            <Card key={c.label} className="py-3">
              <div className={clsx('text-lg font-semibold text-slate-900 dark:text-white', c.tone)}>{c.value}</div>
              <div className="text-xs text-slate-500">{c.label}</div>
            </Card>
          ))}
        </div>
      )}

      <Card>
        <div className="flex flex-wrap items-center gap-2">
          <form
            className="relative min-w-0 flex-1 sm:max-w-xs"
            onSubmit={(e) => { e.preventDefault(); setApplied(search); setPage(1) }}
          >
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Name, code or description…"
              className="w-full pl-9"
            />
          </form>
          <MultiSelect
            label="Company"
            allLabel="All"
            options={companies.map((c) => ({ value: String(c.id), label: c.name }))}
            value={company}
            onChange={(v) => { setCompany(v); setPage(1) }}
            className="min-w-[10rem]"
          />
          <MultiSelect
            label="Kind"
            allLabel="All"
            options={[{ value: 'product', label: 'Products' }, { value: 'service', label: 'Services' }]}
            value={kind}
            onChange={(v) => { setKind(v); setPage(1) }}
            className="min-w-[9rem]"
          />
          <button
            type="button"
            onClick={() => { setLowOnly((l) => !l); setPage(1) }}
            className={clsx(
              'flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-medium ring-1 ring-inset transition-colors',
              lowOnly
                ? 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/30'
                : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
            )}
          >
            <Boxes className="size-4" /> Running low
          </button>
        </div>
      </Card>

      {isLoading ? (
        <Card><div className="flex justify-center py-10"><Spinner /></div></Card>
      ) : !data || data.data.length === 0 ? (
        <EmptyState
          title="Nothing on the list yet"
          hint="Add what this company sells — a price and a tax rate are worth having even for a service."
        />
      ) : (
        <Card>
          <TableBox>
            <table className="w-full min-w-[820px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Item</th>
                  <th className="py-2 pr-3 font-medium">Company</th>
                  <th className="py-2 pr-3 text-right font-medium">Price</th>
                  <th className="py-2 pr-3 text-right font-medium">Tax</th>
                  <th className="py-2 pr-3 text-right font-medium">In stock</th>
                  <th className="py-2 pr-3 font-medium" />
                </tr>
              </thead>
              <tbody>
                {data.data.map((item) => (
                  <tr
                    key={item.uuid}
                    className={clsx(
                      'border-b border-slate-50 last:border-0 dark:border-slate-800/50',
                      !item.is_active && 'opacity-50',
                    )}
                  >
                    <td className="py-2.5 pr-3">
                      <div className="font-medium text-slate-800 dark:text-slate-100">{item.name}</div>
                      <div className="text-xs text-slate-400">
                        {item.kind === 'service' ? 'Service' : 'Product'}
                        {item.code && <> · {item.code}</>}
                        {!item.is_active && <> · switched off</>}
                      </div>
                    </td>
                    <td className="max-w-[160px] truncate py-2.5 pr-3 text-slate-500">{item.issuing_company ?? '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right">{money(item.unit_price, currencyOf(item))}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right text-slate-500">
                      {item.tax_rate === null ? '—' : `${Number(item.tax_rate)}%`}
                    </td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-right">
                      {/* A service has no count, and a zero would read as
                          "none left" for something that cannot run out. */}
                      {item.kind === 'service' ? (
                        <span className="text-slate-400">—</span>
                      ) : (
                        <span className={clsx(
                          'font-medium',
                          (item.quantity ?? 0) <= 0 ? 'text-red-500'
                            : item.low ? 'text-amber-600 dark:text-amber-400'
                              : 'text-slate-700 dark:text-slate-200',
                        )}>
                          {count(item.quantity)}{item.unit ? ` ${item.unit}` : ''}
                        </span>
                      )}
                    </td>
                    <td className="py-2.5 pr-3">
                      <div className="flex items-center justify-end gap-1">
                        <button
                          type="button"
                          title="Why it says that"
                          onClick={() => setHistory(item)}
                          className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"
                        >
                          <History className="size-4" />
                        </button>
                        {canEdit && item.kind === 'product' && (
                          <Button variant="ghost" className="px-2 py-1 text-xs" onClick={() => setMoving(item)}>
                            Move stock
                          </Button>
                        )}
                        {canEdit && (
                          <button
                            type="button"
                            title="Edit"
                            onClick={() => openEdit(item)}
                            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"
                          >
                            <Pencil className="size-4" />
                          </button>
                        )}
                        {canDelete && (
                          <button
                            type="button"
                            title="Remove"
                            onClick={() => {
                              if (confirm(`Remove ${item.name} from the list?`)) removeMutation.mutate(item.uuid)
                            }}
                            className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-500/10"
                          >
                            <Trash2 className="size-4" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableBox>
          <Pager resp={data} onPage={setPage} />
        </Card>
      )}

      {showForm && (
        <Modal title={editing ? 'Edit item' : 'Add an item'} onClose={() => setShowForm(false)}>
          <div className="space-y-3">
            <ErrorNote message={error} />
            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <Label>Company</Label>
                <Select
                  value={form.issuing_company_id}
                  disabled={!!editing}
                  onChange={(e) => {
                    const picked = companies.find((c) => String(c.id) === e.target.value)
                    setForm((f) => ({
                      ...f,
                      issuing_company_id: e.target.value,
                      // What the company says it sells is the right first
                      // answer, never a locked one.
                      kind: picked?.sells === 'services' ? 'service' : 'product',
                    }))
                  }}
                  className="w-full"
                >
                  <option value="">Select</option>
                  {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </Select>
                {editing && <p className="mt-1 text-xs text-slate-400">The shelf it sits on cannot change once it has been sold from.</p>}
              </div>
              <div>
                <Label>Product or service</Label>
                <Select value={form.kind} onChange={(e) => set('kind', e.target.value)} className="w-full">
                  <option value="product">Product — counted</option>
                  <option value="service">Service — not counted</option>
                </Select>
              </div>
              <div className="sm:col-span-2">
                <Label>Name</Label>
                <Input value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="As it should read on the invoice" className="w-full" />
              </div>
              <div>
                <Label>Code (optional)</Label>
                <Input value={form.code} onChange={(e) => set('code', e.target.value)} placeholder="SKU, HSN, part no." className="w-full" />
              </div>
              <div>
                <Label>Unit (optional)</Label>
                <Input value={form.unit} onChange={(e) => set('unit', e.target.value)} placeholder="pcs, kg, hours" className="w-full" />
              </div>
              <div>
                <Label>Price</Label>
                <Input type="number" min="0" step="0.01" value={form.unit_price} onChange={(e) => set('unit_price', e.target.value)} className="w-full" />
              </div>
              <div>
                <Label>Tax %</Label>
                <Input type="number" min="0" max="100" step="0.01" value={form.tax_rate} onChange={(e) => set('tax_rate', e.target.value)} className="w-full" />
                <p className="mt-1 text-xs text-slate-400">
                  Fills the document's tax when this is on it. Whether that lands as CGST + SGST or as IGST is still the place of supply's business.
                </p>
              </div>
              {form.kind === 'product' && (
                <>
                  <div>
                    <Label>{editing ? 'In stock' : 'Opening count'}</Label>
                    <Input
                      type="number"
                      step="0.001"
                      value={form.quantity}
                      disabled={!!editing}
                      onChange={(e) => set('quantity', e.target.value)}
                      className="w-full"
                    />
                    {editing && <p className="mt-1 text-xs text-slate-400">Use Move stock — a count changes for a reason, and the reason is kept.</p>}
                  </div>
                  <div>
                    <Label>Tell me when it drops to (optional)</Label>
                    <Input type="number" min="0" step="0.001" value={form.reorder_at} onChange={(e) => set('reorder_at', e.target.value)} className="w-full" />
                  </div>
                </>
              )}
              <div className="sm:col-span-2">
                <Label>Description (optional)</Label>
                <Textarea rows={2} value={form.description} onChange={(e) => set('description', e.target.value)} className="w-full" />
              </div>
            </div>
            <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(e) => set('is_active', e.target.checked)}
                className="size-4 accent-brand-600"
              />
              On the list — offered when raising a document
            </label>
            <Button
              className="w-full"
              disabled={!form.issuing_company_id || !form.name.trim() || saveMutation.isPending}
              onClick={() => saveMutation.mutate()}
            >
              {saveMutation.isPending ? 'Saving…' : editing ? 'Save' : 'Add to the list'}
            </Button>
          </div>
        </Modal>
      )}

      {moving && <MoveStock item={moving} onClose={() => setMoving(null)} onDone={() => { setMoving(null); refresh() }} />}
      {history && <Moves item={history} onClose={() => setHistory(null)} />}
    </div>
  )
}

/**
 * A count, moved, with a reason.
 *
 * Two ways of saying it, because people do both: "three more came in", and
 * "there are nine on the shelf, whatever the app thinks".
 */
function MoveStock({ item, onClose, onDone }: { item: CrmInventoryItem; onClose: () => void; onDone: () => void }) {
  const { toast } = useToast()
  const [mode, setMode] = useState<'by' | 'counted'>('by')
  const [qty, setQty] = useState('')
  const [reason, setReason] = useState('purchase')
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)

  const saveMutation = useMutation({
    mutationFn: () => crm.inventory.adjust(item.uuid, {
      ...(mode === 'by' ? { qty: Number(qty) } : { counted: Number(qty) }),
      reason,
      note: note || null,
    }),
    onSuccess: (res) => { toast(res.message, 'success'); onDone() },
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={`Move stock — ${item.name}`} onClose={onClose}>
      <div className="space-y-3">
        <ErrorNote message={error} />
        <p className="text-sm text-slate-500">
          {count(item.quantity)}{item.unit ? ` ${item.unit}` : ''} on the shelf now.
        </p>

        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>What happened</Label>
            <Select
              value={reason}
              onChange={(e) => {
                setReason(e.target.value)
                // Counting the shelf is a statement about the total, not a change to it.
                setMode(e.target.value === 'adjustment' ? 'counted' : 'by')
              }}
              className="w-full"
            >
              {REASONS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
            </Select>
          </div>
          <div>
            <Label>{mode === 'counted' ? 'There are' : 'How many'}</Label>
            <Input
              type="number"
              step="0.001"
              value={qty}
              onChange={(e) => setQty(e.target.value)}
              placeholder={mode === 'counted' ? 'The number on the shelf' : 'Negative to take some out'}
              className="w-full"
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Note (optional)</Label>
            <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Three broken in transit…" className="w-full" />
          </div>
        </div>

        <Button className="w-full" disabled={qty === '' || saveMutation.isPending} onClick={() => saveMutation.mutate()}>
          {saveMutation.isPending ? 'Saving…' : 'Record it'}
        </Button>
      </div>
    </Modal>
  )
}

/** Why the count reads the way it does. */
function Moves({ item, onClose }: { item: CrmInventoryItem; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'inventory', item.uuid, 'moves'],
    queryFn: () => crm.inventory.moves(item.uuid),
  })

  return (
    <Modal title={`${item.name} — what moved`} onClose={onClose} wide>
      {isLoading ? (
        <div className="flex justify-center py-8"><Spinner /></div>
      ) : !data || data.data.length === 0 ? (
        <p className="py-6 text-center text-sm text-slate-400">Nothing has moved yet.</p>
      ) : (
        <TableBox>
          <table className="w-full min-w-[520px] text-sm">
            <tbody>
              {data.data.map((m) => (
                <tr key={m.id} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                  <td className="whitespace-nowrap py-2 pr-3 text-slate-500">{m.at?.slice(0, 16)}</td>
                  <td className={clsx('whitespace-nowrap py-2 pr-3 font-medium', m.qty < 0 ? 'text-red-500' : 'text-emerald-600')}>
                    {m.qty > 0 ? '+' : ''}{count(m.qty)}
                  </td>
                  <td className="py-2 pr-3 capitalize text-slate-600 dark:text-slate-300">{m.reason}</td>
                  <td className="max-w-[220px] truncate py-2 pr-3 text-slate-500">
                    {m.invoice ? m.invoice.number : (m.note ?? '—')}
                  </td>
                  <td className="whitespace-nowrap py-2 text-slate-400">{m.by ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableBox>
      )}
    </Modal>
  )
}
