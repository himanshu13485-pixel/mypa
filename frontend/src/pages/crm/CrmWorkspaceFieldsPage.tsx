import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronDown, ChevronUp, Clock, Pencil, Plus, RotateCcw, Sparkles, Trash2 } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmMeQuery, CRM_DCW_ENTITY_LABELS, CRM_FIELD_TYPE_LABELS, CRM_TAX_KIND_LABELS, type CrmCustomField } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Select, Spinner, Textarea } from '../../components/ui'

function statusBadge(status: string) {
  return clsx(
    'rounded-full px-2 py-0.5 text-[11px] font-medium',
    status === 'approved' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
    status === 'pending' && 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
    status === 'rejected' && 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
  )
}

const EMPTY = {
  entity: 'client', label: '', type: 'text', options: '', is_required: false,
  help: '', reason: '', builtin_key: '', is_hidden: false,
  // Money lines only.
  tax_kind: 'tax', tax_basis: 'taxable', default_rate: '',
}

/**
 * Dedicated Company Workspace: the fields this company has asked for. A new
 * field waits for the Super Admin, then appears on this workspace's form —
 * and only this one.
 */
export default function CrmWorkspaceFieldsPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ ...EMPTY })
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({ queryKey: ['crm', 'workspace-fields'], queryFn: crm.workspaceFields.list })
  const { data: me } = useQuery(crmMeQuery())

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'workspace-fields'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'masters'] })
  }

  const requestMutation = useMutation({
    mutationFn: () =>
      crm.workspaceFields.request({
        entity: form.entity,
        label: form.label,
        type: form.type,
        builtin_key: form.builtin_key || null,
        is_hidden: form.is_hidden,
        tax_kind: form.entity === 'tax' ? form.tax_kind : null,
        tax_basis: form.entity === 'tax' ? form.tax_basis : null,
        default_rate: form.entity === 'tax' && form.default_rate !== '' ? Number(form.default_rate) : null,
        options: form.type === 'select'
          ? form.options.split(/[\n,]+/).map((o) => o.trim()).filter(Boolean)
          : null,
        is_required: form.is_required,
        help: form.help || null,
        reason: form.reason || null,
      }),
    onSuccess: (res) => {
      refresh()
      setShowForm(false)
      setForm({ ...EMPTY })
      toast(res.message, 'success')
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const removeMutation = useMutation({
    mutationFn: (uuid: string) => crm.workspaceFields.remove(uuid),
    onSuccess: (res: { message?: string }) => { refresh(); toast(res.message ?? 'Removed.', 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const reorderMutation = useMutation({
    mutationFn: ({ entity, uuids }: { entity: string; uuids: string[] }) =>
      crm.workspaceFields.reorder(entity, uuids),
    onSuccess: () => refresh(),
    onError: (err) => toastError(errorMessage(err)),
  })

  /*
   * Moving a field one place within its own entity.
   *
   * The neighbours are worked out from the list as the server returned it,
   * which is already in `sort` order — so "one place" means one place among
   * the fields this arrow can actually move it past, and not one row on a
   * screen that is showing four entities interleaved.
   *
   * Pending fields are skipped on both counts: they are not on any document
   * yet, so they have no position to swap into.
   */
  const orderedPeers = (f: CrmCustomField) =>
    (data?.data ?? []).filter((x) => x.entity === f.entity && x.status === 'approved')

  const canMove = (f: CrmCustomField, step: -1 | 1) => {
    const peers = orderedPeers(f)
    const at = peers.findIndex((x) => x.uuid === f.uuid)

    return at >= 0 && at + step >= 0 && at + step < peers.length
  }

  const move = (f: CrmCustomField, step: -1 | 1) => {
    const peers = orderedPeers(f)
    const at = peers.findIndex((x) => x.uuid === f.uuid)
    if (!canMove(f, step)) return

    const next = peers.map((x) => x.uuid)
    ;[next[at], next[at + step]] = [next[at + step], next[at]]

    reorderMutation.mutate({ entity: f.entity, uuids: next })
  }

  const set = (key: keyof typeof EMPTY, value: string | boolean) => setForm((f) => ({ ...f, [key]: value }))

  /** Re-word one of our own columns rather than add a new one. */
  const openColumn = (entity: string, key: string) => {
    const method = entity === 'work_order' ? data?.work_order_method : data?.invoice_method
    const column = (method ?? []).find((c) => c.source === 'builtin' && c.key === key)
    const line = entity === 'tax' ? (data?.tax_setup ?? []).find((l) => l.key === key) : undefined
    const builtin = data?.builtins?.[entity]?.[key]

    setForm({
      ...EMPTY,
      entity,
      builtin_key: key,
      label: column?.label ?? line?.label ?? builtin?.label ?? '',
      type: entity === 'tax' ? 'number' : column?.type ?? builtin?.type ?? 'text',
      default_rate: line?.default_rate !== null && line?.default_rate !== undefined ? String(line.default_rate) : '',
    })
    setError(null)
    setShowForm(true)
  }

  const activeBuiltin = form.builtin_key ? data?.builtins?.[form.entity]?.[form.builtin_key] : undefined
  const isTax = form.entity === 'tax'

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Workspace fields</h1>
          <p className="text-sm text-slate-500">
            Extra fields for {me?.organization?.name ?? 'this company'} only — the Super Admin approves each one before it goes live.
          </p>
        </div>
        <Button onClick={() => { setError(null); setShowForm(true) }}>
          <Plus className="size-4" /> Request a field
        </Button>
      </div>

      {/* Our forms are only a starting point: every company words them its own
          way, drops what it does not use, and adds what it does. */}
      {([
        {
          entity: 'invoice',
          title: 'Document fields',
          hint: 'The head of every proforma and invoice.',
          columns: data?.invoice_method ?? [],
        },
        {
          entity: 'work_order',
          title: 'Work Order columns',
          hint: 'The lines of every proforma and invoice.',
          columns: data?.work_order_method ?? [],
        },
      ] as const).map((section) => (
        <Card key={section.entity}>
          <div className="mb-3">
            <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">{section.title}</h2>
            <p className="text-xs text-slate-400">
              {section.hint} Changes go to the Super Admin like any other workspace field.
            </p>
          </div>
          <div className="space-y-2">
            {section.columns.filter((c) => c.source === 'builtin').map((c) => {
              const builtin = data?.builtins?.[section.entity]?.[c.key]
              const row = data?.data.find((f) => f.is_builtin && f.entity === section.entity && f.key === c.key)

              return (
                <div key={c.key} className="flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 px-4 py-2.5 dark:bg-slate-800/60">
                  <div className="min-w-0 flex-1">
                    <div className={clsx('font-medium', c.hidden
                      ? 'text-slate-400 line-through dark:text-slate-500'
                      : 'text-slate-800 dark:text-slate-100')}>
                      {c.label}{c.is_required && <span className="ml-1 text-red-500">*</span>}
                    </div>
                    <div className="text-xs text-slate-400">
                      {builtin && c.label !== builtin.label && <>was “{builtin.label}” · </>}
                      {CRM_FIELD_TYPE_LABELS[c.type] ?? c.type}
                      {c.options && c.options.length > 0 && <> · {c.options.join(', ')}</>}
                      {builtin && <> · can {builtin.can.join(', ')}</>}
                    </div>
                  </div>
                  {row?.status === 'pending' && <span className={statusBadge('pending')}>Awaiting Super Admin</span>}
                  {c.customised && row?.status !== 'pending' && <span className={statusBadge('approved')}>Customised</span>}
                  {row ? (
                    <button
                      onClick={() => {
                        if (confirm(`Restore “${builtin?.label ?? c.key}” to its default?`)) removeMutation.mutate(row.uuid)
                      }}
                      aria-label="Restore default"
                      className="rounded p-1.5 text-slate-400 hover:text-red-500"
                    >
                      <RotateCcw className="size-4" />
                    </button>
                  ) : (
                    <Button size="sm" variant="secondary" onClick={() => openColumn(section.entity, c.key)}>
                      <Pencil className="size-3.5" /> Customise
                    </Button>
                  )}
                </div>
              )
            })}
          </div>
        </Card>
      ))}

      {/* The money lines. A company charges what it charges — two taxes or
          five, under its own names, with its own standing rates. */}
      <Card>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Tax lines</h2>
            <p className="text-xs text-slate-400">
              What sits between the subtotal and the grand total, in the order shown.
            </p>
          </div>
          <Button
            size="sm"
            variant="secondary"
            onClick={() => { setForm({ ...EMPTY, entity: 'tax', type: 'number' }); setError(null); setShowForm(true) }}
          >
            <Plus className="size-3.5" /> Add a line
          </Button>
        </div>
        <div className="space-y-2">
          {(data?.tax_setup ?? []).map((line) => {
            const builtin = data?.builtins?.tax?.[line.key]
            const row = data?.data.find((f) => f.entity === 'tax' && f.key === line.key)

            return (
              <div key={line.key} className="flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 px-4 py-2.5 dark:bg-slate-800/60">
                <div className="min-w-0 flex-1">
                  <div className="font-medium text-slate-800 dark:text-slate-100">{line.label}</div>
                  <div className="text-xs text-slate-400">
                    {CRM_TAX_KIND_LABELS[line.kind] ?? line.kind}
                    {' · on the '}{line.basis === 'subtotal' ? 'subtotal' : 'taxable value'}
                    {line.default_rate !== null && <> · standing rate {line.default_rate}%</>}
                  </div>
                </div>
                {row?.status === 'pending' && <span className={statusBadge('pending')}>Awaiting Super Admin</span>}
                {row ? (
                  <button
                    onClick={() => {
                      const msg = line.source === 'builtin'
                        ? `Restore “${builtin?.label ?? line.key}” to its default?`
                        : `Remove the “${line.label}” line? Documents already raised keep it.`
                      if (confirm(msg)) removeMutation.mutate(row.uuid)
                    }}
                    aria-label="Remove"
                    className="rounded p-1.5 text-slate-400 hover:text-red-500"
                  >
                    {line.source === 'builtin' ? <RotateCcw className="size-4" /> : <Trash2 className="size-4" />}
                  </button>
                ) : (
                  <Button size="sm" variant="secondary" onClick={() => openColumn('tax', line.key)}>
                    <Pencil className="size-3.5" /> Customise
                  </Button>
                )}
              </div>
            )
          })}
          {/* A line switched off is not in the setup any more, so it is listed
              here on its own with the way back. */}
          {(data?.data ?? []).filter((f) => f.entity === 'tax' && f.is_builtin && f.is_hidden && f.status === 'approved').map((f) => (
            <div key={f.uuid} className="flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 px-4 py-2.5 dark:bg-slate-800/60">
              <div className="min-w-0 flex-1">
                <div className="font-medium text-slate-400 line-through dark:text-slate-500">{f.label}</div>
                <div className="text-xs text-slate-400">Not charged — off your documents.</div>
              </div>
              <button
                onClick={() => { if (confirm(`Charge “${f.label}” again?`)) removeMutation.mutate(f.uuid) }}
                aria-label="Restore"
                className="rounded p-1.5 text-slate-400 hover:text-emerald-500"
              >
                <RotateCcw className="size-4" />
              </button>
            </div>
          ))}
        </div>
      </Card>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState
            title="No extra fields yet"
            hint="Ask for the fields your business needs — they appear on your forms once approved."
          />
        ) : (
          <div className="space-y-2">
            {data.data.map((f: CrmCustomField) => (
              <div key={f.uuid} className="flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
                {f.status === 'approved'
                  ? <Sparkles className="size-5 shrink-0 text-emerald-500" />
                  : <Clock className="size-5 shrink-0 text-amber-500" />}
                <div className="min-w-0 flex-1">
                  <div className="font-medium text-slate-800 dark:text-slate-100">
                    {f.label}
                    {f.is_required && <span className="ml-1 text-red-500">*</span>}
                  </div>
                  <div className="text-xs text-slate-400">
                    {CRM_DCW_ENTITY_LABELS[f.entity] ?? f.entity} · {CRM_FIELD_TYPE_LABELS[f.type] ?? f.type}
                    {f.options && f.options.length > 0 && <> · {f.options.join(', ')}</>}
                  </div>
                  {/* The paper trail: asked when and by whom, decided when
                      and by whom — the same facts the Super Admin sees. */}
                  <div className="mt-0.5 text-xs text-slate-400">
                    Requested {f.created_at?.slice(0, 16) ?? '—'}
                    {f.requested_by && <> by {f.requested_by}</>}
                    {f.decided_at && (
                      <> · {f.status === 'approved' ? 'Approved' : 'Rejected'} {f.decided_at.slice(0, 16)}
                        {f.decided_by && <> by {f.decided_by}</>}</>
                    )}
                  </div>
                  {f.decision_note && <div className="mt-0.5 text-xs text-red-400">Note: {f.decision_note}</div>}
                </div>
                <span className={statusBadge(f.status)}>
                  {f.status === 'pending' ? 'Awaiting Super Admin' : f.status}
                </span>
                {/*
                  * Where this field sits on the form and on the printed
                  * document. One order, not two — the form, the PDF and the
                  * validator all read the same method, so moving it here
                  * moves it on the proforma and the invoice as well.
                  *
                  * Only among its own kind: a Work Order column and a
                  * document field are not in one list to be ordered against
                  * each other, so the arrows step within an entity and stop
                  * at its ends. Approved fields only — a pending one has no
                  * place on a document to be moved around on yet.
                  */}
                {f.status === 'approved' && (
                  <div className="flex shrink-0 items-center">
                    <button
                      onClick={() => move(f, -1)}
                      disabled={reorderMutation.isPending || !canMove(f, -1)}
                      aria-label={`Move ${f.label} earlier`}
                      title="Move up — earlier on the form and the document"
                      className="rounded p-1.5 text-slate-400 hover:text-emerald-600 disabled:opacity-30 disabled:hover:text-slate-400"
                    >
                      <ChevronUp className="size-4" />
                    </button>
                    <button
                      onClick={() => move(f, 1)}
                      disabled={reorderMutation.isPending || !canMove(f, 1)}
                      aria-label={`Move ${f.label} later`}
                      title="Move down — later on the form and the document"
                      className="rounded p-1.5 text-slate-400 hover:text-emerald-600 disabled:opacity-30 disabled:hover:text-slate-400"
                    >
                      <ChevronDown className="size-4" />
                    </button>
                  </div>
                )}
                <button
                  onClick={() => {
                    const msg = f.status === 'approved'
                      ? `Remove "${f.label}" from your forms? Values already saved on clients are kept.`
                      : `Withdraw the request for "${f.label}"?`
                    if (confirm(msg)) removeMutation.mutate(f.uuid)
                  }}
                  aria-label="Remove"
                  className="rounded p-1.5 text-slate-400 hover:text-red-500"
                >
                  <Trash2 className="size-4" />
                </button>
              </div>
            ))}
          </div>
        )}
      </Card>

      {showForm && (
        <Modal
          title={activeBuiltin
            ? `Customise ${activeBuiltin.label}`
            : isTax ? 'Add a tax line' : 'Request a workspace field'}
          onClose={() => setShowForm(false)}
        >
          <div className="space-y-3">
            <ErrorNote message={error} />
            {activeBuiltin ? (
              <p className="rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/60">
                One of our Work Order columns. You can {activeBuiltin.can.join(', ')} it — the column keeps its
                current wording until the Super Admin decides.
              </p>
            ) : (
            <div>
              <Label>Which form</Label>
              <Select value={form.entity} onChange={(e) => set('entity', e.target.value)} className="w-full">
                {(data?.entities ?? ['client']).map((e) => <option key={e} value={e}>{CRM_DCW_ENTITY_LABELS[e] ?? e}</option>)}
              </Select>
              <p className="mt-1 text-xs text-slate-400">
                {form.entity === 'work_order'
                  ? 'Work Order fields become columns on every proforma and invoice line — your company writes its work order its own way.'
                  : 'Client fields sit on the add/edit client form.'}
              </p>
            </div>
            )}
            <div>
              <Label>{activeBuiltin ? 'Column heading' : 'Field label'}</Label>
              <Input
                value={form.label}
                onChange={(e) => set('label', e.target.value)}
                placeholder={activeBuiltin ? activeBuiltin.label : 'Port of loading'}
                className="w-full"
              />
            </div>
            {isTax && (
              <>
                {!form.builtin_key && (
                  <>
                    <div>
                      <Label>What kind of line</Label>
                      <Select value={form.tax_kind} onChange={(e) => set('tax_kind', e.target.value)} className="w-full">
                        {Object.entries(CRM_TAX_KIND_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                      </Select>
                    </div>
                    <div>
                      <Label>Charged on</Label>
                      <Select value={form.tax_basis} onChange={(e) => set('tax_basis', e.target.value)} className="w-full">
                        <option value="taxable">The taxable value (subtotal less discounts)</option>
                        <option value="subtotal">The subtotal</option>
                      </Select>
                    </div>
                  </>
                )}
                <div>
                  <Label>Standing rate (optional)</Label>
                  <Input
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    value={form.default_rate}
                    onChange={(e) => set('default_rate', e.target.value)}
                    placeholder="e.g. 9"
                    className="w-full"
                  />
                  <p className="mt-1 text-xs text-slate-400">
                    Filled in on every new document — whoever raises it can still change it.
                  </p>
                </div>
              </>
            )}
            {activeBuiltin?.can.includes('hide') && (
              <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" checked={form.is_hidden} onChange={(e) => set('is_hidden', e.target.checked)} className="size-4 accent-emerald-600" />
                We do not use this column — leave it off our documents
              </label>
            )}
            {!form.is_hidden && !isTax && (
              <div>
                <Label>Type</Label>
                <Select value={form.type} onChange={(e) => set('type', e.target.value)} className="w-full">
                  {/* A built-in column only takes the types its data can hold. */}
                  {(activeBuiltin ? activeBuiltin.types : Object.keys(CRM_FIELD_TYPE_LABELS))
                    .map((v) => <option key={v} value={v}>{CRM_FIELD_TYPE_LABELS[v] ?? v}</option>)}
                </Select>
                {activeBuiltin && !activeBuiltin.can.includes('type') && (
                  <p className="mt-1 text-xs text-slate-400">
                    This column can only be renamed — the line total is worked out from it.
                  </p>
                )}
              </div>
            )}
            {form.type === 'select' && !form.is_hidden && !isTax && (
              <div>
                <Label>Dropdown options (one per line)</Label>
                <Textarea rows={3} value={form.options} onChange={(e) => set('options', e.target.value)} placeholder={'Regular\nComposition'} className="w-full" />
              </div>
            )}
            <div>
              <Label>Helper text (optional)</Label>
              <Input value={form.help} onChange={(e) => set('help', e.target.value)} className="w-full" />
            </div>
            {!isTax && (
              <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" checked={form.is_required} onChange={(e) => set('is_required', e.target.checked)} className="size-4 accent-emerald-600" />
                Required when saving a record
              </label>
            )}
            <div>
              <Label>Why do you need it?</Label>
              <Textarea rows={2} value={form.reason} onChange={(e) => set('reason', e.target.value)} placeholder="Helps the Super Admin decide" className="w-full" />
            </div>
            <Button className="w-full" disabled={!form.label || requestMutation.isPending} onClick={() => requestMutation.mutate()}>
              {requestMutation.isPending ? 'Sending…' : 'Send for approval'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}
