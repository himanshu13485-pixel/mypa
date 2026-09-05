import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Plus, Trash2, X } from 'lucide-react'
import { crm, crmMeQuery, CRM_CLIENT_CATEGORY_LABELS, CRM_DISPATCH_STATUS_LABELS, validityMonths, type CrmWorkOrderColumn } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, ErrorNote, Input, Label, Select, Spinner, Textarea } from '../../components/ui'
import { codeCase, companyCase } from './textCase'
import { crmPath } from '../../lib/crmPath'

/** One Work Order line. `custom` holds this company's own DCW values. */
interface ItemRow {
  membership: string
  plan_name: string
  description: string
  validity_from: string
  validity_to: string
  qty: string
  unit_price: string
  amount_fx: string
  custom: Record<string, string | boolean>
}

const EMPTY_ITEM: ItemRow = {
  membership: '', plan_name: '', description: '', validity_from: '', validity_to: '',
  qty: '1', unit_price: '', amount_fx: '', custom: {},
}

const inr = (v: number) => '₹' + v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })



export default function CrmInvoiceFormPage() {
  const { uuid } = useParams()
  const editing = !!uuid
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const kind = params.get('kind') === 'proforma' ? 'proforma' : 'invoice'
  const [error, setError] = useState<string | null>(null)
  // The client picker: what is typed, what is shown, and whether the list is
  // open. A native <datalist> drew its popup detached from the field, so the
  // list is ours now.
  const [clientSearch, setClientSearch] = useState('')
  const [clientName, setClientName] = useState('')
  const [clientOpen, setClientOpen] = useState(false)

  const [head, setHead] = useState({
    issuing_company_id: '',
    client_uuid: params.get('client') ?? '',
    member_uuid: '',
    invoice_date: new Date().toISOString().slice(0, 10),
    due_date: '',
    client_category: '',
    pricing_tier: 'regular',
    terms_of_payment: '',
    subscription_type: '',
    dispatch_status: 'pending',
    discount: '',
    cgst: '',
    sgst: '',
    igst: '',
    other_tax: '',
    tds: '',
    // A percentage, where the company prices that way. Given one, the server
    // works out the figure — so the two can never drift apart.
    discount_rate: '',
    cgst_rate: '',
    sgst_rate: '',
    igst_rate: '',
    other_tax_rate: '',
    tds_rate: '',
    fx_currency: '',
    fx_rate: '',
    notes: '',
  })
  const [items, setItems] = useState<ItemRow[]>([{ ...EMPTY_ITEM }])
  // Money lines, keyed by this company's own line keys: a rate, or a figure.
  const [taxes, setTaxes] = useState<Record<string, { rate: string; amount: string }>>({})
  // The document's own extra fields (DCW).
  const [docValues, setDocValues] = useState<Record<string, string | boolean>>({})

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data: me } = useQuery(crmMeQuery())
  // The Work Order method this company had approved (DCW) — empty for a
  // company that has not asked for any extra line fields.
  const workOrderFields = masters?.work_order_custom_fields ?? []
  // Our columns as this company words them, then the ones they added.
  const method = masters?.work_order_method ?? []
  const columns = method.filter((c) => !c.hidden)
  // The document's own fields and money lines, as this company set them up.
  const docMethod = masters?.invoice_method ?? []
  const docFields = masters?.invoice_custom_fields ?? []
  /*
   * Memoised, because the totals below are memoised on it.
   *
   * `?? []` builds a new array each render, so the useMemo that computes
   * every invoice total listed it as a dependency that had always changed —
   * and recomputed the whole thing on every keystroke in the form. The memo
   * was doing nothing but adding a comparison.
   */
  const taxSetup = useMemo(() => masters?.tax_setup ?? [], [masters])
  const shows = (key: string) =>
    !docMethod.find((c) => c.source === 'builtin' && c.key === key)?.hidden
  const heading = (key: string, fallback: string) =>
    docMethod.find((c) => c.source === 'builtin' && c.key === key)?.label ?? fallback
  const docColumn = (key: string) => docMethod.find((c) => c.source === 'builtin' && c.key === key)
  const { data: clients } = useQuery({
    queryKey: ['crm', 'client-options', clientSearch],
    queryFn: () => crm.clients.options(clientSearch || undefined),
  })
  const { data: existing, isLoading } = useQuery({
    queryKey: ['crm', 'invoice', uuid],
    queryFn: () => crm.invoices.get(uuid!),
    enabled: editing,
  })

  /** Picking one is the only way to attach a client — no guessing from text. */
  const pickClient = (client: { uuid: string; company_name: string; category?: string | null }) => {
    setH('client_uuid', client.uuid)
    if (client.category) setH('client_category', client.category)
    setClientName(client.company_name)
    setClientSearch('')
    setClientOpen(false)
  }

  // Arriving from a client's page ("New proforma"), the uuid is in the URL
  // and the name has to catch up once the options land.
  useEffect(() => {
    if (!clientName && head.client_uuid) {
      const hit = clients?.find((c) => c.uuid === head.client_uuid)
      if (hit) setClientName(hit.company_name)
    }
  }, [clients, head.client_uuid, clientName])

  useEffect(() => {
    if (!existing) return
    setClientName(existing.client?.company_name ?? '')
    // The money lines as this document has them, keyed as they were saved.
    setTaxes(Object.fromEntries((existing.tax_lines ?? []).map((line) => [
      line.key,
      { rate: line.rate !== null ? String(Number(line.rate)) : '', amount: line.rate !== null ? '' : String(line.amount) },
    ])))
    setDocValues(Object.fromEntries(
      Object.entries(existing.custom_fields ?? {}).map(([k, v]) => [k, typeof v === 'boolean' ? v : String(v)]),
    ))
    setHead({
      issuing_company_id: String(existing.issuing_company?.id ?? ''),
      client_uuid: existing.client?.uuid ?? '',
      member_uuid: existing.salesperson?.uuid ?? '',
      invoice_date: existing.invoice_date,
      due_date: existing.due_date ?? '',
      client_category: existing.client_category ?? '',
      pricing_tier: existing.pricing_tier,
      terms_of_payment: existing.terms_of_payment ?? '',
      subscription_type: existing.subscription_type ?? '',
      dispatch_status: existing.dispatch_status,
      discount: existing.discount !== '0.00' ? existing.discount : '',
      cgst: existing.cgst !== '0.00' ? existing.cgst : '',
      sgst: existing.sgst !== '0.00' ? existing.sgst : '',
      igst: existing.igst !== '0.00' ? existing.igst : '',
      other_tax: existing.other_tax !== '0.00' ? existing.other_tax : '',
      tds: existing.tds !== '0.00' ? existing.tds : '',
      discount_rate: existing.discount_rate ?? '',
      cgst_rate: existing.cgst_rate ?? '',
      sgst_rate: existing.sgst_rate ?? '',
      igst_rate: existing.igst_rate ?? '',
      other_tax_rate: existing.other_tax_rate ?? '',
      tds_rate: existing.tds_rate ?? '',
      fx_currency: existing.fx_currency ?? '',
      fx_rate: existing.fx_rate ?? '',
      notes: existing.notes ?? '',
    })
    setItems(existing.items.map((it) => ({
      membership: it.membership ?? '',
      plan_name: it.plan_name ?? '',
      description: it.description ?? '',
      validity_from: it.validity_from ?? '',
      validity_to: it.validity_to ?? '',
      qty: String(it.qty ?? '1'),
      unit_price: String(it.unit_price ?? ''),
      amount_fx: it.amount_fx ? String(it.amount_fx) : '',
      // Kept raw, keyed as stored, so a slow masters load can never blank
      // out values that are already on the line.
      custom: Object.fromEntries(
        Object.entries(it.custom_fields ?? {}).map(([k, v]) => [k, typeof v === 'boolean' ? v : String(v)]),
      ),
    })))
  }, [existing])

  const setH = (key: keyof typeof head, value: string) => setHead((h) => ({ ...h, [key]: value }))
  const setItem = (idx: number, key: keyof ItemRow, value: string) =>
    setItems((rows) => rows.map((r, i) => (i === idx ? { ...r, [key]: value } : r)))
  const setItemField = (idx: number, key: string, value: string | boolean) =>
    setItems((rows) => rows.map((r, i) => (i === idx ? { ...r, custom: { ...r.custom, [key]: value } } : r)))

  /**
   * The figures, worked exactly as the server works them: a percentage wins
   * over a typed amount, discount comes off the subtotal, and every tax is
   * charged on what is left.
   */
  const totals = useMemo(() => {
    const subtotal = items.reduce((sum, r) => sum + (Number(r.qty) || 0) * (Number(r.unit_price) || 0), 0)
    const round2 = (v: number) => Math.round(v * 100) / 100

    /** A line's figure: its percentage if it has one, else what was typed. */
    const figure = (key: string, defaultRate: number | null, base: number) => {
      const entry = taxes[key]
      const rate = entry?.rate !== undefined && entry.rate !== ''
        ? Number(entry.rate)
        : entry?.amount !== undefined && entry.amount !== '' ? null : defaultRate
      return rate !== null && rate !== undefined && !Number.isNaN(rate)
        ? round2((base * rate) / 100)
        : Number(entry?.amount) || 0
    }

    // Discounts first, so the taxable value is known before the taxes that
    // are charged on it — the same order the server works in.
    const amounts: Record<string, number> = {}
    let discounted = 0
    for (const line of taxSetup.filter((l) => l.kind === 'discount')) {
      amounts[line.key] = figure(line.key, line.default_rate, subtotal)
      discounted += amounts[line.key]
    }
    const taxable = round2(subtotal - discounted)

    let added = 0
    let deducted = 0
    for (const line of taxSetup.filter((l) => l.kind !== 'discount')) {
      const base = line.basis === 'subtotal' ? subtotal : taxable
      amounts[line.key] = figure(line.key, line.default_rate, base)
      if (line.kind === 'deduction') deducted += amounts[line.key]
      else added += amounts[line.key]
    }

    return { subtotal, taxable, total: round2(taxable + added - deducted), amounts }
  }, [items, taxes, taxSetup])

  /**
   * One of our own columns, drawn the way this company asked for it — a
   * dropdown of their products where they wanted one, their heading, their
   * required flag.
   */
  const renderBuiltinCell = (c: CrmWorkOrderColumn, row: ItemRow, idx: number) => {
    if (c.key === 'validity') {
      return (
        <>
          <Input type="date" value={row.validity_from} onChange={(e) => setItem(idx, 'validity_from', e.target.value)} className="mb-1 w-full" />
          <Input type="date" value={row.validity_to} onChange={(e) => setItem(idx, 'validity_to', e.target.value)} className="w-full" />
          {/* The service span in months — the number the incentive spread
              will run on, said here so nobody has to count on fingers. */}
          {validityMonths(row.validity_from, row.validity_to) !== null && (
            <div className="mt-0.5 text-center text-[11px] font-medium text-emerald-600">
              {validityMonths(row.validity_from, row.validity_to)} month{validityMonths(row.validity_from, row.validity_to) === 1 ? '' : 's'}
            </div>
          )}
        </>
      )
    }

    if (c.key === 'qty' || c.key === 'unit_price') {
      return (
        <Input
          type="number"
          min="0"
          step={c.key === 'qty' ? '1' : '0.01'}
          value={row[c.key]}
          onChange={(e) => setItem(idx, c.key as keyof ItemRow, e.target.value)}
          className="w-full"
        />
      )
    }

    const key = c.key as 'membership' | 'plan_name' | 'description'

    if (c.type === 'select') {
      return (
        <Select value={row[key]} onChange={(e) => setItem(idx, key, e.target.value)} className="w-full">
          <option value="">Select</option>
          {(c.options ?? []).map((o) => <option key={o} value={o}>{o}</option>)}
        </Select>
      )
    }
    if (c.type === 'textarea') {
      return (
        <Textarea rows={2} value={row[key]} onChange={(e) => setItem(idx, key, e.target.value)} placeholder={c.label} className="w-full" />
      )
    }

    return (
      <Input
        value={row[key]}
        onChange={(e) => setItem(idx, key, e.target.value)}
        // House style on the name-like boxes only; free text stays as typed.
        onBlur={() => key !== 'description' && setItem(idx, key, companyCase(row[key]))}
        placeholder={c.label}
        className="w-full"
      />
    )
  }

  /** A column this company added (DCW). */
  const renderCustomCell = (c: CrmWorkOrderColumn, row: ItemRow, idx: number) => {
    const value = row.custom?.[c.key]

    if (c.type === 'checkbox') {
      return (
        <input
          type="checkbox"
          checked={!!value}
          onChange={(e) => setItemField(idx, c.key, e.target.checked)}
          className="mt-3 size-4 accent-emerald-600"
        />
      )
    }
    if (c.type === 'select') {
      return (
        <Select value={String(value ?? '')} onChange={(e) => setItemField(idx, c.key, e.target.value)} className="w-full">
          <option value="">Select</option>
          {(c.options ?? []).map((o) => <option key={o} value={o}>{o}</option>)}
        </Select>
      )
    }
    if (c.type === 'textarea') {
      return (
        <Textarea rows={2} value={String(value ?? '')} onChange={(e) => setItemField(idx, c.key, e.target.value)} className="w-full" />
      )
    }

    return (
      <Input
        type={c.type === 'number' ? 'number' : c.type === 'date' ? 'date' : 'text'}
        value={String(value ?? '')}
        onChange={(e) => setItemField(idx, c.key, e.target.value)}
        className="w-full"
        title={c.help ?? undefined}
      />
    )
  }

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = {
        kind,
        issuing_company_id: head.issuing_company_id ? Number(head.issuing_company_id) : null,
        client_uuid: head.client_uuid || null,
        member_uuid: head.member_uuid || null,
        invoice_date: head.invoice_date,
        due_date: head.due_date || null,
        client_category: head.client_category || null,
        pricing_tier: head.pricing_tier,
        terms_of_payment: head.terms_of_payment || null,
        subscription_type: head.subscription_type || null,
        dispatch_status: head.dispatch_status,
        discount: head.discount ? Number(head.discount) : 0,
        cgst: head.cgst ? Number(head.cgst) : 0,
        sgst: head.sgst ? Number(head.sgst) : 0,
        igst: head.igst ? Number(head.igst) : 0,
        other_tax: head.other_tax ? Number(head.other_tax) : 0,
        tds: head.tds ? Number(head.tds) : 0,
        // The company's own money lines; the server works the figures out.
        tax_lines: taxSetup.map((line) => {
          const entry = taxes[line.key] ?? { rate: '', amount: '' }
          return {
            key: line.key,
            rate: entry.rate !== '' ? Number(entry.rate) : null,
            amount: entry.amount !== '' ? Number(entry.amount) : null,
          }
        }),
        custom_fields: docFields.length > 0
          ? Object.fromEntries(docFields.map((f) => {
              const raw = docValues[f.key]
              if (f.type === 'checkbox') return [f.key, !!raw]
              if (raw === '' || raw === undefined) return [f.key, null]
              return [f.key, f.type === 'number' ? Number(raw) : raw]
            }))
          : undefined,
        fx_currency: head.fx_currency || null,
        fx_rate: head.fx_rate ? Number(head.fx_rate) : null,
        notes: head.notes || null,
        items: items
          .filter((r) => r.plan_name || r.description || Number(r.unit_price) > 0)
          .map((r) => ({
            membership: r.membership || null,
            plan_name: r.plan_name || null,
            description: r.description || null,
            validity_from: r.validity_from || null,
            validity_to: r.validity_to || null,
            qty: Number(r.qty) || 1,
            unit_price: Number(r.unit_price) || 0,
            amount_fx: r.amount_fx ? Number(r.amount_fx) : null,
            // The company's own Work Order method travels with the line.
            ...(workOrderFields.length > 0
              ? {
                  custom_fields: Object.fromEntries(workOrderFields.map((f) => {
                    const raw = r.custom?.[f.key]
                    if (f.type === 'checkbox') return [f.key, !!raw]
                    if (raw === '' || raw === undefined) return [f.key, null]
                    return [f.key, f.type === 'number' ? Number(raw) : raw]
                  })),
                }
              : {}),
          })),
      }
      return editing ? crm.invoices.update(uuid!, payload) : crm.invoices.create(payload)
    },
    onSuccess: (res: { message?: string; data?: { uuid?: string } }) => {
      queryClient.invalidateQueries({ queryKey: ['crm'] })
      toast(res.message ?? 'Saved.', 'success')
      navigate(res.data?.uuid ? crmPath(`/crm/invoices/${res.data.uuid}`) : crmPath(`/crm/invoices?kind=${kind}`), { replace: true })
    },
    onError: (err) => setError(errorMessage(err)),
  })

  if (editing && isLoading) {
    return <div className="flex justify-center py-20"><Spinner /></div>
  }

  const docLabel = (editing ? existing?.kind : kind) === 'proforma' ? 'proforma invoice' : 'invoice'

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <button onClick={() => navigate(-1)} aria-label="Back" className="rounded p-1.5 text-slate-400 hover:bg-slate-200/60 dark:hover:bg-slate-800">
            <ArrowLeft className="size-4" />
          </button>
          <h1 className="text-xl font-semibold capitalize text-slate-900 dark:text-white">
            {editing ? `Edit ${existing?.number}` : `New ${docLabel}`}
          </h1>
        </div>
        <Button onClick={() => saveMutation.mutate()} disabled={saveMutation.isPending}>
          {saveMutation.isPending ? 'Saving…' : editing ? 'Save changes' : `Create ${docLabel}`}
        </Button>
      </div>

      <ErrorNote message={error} />

      <Card>
        <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Document</h2>
        <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <Label>{heading('issuing_company', 'Issuing company')}</Label>
            <Select value={head.issuing_company_id} onChange={(e) => setH('issuing_company_id', e.target.value)} disabled={editing} className="w-full">
              <option value="">Select</option>
              {masters?.issuing_companies.filter((c) => c.is_active || String(c.id) === head.issuing_company_id).map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </Select>
            {!editing && masters?.issuing_companies.length === 0 && (
              <p className="mt-1 text-xs text-amber-600">None yet — add one under Billing setup.</p>
            )}
          </div>
          <div className="relative">
            <Label>{heading('client', 'Client')}</Label>
            <Input
              value={clientName}
              onChange={(e) => {
                // Typing unpicks: a half-typed name must never leave the last
                // client silently attached to the document.
                setClientName(e.target.value)
                setClientSearch(e.target.value)
                setH('client_uuid', '')
                setClientOpen(true)
              }}
              onFocus={() => setClientOpen(true)}
              onBlur={() => setClientOpen(false)}
              onKeyDown={(e) => {
                if (e.key === 'Escape') setClientOpen(false)
                if (e.key === 'Enter' && clientOpen && clients?.length) {
                  e.preventDefault()
                  pickClient(clients[0])
                }
              }}
              placeholder="Type to search…"
              className="w-full"
              autoComplete="off"
              role="combobox"
              aria-expanded={clientOpen}
            />
            {head.client_uuid && (
              <button
                type="button"
                onClick={() => { setClientName(''); setClientSearch(''); setH('client_uuid', '') }}
                aria-label="Clear client"
                className="absolute right-2 top-[30px] rounded p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
              >
                <X className="size-4" />
              </button>
            )}
            {clientOpen && (
              <ul
                // The list belongs to the field, so it is drawn under it in
                // the page rather than by the browser wherever it likes.
                className="absolute inset-x-0 top-full z-30 mt-1 max-h-56 overflow-auto rounded-xl bg-white py-1 shadow-lg ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700"
                // Keep the input focused so the click lands before the blur.
                onMouseDown={(e) => e.preventDefault()}
              >
                {(clients ?? []).length === 0 ? (
                  <li className="px-3 py-2 text-sm text-slate-400">No client matches that.</li>
                ) : (
                  clients!.map((c) => (
                    <li key={c.uuid}>
                      <button
                        type="button"
                        onClick={() => pickClient(c)}
                        className="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-700/60"
                      >
                        <span className="block truncate font-medium text-slate-800 dark:text-slate-100">{c.company_name}</span>
                        <span className="block truncate text-xs text-slate-400">
                          {[c.contact_person, c.city, c.gst_no].filter(Boolean).join(' · ') || '—'}
                        </span>
                      </button>
                    </li>
                  ))
                )}
              </ul>
            )}
            {!head.client_uuid && clientName !== '' && !clientOpen && (
              <p className="mt-1 text-xs text-amber-600">Pick a client from the list.</p>
            )}
          </div>
          {/* Every field below is this company's to word or switch off. */}
          <div>
            <Label>{heading('invoice_date', 'Date')}</Label>
            <Input type="date" value={head.invoice_date} onChange={(e) => setH('invoice_date', e.target.value)} className="w-full" />
          </div>
          {shows('due_date') && (
            <div>
              <Label>{heading('due_date', 'Due date')}</Label>
              <Input type="date" value={head.due_date} onChange={(e) => setH('due_date', e.target.value)} className="w-full" />
            </div>
          )}
          {shows('member') && (
            <div>
              <Label>{heading('member', 'Salesperson')}</Label>
              <Select value={head.member_uuid} onChange={(e) => setH('member_uuid', e.target.value)} className="w-full">
                <option value="">Select</option>
                {masters?.members.filter((m) => m.is_salesperson || m.uuid === head.member_uuid).map((m) => (
                  <option key={m.uuid} value={m.uuid}>{m.name}</option>
                ))}
              </Select>
            </div>
          )}
          {shows('client_category') && (
            <div>
              <Label>{heading('client_category', 'Client status')}</Label>
              <Select value={head.client_category} onChange={(e) => setH('client_category', e.target.value)} className="w-full">
                <option value="">Select</option>
                {masters?.client_categories.map((c) => (
                  <option key={c} value={c}>{CRM_CLIENT_CATEGORY_LABELS[c] ?? c}</option>
                ))}
              </Select>
            </div>
          )}
          {shows('pricing_tier') && (
            <div>
              <Label>{heading('pricing_tier', 'Pricing')}</Label>
              <Select value={head.pricing_tier} onChange={(e) => setH('pricing_tier', e.target.value)} className="w-full">
                <option value="regular">Regular pricing</option>
                <option value="low">Low pricing</option>
              </Select>
            </div>
          )}
          {shows('subscription_type') && (
            <div>
              <Label>{heading('subscription_type', 'Subscription type')}</Label>
              <Select value={head.subscription_type} onChange={(e) => setH('subscription_type', e.target.value)} className="w-full">
                <option value="">Select</option>
                <option value="online">Online</option>
                <option value="offline">Offline</option>
                <option value="both">Both</option>
              </Select>
            </div>
          )}
          {shows('dispatch_status') && (
            <div>
              <Label>{heading('dispatch_status', 'Dispatch status')}</Label>
              <Select value={head.dispatch_status} onChange={(e) => setH('dispatch_status', e.target.value)} className="w-full">
                {Object.entries(CRM_DISPATCH_STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </Select>
            </div>
          )}
          {/* The document's own extra fields (DCW), beside ours. */}
          {docFields.map((f) => (
            <div key={f.key} className={f.type === 'textarea' ? 'sm:col-span-2 lg:col-span-3' : undefined}>
              {f.type === 'checkbox' ? (
                <label className="flex items-center gap-2 pt-6 text-sm text-slate-600 dark:text-slate-300">
                  <input
                    type="checkbox"
                    checked={!!docValues[f.key]}
                    onChange={(e) => setDocValues((v) => ({ ...v, [f.key]: e.target.checked }))}
                    className="size-4 accent-emerald-600"
                  />
                  {f.label}
                </label>
              ) : (
                <>
                  <Label>{f.label}{f.is_required && ' *'}</Label>
                  {f.type === 'select' ? (
                    <Select
                      value={String(docValues[f.key] ?? '')}
                      onChange={(e) => setDocValues((v) => ({ ...v, [f.key]: e.target.value }))}
                      className="w-full"
                    >
                      <option value="">Select</option>
                      {(f.options ?? []).map((o) => <option key={o} value={o}>{o}</option>)}
                    </Select>
                  ) : f.type === 'textarea' ? (
                    <Textarea
                      rows={2}
                      value={String(docValues[f.key] ?? '')}
                      onChange={(e) => setDocValues((v) => ({ ...v, [f.key]: e.target.value }))}
                      className="w-full"
                    />
                  ) : (
                    <Input
                      type={f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'}
                      value={String(docValues[f.key] ?? '')}
                      onChange={(e) => setDocValues((v) => ({ ...v, [f.key]: e.target.value }))}
                      className="w-full"
                      title={f.help ?? undefined}
                    />
                  )}
                </>
              )}
            </div>
          ))}
          {shows('terms_of_payment') && (
            <div className="sm:col-span-2 lg:col-span-3">
              <Label>{heading('terms_of_payment', 'Terms of payment')}</Label>
              {docColumn('terms_of_payment')?.type === 'select' ? (
                <Select value={head.terms_of_payment} onChange={(e) => setH('terms_of_payment', e.target.value)} className="w-full">
                  <option value="">Select</option>
                  {(docColumn('terms_of_payment')?.options ?? []).map((o) => <option key={o} value={o}>{o}</option>)}
                </Select>
              ) : (
                <Input value={head.terms_of_payment} onChange={(e) => setH('terms_of_payment', e.target.value)} placeholder="100% advance" className="w-full" />
              )}
            </div>
          )}
        </div>
      </Card>

      <Card>
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Work Order</h2>
            <p className="text-xs text-slate-400">
              {method.some((c) => c.customised)
                ? `${me?.organization?.name ?? 'Your company'}'s own columns.`
                : 'Your company can word these columns its own way from Workspace fields.'}
            </p>
          </div>
          <Button size="sm" variant="secondary" onClick={() => setItems((r) => [...r, { ...EMPTY_ITEM }])}>
            <Plus className="size-3.5" /> Add row
          </Button>
        </div>
        <div className="-mx-4 mt-3 overflow-x-auto px-4">
          <table className="w-full min-w-[880px] text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                {/* The company's Work Order method decides the columns: ours
                    as they word them, then the ones they added. */}
                {columns.map((c) => (
                  <th key={`${c.source}:${c.key}`} className="py-2 pr-2 font-medium" title={c.help ?? undefined}>
                    {c.label}{c.is_required && ' *'}
                  </th>
                ))}
                <th className="w-28 py-2 pr-2 text-right font-medium">Amount</th>
                <th className="w-8 py-2" />
              </tr>
            </thead>
            <tbody>
              {items.map((row, idx) => (
                <tr key={idx} className="border-b border-slate-50 align-top last:border-0 dark:border-slate-800/50">
                  {columns.map((c) => (
                    <td key={`${c.source}:${c.key}`} className="py-2 pr-2">
                      {c.source === 'custom'
                        ? renderCustomCell(c, row, idx)
                        : renderBuiltinCell(c, row, idx)}
                    </td>
                  ))}
                  <td className="whitespace-nowrap py-3.5 pr-2 text-right font-medium">
                    {inr((Number(row.qty) || 0) * (Number(row.unit_price) || 0))}
                  </td>
                  <td className="py-2 text-right">
                    <button
                      onClick={() => setItems((r) => (r.length > 1 ? r.filter((_, i) => i !== idx) : r))}
                      aria-label="Remove row"
                      className="mt-2 rounded p-1 text-slate-300 hover:text-red-500"
                    >
                      <Trash2 className="size-4" />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Taxes & adjustments</h2>
          <p className="mt-0.5 text-xs text-slate-400">
            Enter a percentage and the amount is worked out for you, or leave % blank and type the amount.
            Taxes are charged on {inr(totals.taxable)} (subtotal less discounts).
          </p>
          <div className="mt-3 space-y-2">
            {/* This company's own money lines, in its own order. */}
            {taxSetup.map((line) => {
              const entry = taxes[line.key] ?? { rate: '', amount: '' }
              const rate = entry.rate !== '' ? entry.rate
                : entry.amount === '' && line.default_rate !== null ? String(line.default_rate) : ''
              const byRate = rate !== ''

              return (
                <div key={line.key} className="flex items-center gap-2">
                  <Label className="w-28 shrink-0">{line.label}</Label>
                  <div className="relative w-24 shrink-0">
                    <Input
                      type="number"
                      min="0"
                      max="100"
                      step="0.01"
                      value={rate}
                      onChange={(e) => setTaxes((t) => ({ ...t, [line.key]: { rate: e.target.value, amount: '' } }))}
                      placeholder="—"
                      className="w-full pr-6"
                      aria-label={`${line.label} percentage`}
                    />
                    <span className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-xs text-slate-400">%</span>
                  </div>
                  {byRate ? (
                    /* The percentage owns the figure; an editable box here
                       would be a second answer to the same question. */
                    <div className="flex h-[38px] flex-1 items-center justify-end rounded-xl bg-slate-100 px-3 text-sm font-medium tabular-nums text-slate-700 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/60 dark:text-slate-200 dark:ring-slate-700">
                      {inr(totals.amounts[line.key] ?? 0)}
                    </div>
                  ) : (
                    <Input
                      type="number"
                      min="0"
                      step="0.01"
                      value={entry.amount}
                      onChange={(e) => setTaxes((t) => ({ ...t, [line.key]: { rate: '', amount: e.target.value } }))}
                      placeholder="Amount"
                      className="flex-1"
                      aria-label={`${line.label} amount`}
                    />
                  )}
                </div>
              )
            })}
          </div>
          <div className="mt-3 grid grid-cols-2 gap-3">
            {shows('fx') && (
              <>
                <div>
                  <Label>{heading('fx', 'FX currency')}</Label>
                  <Input value={head.fx_currency} onChange={(e) => setH('fx_currency', codeCase(e.target.value).slice(0, 3))} placeholder="USD" className="w-full" />
                </div>
                <div>
                  <Label>FX rate</Label>
                  <Input type="number" min="0" step="0.0001" value={head.fx_rate} onChange={(e) => setH('fx_rate', e.target.value)} className="w-full" />
                </div>
              </>
            )}
          </div>
          {shows('notes') && (
          <div className="mt-3">
            <Label>{heading('notes', 'Notes')} (printed on the document)</Label>
            <Textarea rows={2} value={head.notes} onChange={(e) => setH('notes', e.target.value)} className="w-full" />
          </div>
          )}
        </Card>

        <Card className="flex flex-col justify-center">
          <div className="space-y-1.5 text-sm">
            <div className="flex justify-between text-slate-500"><span>Subtotal</span><span>{inr(totals.subtotal)}</span></div>
            {taxSetup.map((line) => {
              const amount = totals.amounts[line.key] ?? 0
              if (amount <= 0) return null
              const entry = taxes[line.key]
              const rate = entry?.rate !== undefined && entry.rate !== ''
                ? entry.rate
                : entry?.amount ? '' : line.default_rate !== null ? String(line.default_rate) : ''

              return (
                <div key={line.key} className="flex justify-between text-slate-500">
                  <span>
                    {line.label}
                    {rate !== '' && <span className="text-slate-400"> @ {rate}%</span>}
                  </span>
                  <span>{line.kind === 'tax' ? '' : '− '}{inr(amount)}</span>
                </div>
              )
            })}
            <div className="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold text-slate-900 dark:border-slate-700 dark:text-white">
              <span>Grand total</span><span>{inr(totals.total)}</span>
            </div>
          </div>
          <Button
            className="mt-4"
            onClick={() => saveMutation.mutate()}
            disabled={saveMutation.isPending || (!editing && !head.client_uuid)}
          >
            {saveMutation.isPending ? 'Saving…' : editing ? 'Save changes' : `Create ${docLabel}`}
          </Button>
        </Card>
      </div>
    </div>
  )
}
