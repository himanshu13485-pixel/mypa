import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlarmClock, Building2, ClipboardCheck, Copy, CreditCard, KeyRound, Landmark, LifeBuoy, ListChecks, Pencil, Plus, Wallet } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, crmMeQuery, type CrmMasters, type CrmGatewaySettings, type CrmPaymentSettings } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, ErrorNote, Input, Label, Modal, Select, Spinner } from '../../components/ui'

type Company = CrmMasters['issuing_companies'][number]
type Bank = CrmMasters['bank_accounts'][number]

export default function CrmSettingsPage() {
  const queryClient = useQueryClient()
  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const [companyModal, setCompanyModal] = useState<{ open: boolean; editing?: Company }>({ open: false })
  const [bankModal, setBankModal] = useState<{ open: boolean; editing?: Bank }>({ open: false })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'masters'] })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Billing setup</h1>
        <p className="text-sm text-slate-500">
          Issuing companies (with their numbering series), bank accounts, and how payments are handled.
        </p>
      </div>

      <MasterKey />
      <PaymentRules />
      <CashfreeAccount />
      <FxMargin />
      <BirthdaySong />
      <FestivalCelebrations />
      <LeadAlertTiming />
      <LeadOptions />
      <AssetCategories />
      <ApprovalTypes />
      <ComplaintOptions />

      <Card>
        <div className="flex items-center justify-between">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
            <Building2 className="size-4 text-emerald-500" /> Issuing companies
          </h2>
          <Button size="sm" variant="secondary" onClick={() => setCompanyModal({ open: true })}>
            <Plus className="size-3.5" /> Add
          </Button>
        </div>
        {!masters || masters.issuing_companies.length === 0 ? (
          <p className="mt-3 text-sm text-slate-400">No issuing companies yet — invoices need at least one.</p>
        ) : (
          <div className="-mx-4 mt-3 overflow-x-auto px-4">
            <table className="w-full min-w-[560px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Name</th>
                  <th className="py-2 pr-3 font-medium">GSTIN</th>
                  <th className="py-2 pr-3 font-medium">Invoice series</th>
                  <th className="py-2 pr-3 font-medium">Proforma series</th>
                  <th className="py-2 pr-3 font-medium">Currency</th>
                  <th className="py-2 pr-3 font-medium">Pays salary</th>
                  <th className="py-2 pr-3 font-medium">Active</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {masters.issuing_companies.map((c) => (
                  <tr key={c.id} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="py-2.5 pr-3 font-medium">{c.name}</td>
                    <td className="py-2.5 pr-3">{c.gstin ?? '—'}</td>
                    <td className="py-2.5 pr-3">{c.invoice_prefix}…</td>
                    <td className="py-2.5 pr-3">{c.proforma_prefix}…</td>
                    <td className="py-2.5 pr-3">{c.currency ?? 'INR'}</td>
                    <td className="py-2.5 pr-3">{c.pays_salary ? '✓ Salary company' : '—'}</td>
                    <td className="py-2.5 pr-3">{c.is_active ? 'Yes' : 'No'}</td>
                    <td className="py-2.5 text-right">
                      <button onClick={() => setCompanyModal({ open: true, editing: c })} aria-label="Edit" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                        <Pencil className="size-4" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card>
        <div className="flex items-center justify-between">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
            <Landmark className="size-4 text-emerald-500" /> Bank accounts
          </h2>
          <Button size="sm" variant="secondary" onClick={() => setBankModal({ open: true })}>
            <Plus className="size-3.5" /> Add
          </Button>
        </div>
        {!masters || masters.bank_accounts.length === 0 ? (
          <p className="mt-3 text-sm text-slate-400">No bank accounts yet — payments reference these.</p>
        ) : (
          <div className="-mx-4 mt-3 overflow-x-auto px-4">
            <table className="w-full min-w-[480px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Label</th>
                  <th className="py-2 pr-3 font-medium">Bank</th>
                  <th className="py-2 pr-3 font-medium">Account no.</th>
                  <th className="py-2 pr-3 font-medium">IFSC</th>
                  <th className="py-2 pr-3 font-medium">Issuing company</th>
                  <th className="py-2 pr-3 font-medium">Active</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {masters.bank_accounts.map((b) => (
                  <tr key={b.id} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="py-2.5 pr-3 font-medium">{b.label}</td>
                    <td className="py-2.5 pr-3">{b.bank_name ?? '—'}</td>
                    <td className="py-2.5 pr-3">{b.account_no ?? '—'}</td>
                    <td className="py-2.5 pr-3">{b.ifsc ?? '—'}</td>
                    <td className="py-2.5 pr-3">{b.issuing_company_name ?? '—'}</td>
                    <td className="py-2.5 pr-3">{b.is_active ? 'Yes' : 'No'}</td>
                    <td className="py-2.5 text-right">
                      <button onClick={() => setBankModal({ open: true, editing: b })} aria-label="Edit" className="rounded p-1.5 text-slate-400 hover:text-emerald-600">
                        <Pencil className="size-4" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {companyModal.open && <CompanyModal editing={companyModal.editing} onClose={() => setCompanyModal({ open: false })} onDone={() => { setCompanyModal({ open: false }); refresh() }} />}
      {bankModal.open && <BankModal editing={bankModal.editing} onClose={() => setBankModal({ open: false })} onDone={() => { setBankModal({ open: false }); refresh() }} />}
    </div>
  )
}

/**
 * The FX margin: rupees the bank's conversion cut takes off the market
 * rate. Live rate 96, margin 2 -> foreign invoices convert at 94.
 */
/**
 * One password the company can put a locked-out employee back in with.
 *
 * The admin's own password is asked for before the key can be set or replaced.
 * That is not ceremony: this one value opens every non-admin account in the
 * company, and somebody sitting at an admin's unlocked laptop should not be
 * able to mint it without knowing the password of the account they are sitting
 * at.
 *
 * The key is never read back — there is no endpoint that returns it, only one
 * that says whether there is one. Which means a forgotten key is not
 * recoverable, and the card says so rather than letting somebody find out on
 * the morning they need it.
 */
function MasterKey() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const { data: me } = useQuery(crmMeQuery())
  const { data: status } = useQuery({
    queryKey: ['crm', 'master-key'],
    queryFn: crm.masterKey.status,
    enabled: me?.member?.crm_role === 'admin',
  })

  const [open, setOpen] = useState(false)
  const [current, setCurrent] = useState('')
  const [key, setKey] = useState('')
  const [again, setAgain] = useState('')

  const close = () => {
    setOpen(false)
    setCurrent('')
    setKey('')
    setAgain('')
  }

  const save = useMutation({
    mutationFn: () => crm.masterKey.save({
      current_password: current,
      master_key: key,
      master_key_confirmation: again,
    }),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'master-key'] })
      toast(res.message, 'success')
      close()
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const clear = useMutation({
    mutationFn: crm.masterKey.clear,
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'master-key'] })
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  // Not a subadmin's, whatever rights they hold — the server refuses them too.
  if (me?.member?.crm_role !== 'admin') return null

  return (
    <Card>
      <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <KeyRound className="size-4 text-emerald-500" /> Master key
      </h2>
      <p className="mt-1 text-xs text-slate-400">
        One password you can put a locked-out employee back on, from their row on the Employees
        screen. They are asked to change it the moment they sign in, and every session they had is
        signed out.
      </p>

      <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm">
          {status?.is_set ? (
            <>
              <span className="font-medium text-emerald-600">Set</span>
              {status.set_by && <span className="text-slate-400"> · by {status.set_by}</span>}
              {status.set_at && <span className="text-slate-400"> · {status.set_at}</span>}
            </>
          ) : (
            <span className="text-slate-400">Not set — resets are not possible until it is.</span>
          )}
        </p>
        <div className="flex gap-2">
          <Button size="sm" onClick={() => setOpen(true)}>
            {status?.is_set ? 'Replace' : 'Set a master key'}
          </Button>
          {status?.is_set && (
            <Button
              size="sm"
              variant="secondary"
              disabled={clear.isPending}
              onClick={() => clear.mutate()}
            >
              Remove
            </Button>
          )}
        </div>
      </div>

      <p className="mt-3 rounded-lg bg-amber-50 p-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
        Keep it somewhere safe. It is stored encrypted and is never shown again — if you forget it,
        the only way back is to set a new one. Anyone who learns it can sign in as any of your staff
        until they change their password, so treat it like the office key it is.
      </p>

      {open && (
        <Modal title={status?.is_set ? 'Replace the master key' : 'Set a master key'} onClose={close}>
          <div className="space-y-3">
            <div>
              <Label>Your own password</Label>
              <Input
                type="password"
                autoComplete="current-password"
                value={current}
                onChange={(e) => setCurrent(e.target.value)}
                className="w-full"
              />
              <p className="mt-1 text-xs text-slate-400">
                Asked because this key opens every staff account — an unlocked laptop should not be
                enough on its own.
              </p>
            </div>
            <div>
              <Label>Master key</Label>
              <Input
                type="password"
                autoComplete="new-password"
                value={key}
                onChange={(e) => setKey(e.target.value)}
                className="w-full"
              />
              <p className="mt-1 text-xs text-slate-400">
                At least 10 characters, with letters, numbers and a symbol.
              </p>
            </div>
            <div>
              <Label>Master key again</Label>
              <Input
                type="password"
                autoComplete="new-password"
                value={again}
                onChange={(e) => setAgain(e.target.value)}
                className="w-full"
              />
            </div>
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={close}>Cancel</Button>
              <Button
                disabled={!current || !key || key !== again || save.isPending}
                onClick={() => save.mutate()}
              >
                {save.isPending ? 'Saving…' : 'Save'}
              </Button>
            </div>
          </div>
        </Modal>
      )}
    </Card>
  )
}

function FxMargin() {
  const { toast, toastError } = useToast()
  const [margin, setMargin] = useState('')
  const [preview, setPreview] = useState<{ market: number | null; effective: number | null } | null>(null)

  const { data } = useQuery({
    queryKey: ['crm', 'fx-usd'],
    queryFn: () => crm.masterData.fxRate('USD'),
  })

  const save = useMutation({
    mutationFn: () => crm.masterData.saveFxMargin(Number(margin)),
    onSuccess: async (res) => {
      toast(res.message, 'success')
      const fresh = await crm.masterData.fxRate('USD')
      setPreview({ market: fresh.market_rate, effective: fresh.effective_rate })
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const market = preview?.market ?? data?.market_rate ?? null
  const effective = preview?.effective ?? data?.effective_rate ?? null

  return (
    <Card>
      <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Foreign currency conversion</h2>
      <p className="mt-0.5 text-xs text-slate-400">
        Foreign-currency invoices carry a universal INR figure: the live market rate less this margin (the bank's
        conversion cut). Live USD now: {market !== null ? `₹${market}` : 'unavailable'}
        {effective !== null && <> · converting at <span className="font-medium text-emerald-600">₹{effective}</span></>}.
      </p>
      <div className="mt-3 flex items-end gap-2">
        <div>
          <Label>Margin (₹ off the market rate)</Label>
          <Input type="number" min="0" step="0.5" value={margin} onChange={(e) => setMargin(e.target.value)}
            placeholder={String(data?.margin_inr ?? 2)} className="w-40" />
        </div>
        <Button size="sm" variant="secondary" disabled={margin === '' || save.isPending} onClick={() => save.mutate()}>
          {save.isPending ? 'Saving…' : 'Save margin'}
        </Button>
      </div>
    </Card>
  )
}

/** The birthday vibe: on, and which song plays on someone's day. */
function BirthdaySong() {
  const { toast, toastError } = useToast()
  const { data } = useQuery({ queryKey: ['crm', 'birthday-settings'], queryFn: crm.masterData.birthdaySettings })
  const [draft, setDraft] = useState<{ enabled: boolean; song_url: string } | null>(null)
  const cfg = draft ?? (data ? { enabled: data.enabled, song_url: data.song_url ?? '' } : null)

  const save = useMutation({
    mutationFn: () => crm.masterData.saveBirthdaySettings({ enabled: cfg!.enabled, song_url: cfg!.song_url || null }),
    onSuccess: (res) => toast(res.message, 'success'),
    onError: (err) => toastError(errorMessage(err)),
  })

  if (!cfg) return null

  return (
    <Card>
      <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Birthday wishes</h2>
      <p className="mt-0.5 text-xs text-slate-400">
        On an employee's birthday their CRM turns festive — banner, confetti, and this song playing softly.
        Birthdays come from each profile's date of birth.
      </p>
      <div className="mt-3 space-y-2">
        <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
          <input type="checkbox" checked={cfg.enabled} onChange={(e) => setDraft({ ...cfg, enabled: e.target.checked })} className="size-4 accent-emerald-600" />
          Birthday vibe on
        </label>
        <div>
          <Label>Birthday song URL (mp3)</Label>
          <Input value={cfg.song_url} onChange={(e) => setDraft({ ...cfg, song_url: e.target.value })}
            placeholder="https://…/happy-birthday.mp3" className="w-full" />
        </div>
        <div>
          <Label>…or upload the song file (mp3/wav, up to 10 MB)</Label>
          <input
            type="file"
            accept="audio/*"
            onChange={async (e) => {
              const file = e.target.files?.[0]
              if (!file) return
              try {
                const res = await crm.masterData.uploadCelebrationSong(file)
                setDraft({ ...cfg, song_url: res.data.url })
                toast('Song uploaded — press Save to keep it.', 'success')
              } catch (err) { toastError(errorMessage(err)) }
            }}
            className="block w-full text-sm text-slate-500 file:mr-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-emerald-700"
          />
        </div>
        <Button size="sm" variant="secondary" disabled={save.isPending} onClick={() => save.mutate()}>
          {save.isPending ? 'Saving…' : 'Save'}
        </Button>
      </div>
    </Card>
  )
}

/**
 * Festival celebrations: each occasion on the HR Policy's own holiday
 * calendar can turn the whole CRM festive — its own colour, its own song
 * (URL or uploaded file) — and open the wishes wall for everyone.
 */
function FestivalCelebrations() {
  const { toast, toastError } = useToast()
  const queryClient = useQueryClient()
  const { data } = useQuery({ queryKey: ['crm', 'festival-settings'], queryFn: crm.masterData.festivalSettings })
  const [draft, setDraft] = useState<Record<string, { enabled: boolean; color: string; song_url: string | null }> | null>(null)

  const cfg = draft ?? Object.fromEntries((data?.holidays ?? []).map((h) => [h.date, h.config]))

  const save = useMutation({
    mutationFn: () => crm.masterData.saveFestivalSettings(cfg),
    onSuccess: (res) => { toast(res.message, 'success'); queryClient.invalidateQueries({ queryKey: ['crm', 'festival-settings'] }) },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (!data) return null
  const setDay = (date: string, patch: Partial<{ enabled: boolean; color: string; song_url: string | null }>) =>
    setDraft({ ...cfg, [date]: { ...(cfg[date] ?? { enabled: false, color: '#e11d48', song_url: null }), ...patch } })

  return (
    <Card>
      <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Festival celebrations</h2>
      <p className="mt-0.5 text-xs text-slate-400">
        The occasions come from the HR Policy&rsquo;s holiday calendar. Switch one on and, on its day, the whole
        CRM turns festive in its colour, the song plays, and the wishes wall opens for everyone.
      </p>
      {data.holidays.length === 0 ? (
        <p className="mt-3 text-sm text-slate-400">No holidays on the calendar yet — add them in HR Policy.</p>
      ) : (
        <div className="mt-3 space-y-2">
          {data.holidays.map((h) => {
            const c = cfg[h.date] ?? h.config
            return (
              <div key={h.date} className="flex flex-wrap items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                <label className="flex min-w-0 flex-1 items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                  <input type="checkbox" checked={c.enabled} onChange={(e) => setDay(h.date, { enabled: e.target.checked })} className="size-4 accent-emerald-600" />
                  <span className="truncate font-medium">{h.name}</span>
                  <span className="text-xs text-slate-400">{h.date}</span>
                </label>
                <input type="color" value={c.color} onChange={(e) => setDay(h.date, { color: e.target.value })} title="Theme colour" className="size-8 cursor-pointer rounded border-0 bg-transparent" />
                <Input value={c.song_url ?? ''} onChange={(e) => setDay(h.date, { song_url: e.target.value || null })} placeholder="Song URL" className="w-44" />
                {/* The same Choose File control the Birthday card wears. */}
                <input
                  type="file"
                  accept="audio/*"
                  onChange={async (e) => {
                    const file = e.target.files?.[0]
                    if (!file) return
                    try {
                      const res = await crm.masterData.uploadCelebrationSong(file)
                      setDay(h.date, { song_url: res.data.url })
                      toast('Song uploaded — press Save festivals to keep it.', 'success')
                    } catch (err) { toastError(errorMessage(err)) }
                  }}
                  className="w-60 text-sm text-slate-500 file:mr-2 file:cursor-pointer file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-emerald-700"
                />
              </div>
            )
          })}
        </div>
      )}
      <Button size="sm" variant="secondary" className="mt-3" disabled={save.isPending} onClick={() => save.mutate()}>
        {save.isPending ? 'Saving…' : 'Save festivals'}
      </Button>
    </Card>
  )
}

function CompanyModal({ editing, onClose, onDone }: { editing?: Company; onClose: () => void; onDone: () => void }) {
  const [error, setError] = useState<string | null>(null)
  const [form, setForm] = useState({
    name: editing?.name ?? '',
    address: editing?.address ?? '',
    gstin: editing?.gstin ?? '',
    state_code: editing?.state_code ?? '',
    invoice_prefix: editing?.invoice_prefix ?? 'INV-',
    proforma_prefix: editing?.proforma_prefix ?? 'PI-',
    is_active: editing?.is_active ?? true,
    currency: editing?.currency ?? 'INR',
    pays_salary: editing?.pays_salary ?? false,
  })
  const [logo, setLogo] = useState<File | null>(null)
  const [stamp, setStamp] = useState<File | null>(null)

  const mutation = useMutation({
    mutationFn: async () => {
      const res = await crm.masterData.saveCompany({ ...form, address: form.address || null, gstin: form.gstin || null, state_code: form.state_code || null }, editing?.id) as { data?: { id?: number } }
      const id = editing?.id ?? res?.data?.id
      if (stamp && id) {
        await crm.masterData.uploadCompanyStamp(id, stamp)
      }
      if (logo && id) {
        await crm.masterData.uploadCompanyLogo(id, logo)
      }
      return res
    },
    onSuccess: onDone,
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={editing ? `Edit ${editing.name}` : 'Add issuing company'} onClose={onClose}>
      <div className="space-y-3">
        <ErrorNote message={error} />
        <div>
          <Label>Company name</Label>
          <Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} className="w-full" />
        </div>
        <div>
          <Label>Address</Label>
          <Input value={form.address} onChange={(e) => setForm((f) => ({ ...f, address: e.target.value }))} className="w-full" />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>GSTIN</Label>
            <Input value={form.gstin} onChange={(e) => setForm((f) => ({ ...f, gstin: e.target.value }))} className="w-full" />
          </div>
          <div>
            <Label>State code</Label>
            <Input value={form.state_code} onChange={(e) => setForm((f) => ({ ...f, state_code: e.target.value }))} placeholder="07" className="w-full" />
          </div>
          <div>
            <Label>Invoice prefix</Label>
            <Input value={form.invoice_prefix} onChange={(e) => setForm((f) => ({ ...f, invoice_prefix: e.target.value }))} className="w-full" />
          </div>
          <div>
            <Label>Proforma prefix</Label>
            <Input value={form.proforma_prefix} onChange={(e) => setForm((f) => ({ ...f, proforma_prefix: e.target.value }))} className="w-full" />
          </div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>Billing currency</Label>
            <Select value={form.currency} onChange={(e) => setForm((f) => ({ ...f, currency: e.target.value }))} className="w-full">
              {['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD'].map((c) => <option key={c} value={c}>{c}</option>)}
            </Select>
            <p className="mt-1 text-xs text-slate-400">
              A non-INR company bills whole invoices in that currency; each carries a universal INR equivalent at the live rate less the FX margin.
            </p>
          </div>
          <div>
            <Label>Company logo (prints on invoices &amp; payslips)</Label>
            <input
              type="file"
              accept="image/png,image/jpeg,image/webp"
              onChange={(e) => setLogo(e.target.files?.[0] ?? null)}
              className="block w-full text-sm text-slate-500 file:mr-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-emerald-700"
            />
            {editing?.logo_path && !logo && <p className="mt-1 text-xs text-emerald-600">Logo on file — upload to replace.</p>}
          </div>
          <div>
            {/* Its own upload, because a stamp is not a second logo: it prints
                over the signing space at the foot, not in the header. */}
            <Label>Company stamp (prints beside the signatory)</Label>
            <input
              type="file"
              accept="image/png,image/jpeg,image/webp"
              onChange={(e) => setStamp(e.target.files?.[0] ?? null)}
              className="block w-full text-sm text-slate-500 file:mr-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-emerald-700"
            />
            <p className="mt-1 text-xs text-slate-400">
              A PNG with a transparent background sits over the signature line properly; anything else
              prints as a white box on top of it.
            </p>
            {editing?.stamp_path && !stamp && (
              <p className="mt-1 flex items-center gap-2 text-xs text-emerald-600">
                Stamp on file — upload to replace.
                <button
                  type="button"
                  className="text-red-500 hover:underline"
                  onClick={() => {
                    if (editing.id && confirm(`Remove the stamp from this company's documents?`)) {
                      crm.masterData.deleteCompanyStamp(editing.id)
                        .then(() => { setStamp(null); onDone() })
                        .catch((err) => setError(errorMessage(err)))
                    }
                  }}
                >
                  Remove
                </button>
              </p>
            )}
          </div>
        </div>
        <label className="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
          <input type="checkbox" checked={form.pays_salary} onChange={(e) => setForm((f) => ({ ...f, pays_salary: e.target.checked }))} className="mt-0.5 size-4 accent-emerald-600" />
          <span>
            Salaries are paid from this registered company
            <span className="block text-xs text-slate-400">Payslips carry this company's details and logo. Only one company holds the tick — ticking here unticks the rest.</span>
          </span>
        </label>
        <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
          <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} className="size-4 accent-emerald-600" />
          Active
        </label>
        <Button className="w-full" disabled={!form.name || mutation.isPending} onClick={() => mutation.mutate()}>
          {mutation.isPending ? 'Saving…' : 'Save'}
        </Button>
      </div>
    </Modal>
  )
}

function BankModal({ editing, onClose, onDone }: { editing?: Bank; onClose: () => void; onDone: () => void }) {
  const [error, setError] = useState<string | null>(null)
  const [form, setForm] = useState({
    label: editing?.label ?? '',
    bank_name: editing?.bank_name ?? '',
    account_no: editing?.account_no ?? '',
    ifsc: editing?.ifsc ?? '',
    is_active: editing?.is_active ?? true,
    issuing_company_id: editing?.issuing_company_id ? String(editing.issuing_company_id) : '',
  })
  const { data: bankMasters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })

  const mutation = useMutation({
    mutationFn: () => crm.masterData.saveBank({
      ...form,
      bank_name: form.bank_name || null,
      account_no: form.account_no || null,
      ifsc: form.ifsc || null,
      issuing_company_id: form.issuing_company_id ? Number(form.issuing_company_id) : null,
    }, editing?.id),
    onSuccess: onDone,
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={editing ? `Edit ${editing.label}` : 'Add bank account'} onClose={onClose}>
      <div className="space-y-3">
        <ErrorNote message={error} />
        <div>
          <Label>Belongs to issuing company</Label>
          <Select value={form.issuing_company_id} onChange={(e) => setForm((f) => ({ ...f, issuing_company_id: e.target.value }))} className="w-full">
            <option value="">Unassigned (any company may print it)</option>
            {(bankMasters?.issuing_companies ?? []).map((c) => <option key={c.id} value={String(c.id)}>{c.name}</option>)}
          </Select>
          <p className="mt-1 text-xs text-slate-400">
            A company&rsquo;s invoices print its OWN account. Assign several accounts to one company by editing each account here.
          </p>
        </div>
        <div>
          <Label>Label</Label>
          <Input value={form.label} onChange={(e) => setForm((f) => ({ ...f, label: e.target.value }))} placeholder="HDFC (6948)" className="w-full" />
        </div>
        <div>
          <Label>Bank name</Label>
          <Input value={form.bank_name} onChange={(e) => setForm((f) => ({ ...f, bank_name: e.target.value }))} className="w-full" />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>Account number</Label>
            <Input value={form.account_no} onChange={(e) => setForm((f) => ({ ...f, account_no: e.target.value }))} className="w-full" />
          </div>
          <div>
            <Label>IFSC</Label>
            <Input value={form.ifsc} onChange={(e) => setForm((f) => ({ ...f, ifsc: e.target.value }))} className="w-full" />
          </div>
        </div>
        <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
          <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} className="size-4 accent-emerald-600" />
          Active
        </label>
        <Button className="w-full" disabled={!form.label || mutation.isPending} onClick={() => mutation.mutate()}>
          {mutation.isPending ? 'Saving…' : 'Save'}
        </Button>
      </div>
    </Modal>
  )
}


/**
 * Two rules about money: what happens when a payment is matched to a
 * document, and when unpaid invoices are chased without anyone typing.
 */
function PaymentRules() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [form, setForm] = useState<CrmPaymentSettings | null>(null)

  const { data } = useQuery({ queryKey: ['crm', 'payment-settings'], queryFn: crm.payments.settings })
  // The saved rules arrive once and are then the Admin's to change.
  if (data && !form) setForm(data)

  const saveMutation = useMutation({
    mutationFn: () => crm.payments.saveSettings(form!),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'payment-settings'] })
      queryClient.invalidateQueries({ queryKey: ['crm', 'payments'] })
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (!form) {
    return <Card><div className="flex justify-center py-8"><Spinner /></div></Card>
  }

  const offsets = form.reminders.offsets
  const toggleOffset = (day: number) => setForm({
    ...form,
    reminders: {
      ...form.reminders,
      offsets: offsets.includes(day) ? offsets.filter((d) => d !== day) : [...offsets, day].sort((a, b) => a - b),
    },
  })

  return (
    <Card>
      <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <Wallet className="size-4 text-emerald-500" /> Payments
      </h2>

      <div className="mt-3 space-y-4">
        <div>
          <Label>When a payment is matched to a document</Label>
          <Select
            value={form.settlement_mode}
            onChange={(e) => setForm({ ...form, settlement_mode: e.target.value as 'auto' | 'manual' })}
            className="w-full sm:max-w-md"
          >
            <option value="manual">An Admin checks it first, then settles it</option>
            <option value="auto">Settle it straight away</option>
          </Select>
          <p className="mt-1 text-xs text-slate-400">
            Whoever is not an Admin or Subadmin always sends it for checking, whichever rule is set.
            Settling a proforma turns it into a tax invoice and pays it.
          </p>
        </div>

        <div className="border-t border-slate-100 pt-4 dark:border-slate-800">
          <label className="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200">
            <input
              type="checkbox"
              checked={form.reminders.enabled}
              onChange={(e) => setForm({ ...form, reminders: { ...form.reminders, enabled: e.target.checked } })}
              className="size-4 accent-emerald-600"
            />
            <AlarmClock className="size-4 text-slate-400" /> Chase unpaid invoices automatically
          </label>

          {form.reminders.enabled && (
            <div className="mt-3 space-y-3 pl-6">
              <div>
                <Label>Write on these days</Label>
                <div className="flex flex-wrap gap-1.5">
                  {[-7, -3, 0, 3, 7, 15, 30, 45, 60, 90].map((day) => (
                    <button
                      key={day}
                      type="button"
                      onClick={() => toggleOffset(day)}
                      className={clsx(
                        'rounded-full px-3 py-1 text-xs font-medium transition',
                        offsets.includes(day)
                          ? 'bg-emerald-600 text-white'
                          : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-200 hover:ring-emerald-400 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700',
                      )}
                    >
                      {day === 0 ? 'On the due date' : day < 0 ? `${-day} days before` : `${day} days after`}
                    </button>
                  ))}
                </div>
                <p className="mt-1 text-xs text-slate-400">
                  Counted from the due date. Nothing goes out twice in a day, and a client with no e-mail on
                  file is left for someone to ring.
                </p>
              </div>
              <div>
                <Label>Give up after</Label>
                <Input
                  type="number"
                  min="1"
                  max="20"
                  value={form.reminders.stop_after}
                  onChange={(e) => setForm({
                    ...form,
                    reminders: { ...form.reminders, stop_after: Number(e.target.value) || 1 },
                  })}
                  className="w-24"
                />
                <span className="ml-2 text-xs text-slate-400">automatic reminders per invoice</span>
              </div>
            </div>
          )}
        </div>

        <Button onClick={() => saveMutation.mutate()} disabled={saveMutation.isPending}>
          {saveMutation.isPending ? 'Saving…' : 'Save payment rules'}
        </Button>
      </div>
    </Card>
  )
}


/**
 * The company's own Cashfree account. Its money must land in its bank, so the
 * credentials are per company — and the secret, once saved, is never handed
 * back to the browser.
 */
function CashfreeAccount() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [form, setForm] = useState<CrmGatewaySettings | null>(null)
  const [secret, setSecret] = useState('')

  const { data } = useQuery({ queryKey: ['crm', 'payment-gateway'], queryFn: crm.payments.gateway })
  if (data && !form) setForm(data)

  const saveMutation = useMutation({
    mutationFn: () => crm.payments.saveGateway({
      mode: form!.mode,
      app_id: form!.app_id ?? '',
      secret: secret || undefined,
      is_active: form!.is_active,
    }),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'payment-gateway'] })
      setSecret('')
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (!form) {
    return <Card><div className="flex justify-center py-8"><Spinner /></div></Card>
  }

  const copyWebhook = async () => {
    try {
      await navigator.clipboard.writeText(form.webhook_url)
      toast('Webhook URL copied.', 'success')
    } catch {
      toastError('Could not copy — select the address and copy it by hand.')
    }
  }

  return (
    <Card>
      <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <CreditCard className="size-4 text-emerald-500" /> Cashfree payment links
      </h2>
      <p className="mt-1 text-xs text-slate-400">
        Lets a client pay a proforma or invoice online. When Cashfree says a link is paid, the money is
        recorded and the document settled — a proforma becomes a tax invoice on the way.
      </p>

      <div className="mt-3 grid gap-3 sm:grid-cols-2">
        <div>
          <Label>Environment</Label>
          <Select
            value={form.mode}
            onChange={(e) => setForm({ ...form, mode: e.target.value as 'sandbox' | 'production' })}
            className="w-full"
          >
            <option value="sandbox">Sandbox (test money)</option>
            <option value="production">Production (real money)</option>
          </Select>
        </div>
        <div>
          <Label>App ID</Label>
          <Input value={form.app_id ?? ''} onChange={(e) => setForm({ ...form, app_id: e.target.value })} className="w-full" />
        </div>
        <div className="sm:col-span-2">
          <Label>Secret key</Label>
          <Input
            type="password"
            value={secret}
            onChange={(e) => setSecret(e.target.value)}
            placeholder={form.has_secret ? '•••••••• — leave blank to keep the one on file' : 'Paste the secret key'}
            className="w-full"
            autoComplete="off"
          />
          <p className="mt-1 text-xs text-slate-400">
            Stored encrypted and never shown again. It also signs the webhook, which is how we know a
            payment notice really came from Cashfree.
          </p>
        </div>
      </div>

      <label className="mt-3 flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
        <input
          type="checkbox"
          checked={form.is_active}
          onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
          className="size-4 accent-emerald-600"
        />
        Allow payment links on proformas and invoices
      </label>

      <div className="mt-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
        <Label>Webhook URL — paste this into your Cashfree dashboard</Label>
        <div className="flex items-center gap-2">
          <code className="min-w-0 flex-1 truncate text-xs text-slate-600 dark:text-slate-300">{form.webhook_url}</code>
          <Button size="sm" variant="secondary" onClick={copyWebhook}><Copy className="size-3.5" /> Copy</Button>
        </div>
        <p className="mt-1 text-xs text-slate-400">
          Subscribe it to the payment link event. Until it is set, a paid link will not settle itself.
        </p>
      </div>

      <Button className="mt-3" onClick={() => saveMutation.mutate()} disabled={saveMutation.isPending}>
        {saveMutation.isPending ? 'Saving…' : 'Save Cashfree settings'}
      </Button>
    </Card>
  )
}


/**
 * How often the due-lead popup returns when it is dismissed unattended.
 * The Admin's knob — a busy telecalling floor may want 10 minutes, a
 * consultative desk 60.
 */
function LeadAlertTiming() {
  const { toast, toastError } = useToast()
  const [minutes, setMinutes] = useState<string | null>(null)
  const [newMinutes, setNewMinutes] = useState<string | null>(null)

  const { data } = useQuery({ queryKey: ['crm', 'lead-settings'], queryFn: crm.leads.alertSettings })
  if (data && minutes === null) {
    setMinutes(String(data.alert_minutes))
    setNewMinutes(String(data.new_alert_minutes))
  }

  const saveMutation = useMutation({
    mutationFn: () => crm.leads.saveAlertSettings(Number(minutes), Number(newMinutes)),
    onSuccess: (res) => toast(res.message, 'success'),
    onError: (err) => toastError(errorMessage(err)),
  })

  if (minutes === null || newMinutes === null) {
    return <Card><div className="flex justify-center py-6"><Spinner /></div></Card>
  }

  const badMinutes = (v: string) => !v || Number(v) < 5 || Number(v) > 120

  return (
    <Card>
      <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <AlarmClock className="size-4 text-emerald-500" /> Lead alerts
      </h2>
      <p className="mt-1 text-xs text-slate-400">
        When a lead's follow-up time arrives, a popup lists it until somebody attends it. Dismissed
        unattended, the popup returns after this many minutes.
      </p>
      <div className="mt-3 flex items-center gap-2">
        <Input
          type="number"
          min="5"
          max="120"
          value={minutes}
          onChange={(e) => setMinutes(e.target.value)}
          className="w-24"
        />
        <span className="text-sm text-slate-500">minutes — follow-up alert</span>
      </div>
      <p className="mt-4 text-xs text-slate-400">
        A NEW lead pops up on its assignee's screen the moment it arrives, and keeps returning at
        this interval until they attend it — a first follow-up or a status change hands it over to
        the follow-up alerts above.
      </p>
      <div className="mt-3 flex items-center gap-2">
        <Input
          type="number"
          min="5"
          max="120"
          value={newMinutes}
          onChange={(e) => setNewMinutes(e.target.value)}
          className="w-24"
        />
        <span className="text-sm text-slate-500">minutes — new-lead alert</span>
        <Button
          size="sm"
          disabled={badMinutes(minutes) || badMinutes(newMinutes) || saveMutation.isPending}
          onClick={() => saveMutation.mutate()}
        >
          {saveMutation.isPending ? 'Saving…' : 'Save'}
        </Button>
      </div>
    </Card>
  )
}


/**
 * The words a lead is described in. Sources and subjects are the company's
 * own lists — one per line — and every dropdown that uses them follows.
 * Lead type stays New/Existing: the reports depend on it meaning one thing.
 */
/**
 * The Office Assets category list — the words stock is filed under. One per
 * line, the company's own; assets already in the register keep the category
 * they were added with, so editing this list never rewrites the shelves.
 */
function AssetCategories() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [text, setText] = useState<string | null>(null)

  const { data } = useQuery({ queryKey: ['crm', 'asset-categories'], queryFn: crm.masterData.assetCategories })
  if (data && text === null) setText(data.join('\n'))

  const saveMutation = useMutation({
    mutationFn: () => crm.masterData.saveAssetCategories(
      (text ?? '').split('\n').map((v) => v.trim()).filter(Boolean),
    ),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'asset-categories'] })
      queryClient.invalidateQueries({ queryKey: ['crm', 'assets'] })
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (text === null) {
    return <Card><div className="flex justify-center py-6"><Spinner /></div></Card>
  }

  return (
    <Card>
      <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <ListChecks className="size-4 text-emerald-500" /> Office Asset categories
      </h2>
      <p className="mt-1 text-xs text-slate-400">
        What the Add-items-to-stock dropdown offers — one per line, in the order you want them read.
        Leave it empty to go back to the built-in list.
      </p>
      <textarea
        rows={8}
        value={text}
        onChange={(e) => setText(e.target.value)}
        className="mt-3 w-full rounded-xl bg-white px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700"
      />
      <Button size="sm" variant="secondary" className="mt-3" disabled={saveMutation.isPending} onClick={() => saveMutation.mutate()}>
        {saveMutation.isPending ? 'Saving…' : 'Save categories'}
      </Button>
    </Card>
  )
}

function LeadOptions() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [sources, setSources] = useState<string | null>(null)
  const [subjects, setSubjects] = useState<string | null>(null)

  const { data } = useQuery({ queryKey: ['crm', 'lead-options'], queryFn: crm.leads.options })
  if (data && sources === null) {
    setSources(data.lead_sources.join('\n'))
    setSubjects(data.lead_subjects.join('\n'))
  }

  const saveMutation = useMutation({
    mutationFn: () => crm.leads.saveOptions({
      lead_sources: (sources ?? '').split('\n').map((v) => v.trim()).filter(Boolean),
      lead_subjects: (subjects ?? '').split('\n').map((v) => v.trim()).filter(Boolean),
    }),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'masters'] })
      queryClient.invalidateQueries({ queryKey: ['crm', 'lead-options'] })
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (sources === null || subjects === null) {
    return <Card><div className="flex justify-center py-6"><Spinner /></div></Card>
  }

  return (
    <Card>
      <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <ListChecks className="size-4 text-emerald-500" /> Lead options
      </h2>
      <p className="mt-1 text-xs text-slate-400">
        The dropdowns on every lead form read these lists — one entry per line. Leads already saved keep
        the words they were filed under.
      </p>
      <div className="mt-3 grid gap-3 sm:grid-cols-2">
        <div>
          <Label>Sources</Label>
          <textarea
            rows={8}
            value={sources}
            onChange={(e) => setSources(e.target.value)}
            className="w-full rounded-xl bg-white px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700"
          />
        </div>
        <div>
          <Label>Subjects</Label>
          <textarea
            rows={8}
            value={subjects}
            onChange={(e) => setSubjects(e.target.value)}
            className="w-full rounded-xl bg-white px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700"
          />
        </div>
      </div>
      <Button className="mt-3" size="sm" disabled={saveMutation.isPending} onClick={() => saveMutation.mutate()}>
        {saveMutation.isPending ? 'Saving…' : 'Save lead options'}
      </Button>
    </Card>
  )
}


/**
 * The reasons an approval can be asked for. Every company argues about
 * different things — a discount here, a mobile recharge there — so the list
 * is theirs. Requests already filed keep the words they were filed under.
 */
function ApprovalTypes() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [types, setTypes] = useState<string | null>(null)

  const { data } = useQuery({ queryKey: ['crm', 'approval-types'], queryFn: crm.approvals.types })
  if (data && types === null) setTypes(data.approval_types.join('\n'))

  const saveMutation = useMutation({
    mutationFn: () => crm.approvals.saveTypes(
      (types ?? '').split('\n').map((v) => v.trim()).filter(Boolean),
    ),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'masters'] })
      queryClient.invalidateQueries({ queryKey: ['crm', 'approval-types'] })
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (types === null) {
    return <Card><div className="flex justify-center py-6"><Spinner /></div></Card>
  }

  return (
    <Card>
      <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <ClipboardCheck className="size-4 text-emerald-500" /> Approval types
      </h2>
      <p className="mt-1 text-xs text-slate-400">
        What an employee may ask the Admin for — one per line. Invoice-related reasons (a discount, resending
        details) and general ones (an office recharge to claim back) all live in this list.
      </p>
      <textarea
        rows={8}
        value={types}
        onChange={(e) => setTypes(e.target.value)}
        className="mt-3 w-full rounded-xl bg-white px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700 sm:max-w-md"
      />
      <div>
        <Button className="mt-3" size="sm" disabled={saveMutation.isPending} onClick={() => saveMutation.mutate()}>
          {saveMutation.isPending ? 'Saving…' : 'Save approval types'}
        </Button>
      </div>
    </Card>
  )
}

/**
 * The words a complaint is filed under. Kept here, by the Admin or a
 * Subadmin, so nobody invents a subject mid-complaint and the same problem
 * is always filed under the same name — which is the only reason the
 * "whose error was it?" report means anything. Complaints already filed
 * keep the words they were filed under.
 */
function ComplaintOptions() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [draft, setDraft] = useState<{
    sources: string
    subjects: string
    types: string
    modes: string
    hours: string
  } | null>(null)

  const { data } = useQuery({ queryKey: ['crm', 'complaint-settings'], queryFn: crm.complaints.settings })
  if (data && draft === null) {
    setDraft({
      sources: data.complaint_sources.join('\n'),
      subjects: data.complaint_subjects.join('\n'),
      types: data.complaint_types.join('\n'),
      modes: data.complaint_modes.join('\n'),
      hours: String(data.resolve_hours),
    })
  }

  const lines = (v: string) => v.split('\n').map((x) => x.trim()).filter(Boolean)

  const saveMutation = useMutation({
    mutationFn: () => crm.complaints.saveSettings({
      complaint_sources: lines(draft!.sources),
      complaint_subjects: lines(draft!.subjects),
      complaint_types: lines(draft!.types),
      complaint_modes: lines(draft!.modes),
      resolve_hours: Number(draft!.hours) || 48,
    }),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'complaint-settings'] })
      queryClient.invalidateQueries({ queryKey: ['crm', 'complaint-options'] })
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (draft === null) {
    return <Card><div className="flex justify-center py-6"><Spinner /></div></Card>
  }

  const box = (key: keyof typeof draft, label: string, hint: string, rows: number) => (
    <div>
      <Label>{label}</Label>
      <p className="mb-1 text-xs text-slate-400">{hint}</p>
      <textarea
        rows={rows}
        value={draft[key]}
        onChange={(e) => setDraft((d) => ({ ...d!, [key]: e.target.value }))}
        className="w-full rounded-xl bg-white px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700"
      />
    </div>
  )

  return (
    <Card>
      <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <LifeBuoy className="size-4 text-emerald-500" /> Complaint options
      </h2>
      <p className="mt-1 text-xs text-slate-400">
        One per line. Only an Admin or Subadmin edits these — an employee filing a complaint picks from the
        list and asks you to add anything missing, which is what keeps the same problem under the same name.
      </p>

      <div className="mt-3 grid gap-3 sm:grid-cols-2">
        {box('subjects', 'Complaint subjects', 'What the complaint IS — the line above the description.', 10)}
        {box('sources', 'Complaint sources', 'Who it reached us through.', 10)}
        {box('types', 'Complaint types', 'The broad bucket it falls in.', 6)}
        {box('modes', 'Complaint modes', 'How it arrived.', 6)}
      </div>

      <div className="mt-3 sm:max-w-[220px]">
        <Label>Answer within (hours)</Label>
        <Input
          type="number"
          min="1"
          max="720"
          value={draft.hours}
          onChange={(e) => setDraft((d) => ({ ...d!, hours: e.target.value }))}
          className="w-full"
        />
        <p className="mt-1 text-xs text-slate-400">
          The standing promise. A complaint with no time of its own gets this one, and goes overdue past it.
        </p>
      </div>

      <div>
        <Button className="mt-3" size="sm" disabled={saveMutation.isPending} onClick={() => saveMutation.mutate()}>
          {saveMutation.isPending ? 'Saving…' : 'Save complaint options'}
        </Button>
      </div>
    </Card>
  )
}
