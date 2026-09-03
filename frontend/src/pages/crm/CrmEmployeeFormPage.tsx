import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, CheckCircle2, Download, FileText, Pencil, Plus, Search, Trash2, UserX } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, CRM_MODULE_LABELS, type CrmAccountMatch, type CrmEmployeeFull } from '../../api/crm'
import { LETTER_LABELS, letterAvailability, openLetter, type LetterType } from './letters'
import CrmCompensationCard from './CrmCompensationCard'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, ErrorNote, Input, Label, Modal, Select, Spinner, Textarea } from '../../components/ui'

const TITLES = ['Mr.', 'Mrs.', 'Miss', 'Ms.', 'Dr.']

interface FormState {
  name: string
  email: string
  password: string
  crm_role: string
  status: string
  employee_code: string
  title: string
  department: string
  designation: string
  batch: string
  father_name: string
  father_phone: string
  mother_name: string
  mother_phone: string
  dob: string
  gender: string
  present_address: string
  present_phone: string
  office_phone: string
  permanent_address: string
  permanent_phone: string
  personal_email: string
  joined_at: string
  probation_days: string
  resigned_at: string
  is_salesperson: boolean
  pf_no: string
  esi_no: string
  pan_no: string
  aadhaar_no: string
  bank_name: string
  bank_account_no: string
  bank_ifsc: string
  bank_account_name: string
  team_member_uuids: string[]
  late_waived: boolean
  punch_waived: boolean
  note: string
  salary_amount: string
  salary_from: string
}

const EMPTY: FormState = {
  name: '', email: '', password: '', crm_role: 'employee', status: 'active',
  employee_code: '', title: '', department: '', designation: '', batch: '',
  father_name: '', father_phone: '', mother_name: '', mother_phone: '',
  dob: '', gender: '', present_address: '', present_phone: '', office_phone: '',
  permanent_address: '', permanent_phone: '', personal_email: '',
  joined_at: '', probation_days: '', resigned_at: '', is_salesperson: false,
  pf_no: '', esi_no: '', pan_no: '', aadhaar_no: '',
  bank_name: '', bank_account_no: '', bank_ifsc: '', bank_account_name: '',
  team_member_uuids: [], late_waived: false, punch_waived: false, note: '', salary_amount: '', salary_from: '',
}

function Section({ title, hint, children }: { title: string; hint?: string; children: React.ReactNode }) {
  return (
    <Card>
      <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">{title}</h2>
      {hint && <p className="mt-0.5 text-xs text-slate-400">{hint}</p>}
      <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{children}</div>
    </Card>
  )
}

export default function CrmEmployeeFormPage() {
  const { uuid } = useParams()
  const editing = !!uuid
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [form, setForm] = useState<FormState>(EMPTY)
  const [rights, setRights] = useState<Record<string, string[]>>({})
  /*
   * Grants a Subadmin holds only by name — their role covers the rest.
   *
   * This list is what the tick-box screen offers them and what survives the
   * save, so a capability missing from it cannot be given to a Subadmin at
   * all, however willing the server is. employees.impersonate was exactly
   * that: the backend accepted it and nothing could ever send it.
   */
  const NAMED_GRANTS = ['exports.excel', 'reports.view', 'hr.policy_edit', 'employees.impersonate']

  // The register flow's first step: everyone signs up on Netvork the normal
  // way; the company fetches that account and fills only the employment side.
  // 'link' fetches an existing account; 'create' is the old full form.
  const [accountMode, setAccountMode] = useState<'link' | 'create'>('link')
  const [lookupQ, setLookupQ] = useState('')
  const [linked, setLinked] = useState<CrmAccountMatch | null>(null)
  const [lookingUp, setLookingUp] = useState(false)
  /*
   * The shortlist the search came back with.
   *
   * Null means nobody has searched yet, which is a different thing from a
   * search that found nobody — the first shows no list, the second says so.
   */
  const [matches, setMatches] = useState<CrmAccountMatch[] | null>(null)
  const [truncated, setTruncated] = useState(false)

  /** Take one of the results and fill the form from it. */
  const pickAccount = (found: CrmAccountMatch) => {
    if (found.already_member) {
      toastError('This account is already an employee of this organization.')
      return
    }
    setLinked(found)
    setMatches(null)
    setForm((f) => ({ ...f, name: found.name, email: found.email, password: '' }))
    toast(`Account linked — ${found.name}${found.username ? ` (@${found.username})` : ''}.`, 'success')
  }

  const fetchAccount = async (term = lookupQ, announce = true) => {
    const q = term.trim()
    if (q !== '' && q.length < 2) return
    setLookingUp(true)
    setLinked(null)
    try {
      const found = await crm.employees.lookupAccount(q || undefined)
      setTruncated(found.truncated)

      /*
       * One match and nothing to choose between: linking it is what the
       * person was going to do anyway, and a list of one is a click asking
       * to be told something it already knows.
       *
       * Only when they searched, though. The list that arrives on its own is
       * an address book, and an address book with one name in it must not
       * decide the answer for somebody who has not asked a question yet.
       */
      if (announce && q !== '' && found.data.length === 1 && !found.data[0].already_member) {
        pickAccount(found.data[0])
        return
      }
      setMatches(found.data)
    } catch (err) {
      setMatches([])
      if (announce) toastError(errorMessage(err))
    } finally {
      setLookingUp(false)
    }
  }

  /*
   * The people you already know, before you have typed anything.
   *
   * The box opened empty and silent, so registering a colleague began by
   * guessing at their spelling. Netvork's own add-connection box has always
   * led with your connections; this is the same list, in the place that
   * needs it most.
   */
  useEffect(() => {
    if (editing || accountMode !== 'link') return
    void fetchAccount('', false)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editing, accountMode])

  /*
   * Searching as they type, a beat behind. The button still works for
   * anybody who reaches for it, but nobody should have to press it to see
   * whether the name they are typing exists.
   */
  useEffect(() => {
    if (editing || accountMode !== 'link') return
    const q = lookupQ.trim()
    if (q !== '' && q.length < 2) return
    const t = setTimeout(() => { void fetchAccount(lookupQ, false) }, 350)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lookupQ, editing, accountMode])
  // The delicate acts, granted by name. Separate from the module matrix
  // because they are not "can see / can edit" — they move ownership or money.
  const [capabilities, setCapabilities] = useState<string[]>([])
  const [error, setError] = useState<string | null>(null)

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data: me } = useQuery({ queryKey: ['crm', 'me'], queryFn: crm.me })
  // Company authority, not a grantable right: a Team Head reads their
  // subtree here but never edits pay, documents or the profile itself.
  const manages = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  // The house figure, so the field can show what "blank" actually means.
  const { data: hrPolicy } = useQuery({ queryKey: ['crm', 'hr-policy'], queryFn: crm.hr.policy })
  const { data: existing, isLoading } = useQuery({
    queryKey: ['crm', 'employee', uuid],
    queryFn: () => crm.employees.get(uuid!),
    enabled: editing,
  })

  useEffect(() => {
    if (!existing) return
    setForm({
      ...EMPTY,
      name: existing.name ?? '',
      email: existing.email ?? '',
      crm_role: existing.crm_role,
      status: existing.status,
      employee_code: existing.employee_code ?? '',
      title: existing.title ?? '',
      department: existing.department ?? '',
      designation: existing.designation ?? '',
      batch: existing.batch ?? '',
      father_name: existing.father_name ?? '',
      father_phone: existing.father_phone ?? '',
      mother_name: existing.mother_name ?? '',
      mother_phone: existing.mother_phone ?? '',
      dob: existing.dob ?? '',
      gender: existing.gender ?? '',
      present_address: existing.present_address ?? '',
      present_phone: existing.present_phone ?? '',
      office_phone: existing.office_phone ?? '',
      permanent_address: existing.permanent_address ?? '',
      permanent_phone: existing.permanent_phone ?? '',
      personal_email: existing.personal_email ?? '',
      joined_at: existing.joined_at ?? '',
      probation_days: existing.probation_days === null || existing.probation_days === undefined ? '' : String(existing.probation_days),
      resigned_at: existing.resigned_at ?? '',
      is_salesperson: existing.is_salesperson,
      pf_no: existing.pf_no ?? '',
      esi_no: existing.esi_no ?? '',
      pan_no: existing.pan_no ?? '',
      aadhaar_no: existing.aadhaar_no ?? '',
      bank_name: existing.bank_name ?? '',
      bank_account_no: existing.bank_account_no ?? '',
      bank_ifsc: existing.bank_ifsc ?? '',
      bank_account_name: existing.bank_account_name ?? '',
      team_member_uuids: (existing.team ?? []).map((t) => t.uuid),
      late_waived: !!existing.late_waived,
      punch_waived: !!existing.punch_waived,
      note: existing.note ?? '',
    })
    setRights(existing.rights && !Array.isArray(existing.rights) ? { ...existing.rights } : {})
    setCapabilities(existing.capabilities ?? [])
  }, [existing])

  const set = <K extends keyof FormState>(key: K, value: FormState[K]) =>
    setForm((f) => ({ ...f, [key]: value }))

  const profilePayload = () => ({
    crm_role: form.crm_role,
    status: form.status,
    employee_code: form.employee_code || null,
    title: form.title || null,
    department: form.department || null,
    designation: form.designation || null,
    batch: form.batch || null,
    father_name: form.father_name || null,
    father_phone: form.father_phone || null,
    mother_name: form.mother_name || null,
    mother_phone: form.mother_phone || null,
    dob: form.dob || null,
    gender: form.gender || null,
    present_address: form.present_address || null,
    present_phone: form.present_phone || null,
    office_phone: form.office_phone || null,
    permanent_address: form.permanent_address || null,
    permanent_phone: form.permanent_phone || null,
    personal_email: form.personal_email || null,
    joined_at: form.joined_at || null,
    // Blank means the HR Policy's own figure — one knob moves everybody.
    probation_days: form.probation_days === '' ? null : Number(form.probation_days),
    resigned_at: form.resigned_at || null,
    is_salesperson: form.is_salesperson,
    pf_no: form.pf_no || null,
    esi_no: form.esi_no || null,
    pan_no: form.pan_no || null,
    aadhaar_no: form.aadhaar_no || null,
    bank_name: form.bank_name || null,
    bank_account_no: form.bank_account_no || null,
    bank_ifsc: form.bank_ifsc || null,
    bank_account_name: form.bank_account_name || null,
    // "Reports to" is not asked any more: a new hire lands under the Admin
    // and team leadership is granted through the Team Workspace ticks.
    team_member_uuids: form.team_member_uuids,
    // The waiver rides only when the Admin holds the pen — the server
    // ignores it from anyone else anyway.
    late_waived: form.late_waived,
    punch_waived: form.punch_waived,
    rights: form.crm_role === 'admin' ? undefined : rights,
    // A Subadmin keeps only the by-name grants (exports, reports, opening a
    // workspace); the rest of the list their role already carries, and an
    // Admin needs none of it.
    capabilities: form.crm_role === 'admin' ? []
      : form.crm_role === 'subadmin'
        ? capabilities.filter((c) => NAMED_GRANTS.includes(c))
        : capabilities,
    note: form.note || null,
  })

  const saveMutation = useMutation({
    mutationFn: () => {
      if (editing) return crm.employees.update(uuid!, profilePayload())
      return crm.employees.create({
        ...profilePayload(),
        name: form.name,
        email: form.email,
        password: form.password || null,
        salary: form.salary_amount
          ? { amount: Number(form.salary_amount), effective_from: form.salary_from || form.joined_at || new Date().toISOString().slice(0, 10) }
          : null,
      })
    },
    onSuccess: (res: { message?: string; data?: { uuid?: string } }) => {
      queryClient.invalidateQueries({ queryKey: ['crm'] })
      toast(res.message ?? 'Saved.', 'success')
      if (!editing && res.data?.uuid) navigate(`/crm/employees/${res.data.uuid}`, { replace: true })
      setError(null)
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const deactivateMutation = useMutation({
    mutationFn: () => crm.employees.deactivate(uuid!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crm'] })
      navigate('/crm/employees')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const toggleRight = (module: string, ability: string) => {
    setRights((r) => {
      const current = new Set(r[module] ?? [])
      if (current.has(ability)) current.delete(ability)
      else current.add(ability)
      const next = { ...r }
      if (current.size === 0) delete next[module]
      else next[module] = [...current]
      return next
    })
  }

  /** The column's "All" box: grant or clear one ability across every module. */
  const toggleColumnAll = (ability: string, modules: string[]) => {
    setRights((r) => {
      const everyModuleHasIt = modules.every((m) => (r[m] ?? []).includes(ability))
      const next: Record<string, string[]> = {}
      for (const m of modules) {
        const current = new Set(r[m] ?? [])
        if (everyModuleHasIt) current.delete(ability)
        else current.add(ability)
        if (current.size > 0) next[m] = [...current]
      }
      return next
    })
  }

  const grantEverything = (modules: string[], abilities: string[]) =>
    setRights(Object.fromEntries(modules.map((m) => [m, [...abilities]])))

  const clearEverything = () => setRights({})

  if (editing && isLoading) {
    return <div className="flex justify-center py-20"><Spinner /></div>
  }

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <button onClick={() => navigate('/crm/employees')} aria-label="Back" className="rounded p-1.5 text-slate-400 hover:bg-slate-200/60 dark:hover:bg-slate-800">
            <ArrowLeft className="size-4" />
          </button>
          <div>
            <h1 className="text-xl font-semibold text-slate-900 dark:text-white">
              {editing ? existing?.name ?? 'Employee' : 'Register employee'}
            </h1>
            {editing && <p className="text-sm text-slate-500">{existing?.email}</p>}
          </div>
        </div>
        <div className="flex gap-2">
          {manages && editing && existing?.status === 'active' && (
            <Button variant="danger" onClick={() => { if (confirm('Deactivate this employee? They lose CRM access but keep their Netvork account.')) deactivateMutation.mutate() }}>
              <UserX className="size-4" /> Deactivate
            </Button>
          )}
          {manages && (
            <Button
              onClick={() => saveMutation.mutate()}
              disabled={saveMutation.isPending || (!editing && accountMode === 'link' && !linked)}
              title={!editing && accountMode === 'link' && !linked ? 'Fetch the Netvork account first' : undefined}
            >
              {saveMutation.isPending ? 'Saving…' : editing ? 'Save changes' : 'Register'}
            </Button>
          )}
        </div>
      </div>
      {!manages && (
        <p className="rounded-xl bg-slate-100 px-4 py-2.5 text-sm text-slate-500 dark:bg-slate-800/60">
          You are viewing this profile. Only a CRM admin or subadmin can change it.
          {existing?.personal_hidden && (
            <span className="block text-xs text-slate-400">
              Personal details, statutory numbers, bank, pay and documents are private — visible to the
              person and the Admin only.
            </span>
          )}
        </p>
      )}

      <ErrorNote message={error} />

      <Section
        title="Account"
        hint={editing ? 'Login belongs to the Netvork account; change email or password from there.'
          : accountMode === 'link'
            ? 'The person signs up on Netvork first; fetch their account here and finish only the employment side.'
            : 'A new verified Netvork account is created with this email and password.'}
      >
        {!editing && (
          <div className="sm:col-span-2 lg:col-span-3">
            <div className="flex gap-2">
              {([['link', 'Existing Netvork account'], ['create', 'Create a new account']] as const).map(([mode, label]) => (
                <button
                  key={mode}
                  type="button"
                  onClick={() => { setAccountMode(mode); setLinked(null) }}
                  className={clsx(
                    'rounded-full px-3 py-1.5 text-xs font-medium',
                    accountMode === mode
                      ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                      : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
                  )}
                >
                  {label}
                </button>
              ))}
            </div>
            {accountMode === 'link' && (
              <div className="mt-3 flex flex-wrap items-center gap-2">
                <Input
                  value={lookupQ}
                  onChange={(e) => setLookupQ(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); void fetchAccount() } }}
                  placeholder="Name, email, username or App ID — e.g. priyanshu"
                  className="w-full sm:w-80"
                />
                <Button size="sm" variant="secondary" disabled={lookingUp} onClick={() => void fetchAccount()}>
                  <Search className="size-3.5" /> {lookingUp ? 'Searching…' : 'Search accounts'}
                </Button>
                {linked && (
                  <span className="flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                    <CheckCircle2 className="size-3.5" />
                    {linked.name}{linked.username ? ` · @${linked.username}` : ''} · {linked.email}
                  </span>
                )}
                {/*
                  * The shortlist. Names are not unique — two Priyanshus are a
                  * normal Tuesday — so the search answers with everyone it
                  * matched and the person registering says which.
                  */}
                {matches !== null && matches.length > 0 && (
                  <p className="w-full text-[11px] font-medium uppercase tracking-wide text-slate-400">
                    {lookupQ.trim() ? 'Matches' : 'Your connections'}
                  </p>
                )}
                {matches !== null && matches.length > 0 && (
                  <ul className="w-full divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 dark:divide-slate-700 dark:border-slate-700">
                    {matches.map((m) => (
                      <li key={m.email}>
                        <button
                          type="button"
                          disabled={m.already_member}
                          onClick={() => pickAccount(m)}
                          className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm enabled:hover:bg-slate-50 disabled:opacity-60 dark:enabled:hover:bg-slate-800"
                        >
                          <span className="min-w-0">
                            <span className="block truncate font-medium">
                              {m.name}{m.username ? ` · @${m.username}` : ''}
                            </span>
                            <span className="block truncate text-xs text-slate-500">
                              {m.email}{m.app_id ? ` · ${m.app_id}` : ''}
                            </span>
                          </span>
                          <span className="shrink-0 text-xs text-slate-400">
                            {m.already_member
                              ? 'Already an employee'
                              : m.connected ? 'Connected · Choose' : 'Choose'}
                          </span>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
                {matches !== null && matches.length === 0 && !lookingUp && (
                  <p className="w-full text-xs text-slate-500">
                    {lookupQ.trim()
                      ? 'Nobody on Netvork matches that. They need an account before they can be registered here.'
                      : 'You have no connections yet — search by name, email, username or App ID to find the account.'}
                  </p>
                )}
                {truncated && matches !== null && matches.length > 0 && (
                  <p className="w-full text-xs text-slate-400">
                    Showing the first {matches.length}. Add more of the name to narrow it down.
                  </p>
                )}
              </div>
            )}
          </div>
        )}
        {(editing || accountMode === 'create' || linked) && (
          <>
            <div>
              <Label>Full name</Label>
              <Input value={form.name} onChange={(e) => set('name', e.target.value)} disabled={editing || (accountMode === 'link' && !!linked)} className="w-full" />
            </div>
            <div>
              <Label>Email (login)</Label>
              <Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} disabled={editing || (accountMode === 'link' && !!linked)} className="w-full" />
            </div>
          </>
        )}
        {!editing && accountMode === 'create' && (
          <div>
            <Label>Password (for a new account)</Label>
            <Input type="password" value={form.password} onChange={(e) => set('password', e.target.value)} placeholder="Min 8, letters + numbers" className="w-full" />
          </div>
        )}
        <div>
          <Label>CRM role</Label>
          <Select value={form.crm_role} onChange={(e) => set('crm_role', e.target.value)} className="w-full">
            <option value="admin">Admin — full CRM access</option>
            <option value="subadmin">Subadmin — granted modules</option>
            <option value="employee">Employee — granted modules</option>
          </Select>
        </div>
        <div>
          <Label>Status</Label>
          <Select value={form.status} onChange={(e) => set('status', e.target.value)} className="w-full">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </Select>
        </div>
        <div className="flex items-end pb-2">
          <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" checked={form.is_salesperson} onChange={(e) => set('is_salesperson', e.target.checked)} className="size-4 accent-emerald-600" />
            Is a salesperson
          </label>
        </div>
      </Section>

      <Section title="Employment">
        <div>
          <Label>Employee code</Label>
          <Input value={form.employee_code} onChange={(e) => set('employee_code', e.target.value)} placeholder="Automatic — EMP-101 onwards" className="w-full" />
          {!editing && (
            <p className="mt-1 text-xs text-slate-400">Leave blank and the next number is issued automatically.</p>
          )}
        </div>
        <div>
          <Label>Department</Label>
          <Select value={form.department} onChange={(e) => set('department', e.target.value)} className="w-full">
            <option value="">Select</option>
            {masters?.departments.map((d) => <option key={d} value={d}>{d}</option>)}
          </Select>
        </div>
        <div>
          <Label>Designation</Label>
          <Select value={form.designation} onChange={(e) => set('designation', e.target.value)} className="w-full">
            <option value="">Select</option>
            {masters?.designations.map((d) => <option key={d} value={d}>{d}</option>)}
          </Select>
        </div>
        <div>
          <Label>Batch</Label>
          <Input value={form.batch} onChange={(e) => set('batch', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Joining date</Label>
          <Input type="date" value={form.joined_at} onChange={(e) => set('joined_at', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Resignation date</Label>
          <Input type="date" value={form.resigned_at} onChange={(e) => set('resigned_at', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Probation period (days)</Label>
          <Input
            type="number"
            min="0"
            max="1095"
            value={form.probation_days}
            onChange={(e) => set('probation_days', e.target.value)}
            className="w-full"
            placeholder={hrPolicy ? `${hrPolicy.policy.probation_days} (HR Policy)` : '180 (HR Policy)'}
          />
          <p className="mt-1 text-xs text-slate-400">
            Leave blank to follow the HR Policy. A longer probation here applies to this person only — it delays
            when they start earning paid leave, and it is written into the user log.
            {existing?.probation_ends_on && (
              <> Currently ends <span className="font-medium">{existing.probation_ends_on}</span>
                {existing.on_probation ? ' — still on probation.' : '.'}</>
            )}
          </p>
        </div>
        {/* The Admin's late waiver: only the Admin sees the switch, and
            only a person actually holding it sees the notice on their own
            profile — nobody else even knows the feature exists. */}
        {me?.member?.crm_role === 'admin' && (
          <div className="flex items-end pb-2">
            <label className="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
              <input
                type="checkbox"
                checked={form.late_waived}
                onChange={(e) => set('late_waived', e.target.checked)}
                className="mt-0.5 size-4 accent-emerald-600"
              />
              <span>
                Late waived off
                <span className="block text-xs text-slate-400">No late is counted for this person — a late arrival is marked Present. Admin only.</span>
              </span>
            </label>
          </div>
        )}
        {me?.member?.crm_role !== 'admin' && existing?.late_waived && existing?.uuid === me?.member?.uuid && (
          <p className="flex items-end pb-2 text-xs font-medium text-emerald-600">
            Late waived off — your late arrivals are marked Present.
          </p>
        )}
        {/* The punch waiver: for the people the clock was never about — a
            director, a founder — so the register stops calling their
            working days absences and payroll stops docking for them. */}
        {me?.member?.crm_role === 'admin' && (
          <div className="flex items-end pb-2">
            <label className="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
              <input
                type="checkbox"
                checked={form.punch_waived}
                onChange={(e) => set('punch_waived', e.target.checked)}
                className="mt-0.5 size-4 accent-emerald-600"
              />
              <span>
                Punch in / out waived off
                <span className="block text-xs text-slate-400">
                  This person does not clock in. Working days count as Present without a punch, so the
                  register and the payroll stop treating them as absences. Admin only.
                </span>
              </span>
            </label>
          </div>
        )}
        {me?.member?.crm_role !== 'admin' && existing?.punch_waived && existing?.uuid === me?.member?.uuid && (
          <p className="flex items-end pb-2 text-xs font-medium text-emerald-600">
            Punch waived off — you do not need to clock in or out.
          </p>
        )}
        {!editing && (
          <>
            <div>
              <Label>Starting salary (₹/month)</Label>
              <Input type="number" min="0" value={form.salary_amount} onChange={(e) => set('salary_amount', e.target.value)} className="w-full" />
            </div>
            <div>
              <Label>Salary effective from</Label>
              <Input type="date" value={form.salary_from} onChange={(e) => set('salary_from', e.target.value)} className="w-full" />
            </div>
          </>
        )}
      </Section>

      {/* The office kit in this person's hands — from the assets register. */}
      {editing && <MemberAssetsCard memberUuid={uuid!} manages={manages} />}

      {/* The Team Workspace: everyone sits under the company (Admin) by
          default; ticking names here puts those people in THIS person's
          hands — their window, and their team incentive, widen to them.
          Only an Admin/Subadmin steers it; everyone else reads it. */}
      <Card>
        <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Team Workspace</h2>
        <p className="mt-0.5 text-xs text-slate-400">
          Every employee is under the company (Admin) by default. Tick who <span className="font-medium">{form.name || 'this person'}</span> handles —
          they become a team leader over those people: they see their employees, sales and complaints, and the
          team incentive on their plan runs on these people's sales.
          {!manages && ' Only an Admin or Subadmin can change this.'}
        </p>
        {(existing?.team_leaders?.length ?? 0) > 0 && (
          <p className="mt-2 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/60">
            Handled by: {existing!.team_leaders!.map((l) => l.name).filter(Boolean).join(', ')}
          </p>
        )}
        <div className="mt-3 grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
          {/* Employees only: the Admin side already controls everything, so
              it is never something to be handed into someone's hands. */}
          {(masters?.members ?? []).filter((m) => m.uuid !== uuid && (m.crm_role ?? 'employee') === 'employee').map((m) => (
            <label
              key={m.uuid}
              className={clsx(
                'flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm',
                manages ? 'cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/60' : 'opacity-70',
                form.team_member_uuids.includes(m.uuid) && 'bg-emerald-50/70 dark:bg-emerald-500/10',
              )}
            >
              <input
                type="checkbox"
                disabled={!manages}
                checked={form.team_member_uuids.includes(m.uuid)}
                onChange={() => set('team_member_uuids', form.team_member_uuids.includes(m.uuid)
                  ? form.team_member_uuids.filter((u) => u !== m.uuid)
                  : [...form.team_member_uuids, m.uuid])}
                className="size-4 accent-emerald-600"
              />
              <span className="text-slate-700 dark:text-slate-200">
                {m.name}
                {m.employee_code && <span className="ml-1 text-xs text-slate-400">({m.employee_code})</span>}
              </span>
            </label>
          ))}
        </div>
        {form.team_member_uuids.length > 0 && (
          <p className="mt-2 text-xs text-emerald-600">
            Handles {form.team_member_uuids.length} {form.team_member_uuids.length === 1 ? 'person' : 'people'} — saved with the profile.
          </p>
        )}
      </Card>

      <Section title="Personal & family">
        <div>
          <Label>Title</Label>
          <Select value={form.title} onChange={(e) => set('title', e.target.value)} className="w-full">
            <option value="">Select</option>
            {TITLES.map((t) => <option key={t} value={t}>{t}</option>)}
          </Select>
        </div>
        <div>
          <Label>Birth date</Label>
          <Input type="date" value={form.dob} onChange={(e) => set('dob', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Gender</Label>
          <Select value={form.gender} onChange={(e) => set('gender', e.target.value)} className="w-full">
            <option value="">Select</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
            <option value="other">Other</option>
          </Select>
        </div>
        <div>
          <Label>Father's name</Label>
          <Input value={form.father_name} onChange={(e) => set('father_name', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Father's phone</Label>
          <Input value={form.father_phone} onChange={(e) => set('father_phone', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Mother's name</Label>
          <Input value={form.mother_name} onChange={(e) => set('mother_name', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Mother's phone</Label>
          <Input value={form.mother_phone} onChange={(e) => set('mother_phone', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Personal email</Label>
          <Input type="email" value={form.personal_email} onChange={(e) => set('personal_email', e.target.value)} className="w-full" />
        </div>
      </Section>

      <Section title="Contact & addresses">
        <div className="sm:col-span-2">
          <Label>Present address</Label>
          <Input value={form.present_address} onChange={(e) => set('present_address', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Phone</Label>
          <Input value={form.present_phone} onChange={(e) => set('present_phone', e.target.value)} className="w-full" />
        </div>
        <div className="sm:col-span-2">
          <Label>Permanent address</Label>
          <Input value={form.permanent_address} onChange={(e) => set('permanent_address', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Permanent phone</Label>
          <Input value={form.permanent_phone} onChange={(e) => set('permanent_phone', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Office phone</Label>
          <Input value={form.office_phone} onChange={(e) => set('office_phone', e.target.value)} className="w-full" />
        </div>
      </Section>

      <Section title="Statutory & bank" hint="Used by payroll and compliance later; all optional.">
        <div>
          <Label>PF number</Label>
          <Input value={form.pf_no} onChange={(e) => set('pf_no', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>ESI number</Label>
          <Input value={form.esi_no} onChange={(e) => set('esi_no', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>PAN</Label>
          <Input value={form.pan_no} onChange={(e) => set('pan_no', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Aadhaar</Label>
          <Input value={form.aadhaar_no} onChange={(e) => set('aadhaar_no', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Bank name</Label>
          <Input value={form.bank_name} onChange={(e) => set('bank_name', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Account number</Label>
          <Input value={form.bank_account_no} onChange={(e) => set('bank_account_no', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>IFSC</Label>
          <Input value={form.bank_ifsc} onChange={(e) => set('bank_ifsc', e.target.value)} className="w-full" />
        </div>
        <div>
          <Label>Account holder name</Label>
          <Input value={form.bank_account_name} onChange={(e) => set('bank_account_name', e.target.value)} className="w-full" />
        </div>
        <div className="sm:col-span-2 lg:col-span-3">
          <Label>Note</Label>
          <Textarea rows={2} value={form.note} onChange={(e) => set('note', e.target.value)} className="w-full" />
        </div>
      </Section>

      {form.crm_role !== 'admin' && masters && (
        <Card>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Module rights</h2>
              <p className="mt-0.5 text-xs text-slate-400">What this {form.crm_role} can see and do. Admins always have everything.</p>
            </div>
            <div className="flex gap-1.5">
              <Button size="sm" variant="secondary" onClick={() => grantEverything(masters.modules, masters.abilities)}>
                Select all
              </Button>
              <Button size="sm" variant="ghost" onClick={clearEverything}>
                Clear all
              </Button>
            </div>
          </div>
          <div className="-mx-4 mt-3 overflow-x-auto px-4">
            <table className="w-full min-w-[560px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Module</th>
                  {masters.abilities.map((a) => (
                    <th key={a} className="py-2 pr-3 font-medium">
                      <span className="capitalize">{a}</span>
                      <label className="mt-1 flex items-center gap-1 normal-case text-slate-400" title={`Grant or clear "${a}" on every module`}>
                        <input
                          type="checkbox"
                          className="size-3.5 accent-emerald-600"
                          checked={masters.modules.every((m) => (rights[m] ?? []).includes(a))}
                          onChange={() => toggleColumnAll(a, masters.modules)}
                        />
                        All
                      </label>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {masters.modules.map((mod) => (
                  <tr key={mod} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="py-2 pr-3 font-medium text-slate-700 dark:text-slate-200">{CRM_MODULE_LABELS[mod] ?? mod}</td>
                    {masters.abilities.map((a) => (
                      <td key={a} className="py-2 pr-3">
                        <input
                          type="checkbox"
                          className="size-4 accent-emerald-600"
                          checked={(rights[mod] ?? []).includes(a)}
                          onChange={() => toggleRight(mod, a)}
                        />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {/* The acts that move ownership or money. An Admin and a Subadmin hold
          them by the nature of the job; an employee holds what is ticked. */}
      {(form.crm_role === 'employee' || form.crm_role === 'subadmin') && masters && (
        <Card>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Special permissions</h2>
              <p className="mt-0.5 text-xs text-slate-400">
                {form.crm_role === 'subadmin'
                  ? 'Held by name even for a Subadmin — the Excel export, the Reports screen and opening an employee’s workspace open only when ticked here.'
                  : 'Acts normally kept with the Company Admin. Tick one to hand it to this employee.'}
              </p>
            </div>
            <Button size="sm" variant="ghost" onClick={() => setCapabilities([])}>Clear all</Button>
          </div>
          <div className="mt-3 space-y-4">
            {Object.entries(
              (masters.capabilities ?? [])
                .filter((cap) => form.crm_role !== 'subadmin' || NAMED_GRANTS.includes(cap.key))
                .reduce<Record<string, typeof masters.capabilities>>((groups, cap) => {
                groups[cap.group] = [...(groups[cap.group] ?? []), cap]
                return groups
              }, {}),
            ).map(([group, caps]) => (
              <div key={group}>
                <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{group}</p>
                <div className="grid gap-2 sm:grid-cols-2">
                  {caps.map((cap) => (
                    <label key={cap.key} className="flex items-start gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                      <input
                        type="checkbox"
                        checked={capabilities.includes(cap.key)}
                        onChange={(e) => setCapabilities((prev) => (e.target.checked
                          ? [...prev, cap.key]
                          : prev.filter((c) => c !== cap.key)))}
                        className="mt-0.5 size-4 accent-emerald-600"
                      />
                      <span>{cap.label}</span>
                    </label>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </Card>
      )}

      {editing && existing && manages && <KpiAssignmentCard uuid={uuid!} />}
      {/* The CTC structure, incentive plan and loans — the terms every
          payroll run computes from. */}
      {editing && existing && manages && <CrmCompensationCard memberUuid={uuid!} />}
      {editing && existing && manages && <SalaryCard uuid={uuid!} existing={existing} />}
      {editing && existing && manages && <DocumentsCard uuid={uuid!} existing={existing} />}
      {editing && existing && manages && <LettersCard existing={existing} />}
      {editing && existing && !manages && (
        <Card>
          <p className="text-sm text-slate-500">
            Salary, documents, KPI assignment and HR letters are managed by your CRM admin.
          </p>
        </Card>
      )}

      {manages && (
        <div className="flex justify-end pb-8">
          <Button
            onClick={() => saveMutation.mutate()}
            disabled={saveMutation.isPending || (!editing && accountMode === 'link' && !linked)}
            title={!editing && accountMode === 'link' && !linked ? 'Fetch the Netvork account first' : undefined}
          >
            {saveMutation.isPending ? 'Saving…' : editing ? 'Save changes' : 'Register'}
          </Button>
        </div>
      )}
    </div>
  )
}

/**
 * Which KPI parameters this employee reports in their DWR, with weightage
 * and daily target — the old CRM's checkbox grid, structured.
 */
function KpiAssignmentCard({ uuid }: { uuid: string }) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [rows, setRows] = useState<{ parameter_id: number; weightage: string; daily_target: string }[]>([])
  const [loaded, setLoaded] = useState(false)
  const [newName, setNewName] = useState('')
  const [newUnit, setNewUnit] = useState('count')
  // Editing a catalog entry in place: rename it, or change its kind
  // (count / currency / percent / yes-no). Applies org-wide; old reports
  // keep their snapshots.
  const [editParam, setEditParam] = useState<{ id: number; name: string; unit: string } | null>(null)

  const { data: parameters } = useQuery({ queryKey: ['crm', 'dwr', 'parameters'], queryFn: crm.dwr.parameters })
  useQuery({
    queryKey: ['crm', 'dwr', 'assignments', uuid],
    queryFn: async () => {
      const assigned = await crm.dwr.assignments(uuid)
      setRows(assigned.map((a) => ({
        parameter_id: a.parameter_id,
        weightage: String(a.weightage),
        daily_target: String(Number(a.daily_target)),
      })))
      setLoaded(true)
      return assigned
    },
  })

  const totalWeight = rows.reduce((s, r) => s + (Number(r.weightage) || 0), 0)

  const saveMutation = useMutation({
    mutationFn: () =>
      crm.dwr.saveAssignments(uuid, rows.map((r) => ({
        parameter_id: r.parameter_id,
        weightage: Number(r.weightage) || 1,
        daily_target: Number(r.daily_target) || 0,
      }))),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'dwr'] })
      toast('KPI assignment saved.', 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const editParamMutation = useMutation({
    mutationFn: () => crm.dwr.updateParameter(editParam!.id, { name: editParam!.name, unit: editParam!.unit }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'dwr', 'parameters'] })
      setEditParam(null)
      toast('Parameter updated for the whole company. Old reports keep their snapshots.', 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const addParamMutation = useMutation({
    mutationFn: () => crm.dwr.addParameter(newName, newUnit),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'dwr', 'parameters'] })
      setRows((r) => [...r, { parameter_id: res.data.id, weightage: '10', daily_target: '0' }])
      setNewName('')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const toggle = (parameterId: number) =>
    setRows((r) => (r.some((x) => x.parameter_id === parameterId)
      ? r.filter((x) => x.parameter_id !== parameterId)
      : [...r, { parameter_id: parameterId, weightage: '10', daily_target: '0' }]))

  const setRow = (parameterId: number, key: 'weightage' | 'daily_target', value: string) =>
    setRows((r) => r.map((x) => (x.parameter_id === parameterId ? { ...x, [key]: value } : x)))

  if (!loaded || !parameters) return null

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">DWR KPI parameters</h2>
          <p className="mt-0.5 text-xs text-slate-400">
            What this employee reports daily. Weightage total:{' '}
            <span className={totalWeight === 100 ? 'font-medium text-emerald-600' : 'font-medium text-amber-600'}>{totalWeight}</span>
            {totalWeight !== 100 && ' (100 recommended)'}
          </p>
        </div>
        <Button size="sm" onClick={() => saveMutation.mutate()} disabled={saveMutation.isPending}>
          {saveMutation.isPending ? 'Saving…' : 'Save KPIs'}
        </Button>
      </div>

      <div className="mt-3 grid gap-1.5 sm:grid-cols-2">
        {parameters.filter((p) => p.is_active).map((p) => {
          const row = rows.find((r) => r.parameter_id === p.id)
          return (
            <div key={p.id} className={clsx('flex items-center gap-2 rounded-lg px-2.5 py-1.5', row ? 'bg-emerald-50 dark:bg-emerald-500/10' : 'bg-slate-50 dark:bg-slate-800/60')}>
              <input type="checkbox" checked={!!row} onChange={() => toggle(p.id)} className="size-4 shrink-0 accent-emerald-600" />
              {editParam?.id === p.id ? (
                <span className="flex min-w-0 flex-1 items-center gap-1">
                  <Input value={editParam.name} onChange={(e) => setEditParam({ ...editParam, name: e.target.value })} className="w-full px-1.5 py-1 text-xs" />
                  <Select value={editParam.unit} onChange={(e) => setEditParam({ ...editParam, unit: e.target.value })} className="px-1 py-1 text-xs">
                    <option value="count">Count</option>
                    <option value="percent">Percent</option>
                    <option value="currency">Currency</option>
                    <option value="boolean">Yes/No</option>
                  </Select>
                  <button onClick={() => editParamMutation.mutate()} disabled={!editParam.name.trim() || editParamMutation.isPending} className="shrink-0 text-xs font-medium text-emerald-600 hover:underline">Save</button>
                  <button onClick={() => setEditParam(null)} className="shrink-0 text-xs text-slate-400 hover:underline">×</button>
                </span>
              ) : (
                <span className="min-w-0 flex-1 truncate text-sm text-slate-700 dark:text-slate-200" title={p.name}>
                  {p.name} <span className="text-[10px] uppercase text-slate-400">{p.unit}</span>
                  <button
                    onClick={() => setEditParam({ id: p.id, name: p.name, unit: p.unit })}
                    title="Edit this parameter (name / kind) for the whole company"
                    className="ml-1 align-middle text-slate-300 hover:text-emerald-600"
                  >
                    <Pencil className="inline size-3" />
                  </button>
                </span>
              )}
              {row && (
                <>
                  <Input type="number" min="1" max="100" value={row.weightage} onChange={(e) => setRow(p.id, 'weightage', e.target.value)} className="w-14 px-1.5 py-1 text-xs" title="Weightage" />
                  <Input type="number" min="0" value={row.daily_target} onChange={(e) => setRow(p.id, 'daily_target', e.target.value)} className="w-20 px-1.5 py-1 text-xs" title="Daily target" />
                </>
              )}
            </div>
          )
        })}
      </div>

      <div className="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
        <div className="min-w-0 flex-1 sm:max-w-[240px]">
          <Label>New parameter</Label>
          <Input value={newName} onChange={(e) => setNewName(e.target.value)} placeholder="Demo scheduled" className="w-full" />
        </div>
        <Select value={newUnit} onChange={(e) => setNewUnit(e.target.value)}>
          <option value="count">Count</option>
          <option value="percent">Percent</option>
          <option value="currency">Currency</option>
          <option value="boolean">Yes/No</option>
        </Select>
        <Button size="sm" variant="secondary" disabled={!newName.trim() || addParamMutation.isPending} onClick={() => addParamMutation.mutate()}>
          Add to catalog
        </Button>
      </div>
    </Card>
  )
}

/**
 * One-click HR letters generated from the profile itself. Availability
 * follows the data: no resignation date → no resignation letter; full &
 * final needs the person to have actually left.
 */
function LettersCard({ existing }: { existing: CrmEmployeeFull }) {
  const { data: me } = useQuery({ queryKey: ['crm', 'me'], queryFn: crm.me })
  const orgName = me?.organization?.name ?? 'The Company'
  const availability = letterAvailability(existing)
  const [showFnf, setShowFnf] = useState(false)
  const [fnfAmount, setFnfAmount] = useState('')

  const generate = (type: LetterType) => {
    if (type === 'fnf') {
      setFnfAmount('')
      setShowFnf(true)
      return
    }
    openLetter(type, existing, orgName)
  }

  return (
    <Card>
      <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">HR letters</h2>
      <p className="mt-0.5 text-xs text-slate-400">
        Generated from this profile's data — opens print-ready, save as PDF from the print dialog.
      </p>
      {showFnf && (
        <Modal title="Full & final settlement" onClose={() => setShowFnf(false)}>
          <div className="space-y-3">
            <p className="text-sm text-slate-500">
              Last working day <span className="font-medium">{existing.resigned_at}</span>
              {existing.bank_account_no && <> · payout to the account ending <span className="font-medium">{existing.bank_account_no.slice(-4)}</span></>}
            </p>
            <div>
              <Label>Settlement amount (₹)</Label>
              <Input
                type="number"
                min="0"
                step="0.01"
                value={fnfAmount}
                onChange={(e) => setFnfAmount(e.target.value)}
                placeholder="Salary dues + leave encashment − deductions"
                className="w-full"
                autoFocus
              />
              <p className="mt-1 text-xs text-slate-400">The amount is printed on the letter as the full and final figure.</p>
            </div>
            <Button
              className="w-full"
              disabled={fnfAmount === '' || Number(fnfAmount) < 0}
              onClick={() => {
                openLetter('fnf', existing, orgName, Number(fnfAmount))
                setShowFnf(false)
              }}
            >
              Generate letter
            </Button>
          </div>
        </Modal>
      )}
      <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {(Object.keys(LETTER_LABELS) as LetterType[]).map((type) => {
          const a = availability[type]
          return (
            <button
              key={type}
              disabled={!a.enabled}
              onClick={() => generate(type)}
              title={a.why}
              className={clsx(
                'flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-left text-sm ring-1 ring-inset transition-colors',
                a.enabled
                  ? 'bg-white font-medium text-slate-700 ring-slate-200 hover:bg-emerald-50 hover:ring-emerald-300 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-emerald-500/10'
                  : 'cursor-not-allowed bg-slate-50 text-slate-400 ring-slate-100 dark:bg-slate-800/40 dark:ring-slate-800',
              )}
            >
              <FileText className={clsx('size-4 shrink-0', a.enabled ? 'text-emerald-500' : 'text-slate-300')} />
              <span className="min-w-0 flex-1">
                {LETTER_LABELS[type]}
                {!a.enabled && a.why && <span className="block truncate text-[11px] font-normal">{a.why}</span>}
              </span>
              {a.enabled && <Download className="size-3.5 shrink-0 text-slate-400" />}
            </button>
          )
        })}
      </div>

      {/* Every promotion on record keeps its letter: a revision that named a
          designation is a promotion, and each reprints as it stood. */}
      {existing.salary_records.some((r) => r.designation) && (
        <div className="mt-4 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/40">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Promotion letter history</div>
          <ul className="mt-1 divide-y divide-slate-200/70 dark:divide-slate-700/60">
            {existing.salary_records.filter((r) => r.designation).map((r) => (
              <li key={r.id} className="flex items-center justify-between gap-3 py-1.5 text-sm">
                <span className="min-w-0 truncate text-slate-600 dark:text-slate-300">
                  → <span className="font-medium">{r.designation}</span>
                  <span className="ml-2 text-xs text-slate-400">
                    effective {r.effective_from}
                    {r.created_at && <> · changed on {r.created_at}</>}
                    {' '}· ₹{Number(r.amount).toLocaleString('en-IN')}/month
                  </span>
                </span>
                <button
                  onClick={() => openLetter('promotion', existing, orgName, undefined, r.id)}
                  className="flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-xs text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-500/10"
                >
                  <FileText className="size-3.5" /> Letter
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </Card>
  )
}

/** The office kit in this person's hands, read from the assets register. */
function MemberAssetsCard({ memberUuid, manages }: { memberUuid: string; manages: boolean }) {
  const { data } = useQuery({
    queryKey: ['crm', 'member-assets', memberUuid],
    queryFn: () => crm.assets.forMember(memberUuid),
  })

  return (
    <Card>
      <div className="flex items-center justify-between gap-2">
        <div>
          <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Office assets held</h2>
          <p className="mt-0.5 text-xs text-slate-400">
            Laptop, mobile, SIM, chargers… whatever the company handed over.
            {manages && ' Allocate and take back from the Office Assets menu.'}
          </p>
        </div>
        {manages && (
          <a href="/crm/assets" className="text-xs font-medium text-emerald-600 hover:underline">Open Office Assets</a>
        )}
      </div>
      {(data ?? []).length === 0 ? (
        <p className="mt-3 text-sm text-slate-400">Nothing allocated.</p>
      ) : (
        <ul className="mt-3 divide-y divide-slate-100 text-sm dark:divide-slate-800">
          {data!.map((a) => (
            <li key={a.uuid} className="flex items-baseline justify-between gap-3 py-2">
              <span className="min-w-0 truncate text-slate-700 dark:text-slate-200">
                <span className="font-medium">{a.name}</span>
                <span className="ml-2 text-xs text-slate-400">
                  {[a.category, a.model_no, a.serial_no].filter(Boolean).join(' · ')}
                </span>
              </span>
              <span className="shrink-0 text-xs text-slate-400">since {a.allocated_at?.slice(0, 10) ?? '—'}</span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function SalaryCard({ uuid, existing }: { uuid: string; existing: CrmEmployeeFull }) {
  const queryClient = useQueryClient()
  const { toastError } = useToast()
  const [show, setShow] = useState(false)
  const [amount, setAmount] = useState('')
  const [from, setFrom] = useState('')
  const [designation, setDesignation] = useState('')
  const [note, setNote] = useState('')

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'employee', uuid] })

  const addMutation = useMutation({
    mutationFn: () => crm.employees.addSalary(uuid, {
      amount: Number(amount),
      effective_from: from,
      designation: designation || null,
      note: note || null,
    }),
    onSuccess: () => { refresh(); setShow(false); setAmount(''); setFrom(''); setDesignation(''); setNote('') },
    onError: (err) => toastError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => crm.employees.deleteSalary(uuid, id),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <Card>
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Salary history</h2>
          <p className="mt-0.5 text-xs text-slate-400">Every revision with the date it takes effect.</p>
        </div>
        <Button size="sm" variant="secondary" onClick={() => setShow(true)}>
          <Plus className="size-3.5" /> Revision
        </Button>
      </div>
      {existing.salary_records.length === 0 ? (
        <p className="mt-3 text-sm text-slate-400">No salary recorded yet.</p>
      ) : (
        <table className="mt-3 w-full text-sm">
          <tbody>
            {existing.salary_records.map((s) => (
              <tr key={s.id} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                <td className="py-2 pr-3 font-medium">₹{Number(s.amount).toLocaleString('en-IN')}</td>
                <td className="py-2 pr-3 text-slate-500">from {s.effective_from}</td>
                <td className="py-2 pr-3">
                  {s.designation && (
                    <span className="mr-2 rounded-full bg-purple-100 px-2 py-0.5 text-[11px] font-medium text-purple-700 dark:bg-purple-500/15 dark:text-purple-300">
                      → {s.designation}
                    </span>
                  )}
                  <span className="text-slate-400">{s.note}</span>
                </td>
                <td className="py-2 text-right">
                  <button onClick={() => deleteMutation.mutate(s.id)} aria-label="Remove" className="rounded p-1 text-slate-300 hover:text-red-500">
                    <Trash2 className="size-4" />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {show && (
        <Modal title="Salary revision" onClose={() => setShow(false)}>
          <div className="space-y-3">
            <div>
              <Label>Amount (₹/month)</Label>
              <Input type="number" min="0" value={amount} onChange={(e) => setAmount(e.target.value)} className="w-full" />
            </div>
            <div>
              <Label>Effective from</Label>
              <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-full" />
            </div>
            <div>
              <Label>New designation (only if the position changed)</Label>
              <Select value={designation} onChange={(e) => setDesignation(e.target.value)} className="w-full">
                <option value="">No change — {existing.designation ?? 'current position'}</option>
                {masters?.designations.map((d) => <option key={d} value={d}>{d}</option>)}
              </Select>
              <p className="mt-1 text-xs text-slate-400">Picking one promotes the employee and enables the promotion letter.</p>
            </div>
            <div>
              <Label>Note</Label>
              <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Appraisal, promotion…" className="w-full" />
            </div>
            <Button className="w-full" disabled={!amount || !from || addMutation.isPending} onClick={() => addMutation.mutate()}>
              Save revision
            </Button>
          </div>
        </Modal>
      )}
    </Card>
  )
}

function DocumentsCard({ uuid, existing }: { uuid: string; existing: CrmEmployeeFull }) {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [name, setName] = useState('')
  const [files, setFiles] = useState<File[]>([])

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'employee', uuid] })

  const uploadMutation = useMutation({
    mutationFn: async () => {
      // Several files at once: the typed name only applies to a single
      // file; multiple files keep their own names.
      let done = 0
      for (const f of files) {
        await crm.employees.uploadDocument(uuid, files.length === 1 ? name : '', f)
        done++
      }
      return done
    },
    onSuccess: (done) => {
      refresh()
      setName('')
      setFiles([])
      toast(done + ' document' + (done === 1 ? '' : 's') + ' uploaded.', 'success')
    },
    onError: (err) => { refresh(); toastError(errorMessage(err)) },
  })

  const deleteMutation = useMutation({
    mutationFn: (docUuid: string) => crm.employees.deleteDocument(uuid, docUuid),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  const download = async (docUuid: string, docName: string) => {
    try {
      const blob = await crm.employees.downloadDocument(uuid, docUuid)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = docName
      a.click()
      URL.revokeObjectURL(url)
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  return (
    <Card>
      <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">Documents</h2>
      <p className="mt-0.5 text-xs text-slate-400">Offer letter, ID proofs, agreements — named files against this employee.</p>

      <div className="mt-3 flex flex-wrap items-end gap-2">
        <div className="min-w-0 flex-1 sm:max-w-[220px]">
          <Label>Document name (optional)</Label>
          <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Defaults to the file name" className="w-full" />
        </div>
        <div className="min-w-0 flex-1 sm:max-w-[280px]">
          <Label>Files (multiple allowed, 10 MB each)</Label>
          <input
            type="file"
            multiple
            onChange={(e) => setFiles(Array.from(e.target.files ?? []))}
            className="block w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-xs file:font-medium file:text-slate-700 dark:file:bg-slate-800 dark:file:text-slate-200"
          />
        </div>
        <Button size="sm" disabled={files.length === 0 || uploadMutation.isPending} onClick={() => uploadMutation.mutate()}>
          {uploadMutation.isPending ? 'Uploading…' : files.length > 1 ? `Upload ${files.length} files` : 'Upload'}
        </Button>
      </div>
      {files.length > 0 && (
        <p className="mt-1.5 text-xs text-slate-400">
          Selected: {files.map((f) => f.name).join(', ')}
        </p>
      )}

      {existing.documents.length > 0 && (
        <ul className="mt-4 space-y-1.5">
          {existing.documents.map((d) => (
            <li key={d.uuid} className="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60">
              <FileText className="size-4 shrink-0 text-slate-400" />
              <span className="min-w-0 flex-1 truncate font-medium text-slate-700 dark:text-slate-200">{d.name}</span>
              <span className="hidden text-xs text-slate-400 sm:block">{(d.size / 1024).toFixed(0)} KB</span>
              <button onClick={() => download(d.uuid, d.name)} aria-label="Download" className="rounded p-1 text-slate-400 hover:text-emerald-600">
                <Download className="size-4" />
              </button>
              <button onClick={() => deleteMutation.mutate(d.uuid)} aria-label="Delete" className="rounded p-1 text-slate-400 hover:text-red-500">
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
