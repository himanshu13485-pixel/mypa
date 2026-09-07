import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Boxes, History, Plus, Trash2, Undo2, UserCheck, Wrench } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmMeQuery, type CrmAsset } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Input, Label, Modal, Select, Spinner } from '../../components/ui'

const STATUS_STYLE: Record<CrmAsset['status'], string> = {
  in_stock: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
  allocated: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
  damaged: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400',
}
const STATUS_LABEL: Record<CrmAsset['status'], string> = {
  in_stock: 'In stock', allocated: 'Allocated', damaged: 'Damaged',
}

/**
 * The Office Assets register: one window that answers who holds what, when
 * it came back, what stock is left, and what sits damaged. Items live for
 * life — allocations, returns and repairs are events, not edits.
 */
export default function CrmAssetsPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [status, setStatus] = useState('')
  const [holder, setHolder] = useState('')
  const [category, setCategory] = useState('')
  const [search, setSearch] = useState('')
  const [showAdd, setShowAdd] = useState(false)
  const [editing, setEditing] = useState<CrmAsset | null>(null)
  const [allocating, setAllocating] = useState<CrmAsset | null>(null)
  const [returning, setReturning] = useState<CrmAsset | null>(null)
  const [historyOf, setHistoryOf] = useState<CrmAsset | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'assets', status, category, search, holder],
    queryFn: () => crm.assets.list({ status: status || undefined, category: category || undefined, search: search || undefined, member: holder || undefined }),
  })
  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data: me } = useQuery(crmMeQuery())

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'assets'] })
  const act = (fn: () => Promise<{ message: string }>) =>
    fn().then((res) => { toast(res.message, 'success'); refresh() }).catch((err) => toastError(errorMessage(err)))

  const manages = data?.manages ?? false

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-white">
            <Boxes className="size-5 text-emerald-500" /> Office Assets
          </h1>
          <p className="text-sm text-slate-500">
            {manages
              ? 'Everything the company hands out, for life — who holds what, what stock is left.'
              : 'The items in your hands.'}
          </p>
        </div>
        {manages && (
          <Button onClick={() => setShowAdd(true)}><Plus className="size-4" /> Add items</Button>
        )}
      </div>

      {data && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {([['Total items', data.summary.total, ''], ['In stock', data.summary.in_stock, 'text-emerald-600'],
            ['Allocated', data.summary.allocated, 'text-sky-600'], ['Damaged', data.summary.damaged, 'text-red-500']] as const)
            .map(([label, value, tone]) => (
              <Card key={label} className="py-3">
                <div className={clsx('text-lg font-semibold text-slate-900 dark:text-white', tone)}>{value}</div>
                <div className="text-xs text-slate-500">{label}</div>
              </Card>
            ))}
        </div>
      )}

      <Card>
        <div className="mb-3 flex flex-wrap gap-2">
          <Select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">Any status</option>
            <option value="in_stock">In stock</option>
            <option value="allocated">Allocated</option>
            <option value="damaged">Damaged</option>
          </Select>
          <Select value={category} onChange={(e) => setCategory(e.target.value)}>
            <option value="">Any category</option>
            {(data?.categories ?? []).map((c) => <option key={c} value={c}>{c}</option>)}
          </Select>
          {/* Person-wise: managers pick anyone; a Team Workspace leader
              their own people. */}
          {(manages || !!me?.has_team) && (
            <Select value={holder} onChange={(e) => setHolder(e.target.value)} title="Held by">
              <option value="">Any holder</option>
              {(masters?.members ?? [])
                .filter((m) => (m.crm_role ?? 'employee') !== 'admin')
                .filter((m) => manages || (me?.member?.team_member_uuids ?? []).includes(m.uuid))
                .map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
            </Select>
          )}
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name / model / serial…" />
        </div>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No assets found" hint={manages ? 'Add the first items — they land in stock.' : 'Nothing allocated to you yet.'} />
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[900px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Item</th>
                  <th className="py-2 pr-3 font-medium">Category</th>
                  <th className="py-2 pr-3 font-medium">Model / Serial</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 pr-3 font-medium">Holder</th>
                  <th className="py-2 pr-3 font-medium">Since</th>
                  {manages && <th className="py-2 font-medium" />}
                </tr>
              </thead>
              <tbody>
                {data.data.map((a) => (
                  <tr key={a.uuid} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60 dark:border-slate-800/50 dark:hover:bg-slate-800/40">
                    <td className="py-2.5 pr-3">
                      <button
                        onClick={() => manages && setEditing(a)}
                        className={clsx('font-medium', manages ? 'text-emerald-600 hover:underline' : 'text-slate-700 dark:text-slate-200')}
                      >
                        {a.name}
                      </button>
                      {a.color && <div className="text-xs text-slate-400">{a.color}</div>}
                    </td>
                    <td className="py-2.5 pr-3">{a.category}</td>
                    <td className="py-2.5 pr-3 text-xs text-slate-500">
                      {[a.model_no, a.serial_no].filter(Boolean).join(' · ') || '—'}
                    </td>
                    <td className="py-2.5 pr-3">
                      <span className={clsx('rounded-full px-2 py-0.5 text-[11px] font-medium', STATUS_STYLE[a.status])}>
                        {STATUS_LABEL[a.status]}
                      </span>
                    </td>
                    <td className="py-2.5 pr-3">{a.holder?.name ?? '—'}</td>
                    <td className="whitespace-nowrap py-2.5 pr-3 text-xs text-slate-500">{a.allocated_at?.slice(0, 10) ?? '—'}</td>
                    {manages && (
                      <td className="whitespace-nowrap py-2.5 text-right">
                        {a.status === 'in_stock' && (
                          <button onClick={() => setAllocating(a)} title="Allocate to an employee" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                            <UserCheck className="size-4" />
                          </button>
                        )}
                        {a.status === 'allocated' && (
                          <button onClick={() => setReturning(a)} title="Record a return" className="rounded p-1.5 text-slate-400 hover:text-sky-600">
                            <Undo2 className="size-4" />
                          </button>
                        )}
                        {a.status === 'damaged' && (
                          <button onClick={() => act(() => crm.assets.repaired(a.uuid))} title="Repaired — back to stock" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                            <Wrench className="size-4" />
                          </button>
                        )}
                        <button onClick={() => setHistoryOf(a)} title="History" className="rounded p-1.5 text-slate-400 hover:text-slate-600">
                          <History className="size-4" />
                        </button>
                        {data.can_delete && a.status !== 'allocated' && (
                          <button
                            onClick={() => { if (confirm('Remove this item from the register? Its history stays on the trail.')) act(() => crm.assets.remove(a.uuid)) }}
                            title="Remove (beyond repair)"
                            className="rounded p-1.5 text-slate-400 hover:text-red-500"
                          >
                            <Trash2 className="size-4" />
                          </button>
                        )}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {(showAdd || editing) && (
        <AssetModal
          asset={editing}
          categories={data?.categories ?? []}
          onClose={() => { setShowAdd(false); setEditing(null) }}
          onDone={(msg) => { setShowAdd(false); setEditing(null); refresh(); toast(msg, 'success') }}
        />
      )}
      {allocating && (
        <Modal title={`Allocate — ${allocating.name}`} onClose={() => setAllocating(null)}>
          <AllocateForm
            members={(masters?.members ?? []).filter((m) => (m.crm_role ?? 'employee') !== 'admin')}
            onSubmit={(memberUuid, note) => { act(() => crm.assets.allocate(allocating.uuid, memberUuid, note)); setAllocating(null) }}
          />
        </Modal>
      )}
      {returning && (
        <Modal title={`Return — ${returning.name}`} onClose={() => setReturning(null)}>
          <ReturnForm
            holder={returning.holder?.name ?? null}
            onSubmit={(damaged, note) => { act(() => crm.assets.returnAsset(returning.uuid, damaged, note)); setReturning(null) }}
          />
        </Modal>
      )}
      {historyOf && <HistoryModal asset={historyOf} onClose={() => setHistoryOf(null)} />}
    </div>
  )
}

function AssetModal({ asset, categories, onClose, onDone }: {
  asset: CrmAsset | null
  categories: string[]
  onClose: () => void
  onDone: (message: string) => void
}) {
  const { toastError } = useToast()
  const [form, setForm] = useState({
    category: asset?.category ?? categories[0] ?? 'Other',
    name: asset?.name ?? '',
    model_no: asset?.model_no ?? '',
    color: asset?.color ?? '',
    serial_no: asset?.serial_no ?? '',
    details: asset?.details ?? '',
    purchased_on: asset?.purchased_on ?? '',
    note: asset?.note ?? '',
    quantity: '1',
  })
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        category: form.category, name: form.name,
        model_no: form.model_no || null, color: form.color || null,
        serial_no: form.serial_no || null, details: form.details || null,
        purchased_on: form.purchased_on || null, note: form.note || null,
      }
      return asset
        ? crm.assets.update(asset.uuid, payload)
        : crm.assets.create({ ...payload, quantity: Number(form.quantity) || 1 })
    },
    onSuccess: (res) => onDone(res.message),
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <Modal title={asset ? `Edit — ${asset.name}` : 'Add items to stock'} onClose={onClose} wide>
      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <Label>Category</Label>
          <Select value={form.category} onChange={(e) => set('category', e.target.value)} className="w-full">
            {categories.map((c) => <option key={c} value={c}>{c}</option>)}
          </Select>
        </div>
        <div>
          <Label>Item name</Label>
          <Input value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="Dell Latitude 5420" className="w-full" />
        </div>
        <div>
          <Label>Model no.</Label>
          <Input value={form.model_no} onChange={(e) => set('model_no', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Serial no. / IMEI / SIM number</Label>
          <Input value={form.serial_no} onChange={(e) => set('serial_no', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Colour</Label>
          <Input value={form.color} onChange={(e) => set('color', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Purchased on</Label>
          <Input type="date" value={form.purchased_on} onChange={(e) => set('purchased_on', e.target.value)} className="w-full" />
        </div>
        <div className="sm:col-span-2">
          <Label>More details (capacity, RAM, plan…)</Label>
          <Input value={form.details} onChange={(e) => set('details', e.target.value)} className="w-full" />
        </div>
        {!asset && (
          <div>
            <Label>Quantity (bulk — each becomes its own item)</Label>
            <Input type="number" min="1" max="100" value={form.quantity} onChange={(e) => set('quantity', e.target.value)} className="w-full" />
          </div>
        )}
        <div className={asset ? 'sm:col-span-2' : ''}>
          <Label>Note</Label>
          <Input value={form.note} onChange={(e) => set('note', e.target.value)} className="w-full" />
        </div>
      </div>
      <Button className="mt-3 w-full" disabled={!form.name || save.isPending} onClick={() => save.mutate()}>
        {save.isPending ? 'Saving…' : asset ? 'Save changes' : 'Add to stock'}
      </Button>
    </Modal>
  )
}

function AllocateForm({ members, onSubmit }: {
  members: { uuid: string; name: string | null; employee_code: string | null }[]
  onSubmit: (memberUuid: string, note?: string) => void
}) {
  const [memberUuid, setMemberUuid] = useState('')
  const [note, setNote] = useState('')

  return (
    <div className="space-y-3">
      <div>
        <Label>Employee</Label>
        <Select value={memberUuid} onChange={(e) => setMemberUuid(e.target.value)} className="w-full">
          <option value="">Select…</option>
          {members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}{m.employee_code ? ` (${m.employee_code})` : ''}</option>)}
        </Select>
      </div>
      <div>
        <Label>Note</Label>
        <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="With charger and bag" className="w-full" />
      </div>
      <Button className="w-full" disabled={!memberUuid} onClick={() => onSubmit(memberUuid, note || undefined)}>
        Allocate
      </Button>
    </div>
  )
}

function ReturnForm({ holder, onSubmit }: { holder: string | null; onSubmit: (damaged: boolean, note?: string) => void }) {
  const [damaged, setDamaged] = useState(false)
  const [note, setNote] = useState('')

  return (
    <div className="space-y-3">
      {holder && <p className="text-sm text-slate-500">Coming back from <span className="font-medium">{holder}</span>.</p>}
      <label className="flex items-start gap-2 rounded-xl bg-red-50/60 p-3 text-sm text-slate-600 dark:bg-red-500/5 dark:text-slate-300">
        <input type="checkbox" checked={damaged} onChange={(e) => setDamaged(e.target.checked)} className="mt-0.5 size-4 accent-red-500" />
        <span>
          <span className="font-medium text-slate-800 dark:text-slate-100">Returned damaged</span>
          <span className="block text-xs text-slate-400">It sits aside until marked repaired, or removed if beyond repair.</span>
        </span>
      </label>
      <div>
        <Label>Note</Label>
        <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Screen cracked / all fine" className="w-full" />
      </div>
      <Button className="w-full" onClick={() => onSubmit(damaged, note || undefined)}>Record return</Button>
    </div>
  )
}

function HistoryModal({ asset, onClose }: { asset: CrmAsset; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'asset-history', asset.uuid],
    queryFn: () => crm.assets.history(asset.uuid),
  })

  const LABELS: Record<string, string> = {
    created: 'Entered into stock', allocated: 'Allocated', returned: 'Returned',
    damaged: 'Returned damaged', repaired: 'Repaired',
  }

  return (
    <Modal title={`History — ${asset.name}`} onClose={onClose}>
      {isLoading ? <div className="flex justify-center py-8"><Spinner /></div> : (
        <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
          {(data ?? []).map((e, i) => (
            <li key={i} className="py-2">
              <div className="flex items-baseline justify-between gap-2">
                <span className="font-medium text-slate-700 dark:text-slate-200">
                  {LABELS[e.action] ?? e.action}{e.member ? ` — ${e.member}` : ''}
                </span>
                <span className="shrink-0 text-xs text-slate-400">{e.at?.slice(0, 16)}</span>
              </div>
              {(e.note || e.by) && (
                <div className="text-xs text-slate-400">{[e.note, e.by ? `by ${e.by}` : null].filter(Boolean).join(' · ')}</div>
              )}
            </li>
          ))}
        </ul>
      )}
    </Modal>
  )
}
