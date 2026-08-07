import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowDownCircle, ArrowUpCircle, Bell, Briefcase, Download, Pencil, Plus, Search, Share2, Trash2,
} from 'lucide-react'
import { format } from 'date-fns'
import { clsx } from 'clsx'
import { projects as projectsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import UserSuggest from '../components/UserSuggest'
import { useAuthStore } from '../stores/auth'
import type { ProjectItem, ProjectEntryItem, ProjectSummaryRow } from '../types'
import {
  Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner, Textarea,
} from '../components/ui'

const CURRENCIES = ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD', 'JPY', 'CNY']
const PURPOSES = ['construction', 'business', 'personal', 'trading', 'rental', 'general']

const emptyEntryForm = {
  entry_date: format(new Date(), 'yyyy-MM-dd'),
  description: '',
  direction: 'debit',
  amount: '',
  currency: 'INR',
  mode: 'cash',
  bank_account: '',
  counterparty: '',
  reminder_at: '',
}

export default function ProjectsPage() {
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState<ProjectItem | null>(null)
  const [showProjectForm, setShowProjectForm] = useState(false)
  const [editProject, setEditProject] = useState<ProjectItem | null>(null)

  const { data: projects, isLoading } = useQuery({ queryKey: ['projects'], queryFn: projectsApi.list })

  // Keep the selected project fresh after edits.
  useEffect(() => {
    if (selected && projects) {
      const fresh = projects.find((p) => p.uuid === selected.uuid)
      if (fresh && fresh !== selected) setSelected(fresh)
      if (!fresh) setSelected(null)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projects])

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
          <Briefcase className="size-5 text-brand-600" /> Projects
        </h1>
        <Button size="sm" onClick={() => setShowProjectForm(true)}>
          <Plus className="size-3.5" /> New project
        </Button>
      </div>

      {isLoading ? (
        <Spinner />
      ) : !projects?.length ? (
        <Card>
          <EmptyState
            title="No projects yet"
            hint="Create a project ledger — construction expenses, business accounts, personal money tracking — and record every credit and debit."
          />
        </Card>
      ) : (
        <div className="flex flex-wrap gap-2">
          {projects.map((p) => (
            <button
              key={p.uuid}
              onClick={() => setSelected(p)}
              className={clsx(
                'rounded-lg border px-3 py-2 text-left text-sm transition-colors',
                selected?.uuid === p.uuid
                  ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300'
                  : 'border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800',
                p.is_archived && 'opacity-50',
              )}
            >
              <p className="font-medium">
                {p.name}
                {!p.is_owner && (
                  <span className="ml-1.5 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                    {p.permission === 'edit' ? 'can edit' : 'view only'}
                  </span>
                )}
              </p>
              <p className="text-[11px] capitalize text-slate-400">
                {p.purpose} · {p.base_currency}
                {p.entries_count != null && ` · ${p.entries_count} entries`}
                {!p.is_owner && p.owner && ` · shared by ${p.owner.name}`}
                {p.is_owner && p.shared_with.length > 0 && ` · shared with ${p.shared_with.length}`}
                {p.is_archived && ' · archived'}
              </p>
            </button>
          ))}
        </div>
      )}

      {selected && (
        <ProjectLedger
          key={selected.uuid}
          project={selected}
          onEdit={() => setEditProject(selected)}
        />
      )}

      {(showProjectForm || editProject) && (
        <ProjectFormModal
          project={editProject}
          onClose={() => {
            setShowProjectForm(false)
            setEditProject(null)
          }}
          onSaved={(p) => {
            queryClient.invalidateQueries({ queryKey: ['projects'] })
            if (p) setSelected(p)
          }}
        />
      )}
    </div>
  )
}

function ProjectFormModal({
  project,
  onClose,
  onSaved,
}: {
  project: ProjectItem | null
  onClose: () => void
  onSaved: (p: ProjectItem | null) => void
}) {
  const [form, setForm] = useState({
    name: project?.name ?? '',
    purpose: project?.purpose ?? 'construction',
    base_currency: project?.base_currency ?? 'INR',
    notes: project?.notes ?? '',
    is_archived: project?.is_archived ?? false,
    daily_report: project?.daily_report ?? false,
    report_format: project?.report_format ?? 'excel',
    password: '',
    remove_password: false,
  })
  const [error, setError] = useState<string | null>(null)
  const queryClient = useQueryClient()

  const save = useMutation({
    mutationFn: () => {
      const { password, remove_password, ...rest } = form
      const payload: Record<string, unknown> = { ...rest }
      if (remove_password) payload.password = null
      else if (password) payload.password = password
      return project ? projectsApi.update(project.uuid, payload) : projectsApi.create(payload)
    },
    onSuccess: (p) => {
      onSaved(p)
      onClose()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const remove = useMutation({
    mutationFn: () => projectsApi.remove(project!.uuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects'] })
      onSaved(null)
      onClose()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={project ? 'Edit project' : 'New project'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          setError(null)
          save.mutate()
        }}
      >
        <ErrorNote message={error} />
        <div>
          <Label>Project name</Label>
          <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Site A construction, Shop account…" required autoFocus />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>Purpose</Label>
            <Select value={form.purpose} onChange={(e) => setForm({ ...form, purpose: e.target.value })}>
              {PURPOSES.map((p) => (
                <option key={p} value={p} className="capitalize">{p}</option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Main currency</Label>
            <Select value={form.base_currency} onChange={(e) => setForm({ ...form, base_currency: e.target.value })}>
              {CURRENCIES.map((c) => <option key={c}>{c}</option>)}
            </Select>
          </div>
        </div>
        <div>
          <Label>Notes</Label>
          <Textarea rows={2} value={form.notes ?? ''} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </div>
        <div className="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
          <Label>{project?.has_password ? 'Change project password' : 'Project password (optional)'}</Label>
          <Input
            type="password"
            autoComplete="new-password"
            value={form.password}
            onChange={(e) => setForm({ ...form, password: e.target.value, remove_password: false })}
            placeholder={project?.has_password ? 'Leave blank to keep the current password' : 'Locks the ledger — min 4 characters'}
          />
          {project?.has_password && (
            <label className="mt-1.5 flex items-center gap-2 text-xs text-slate-500">
              <input
                type="checkbox"
                checked={form.remove_password}
                onChange={(e) => setForm({ ...form, remove_password: e.target.checked, password: '' })}
              />
              Remove the password (unlock for everyone with access)
            </label>
          )}
          <p className="mt-1 text-[11px] text-slate-400">
            With a password, everyone (including you) must enter it to open this ledger. Forgot it?
            Only an admin can email you a reset code.
          </p>
        </div>
        <div className="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.daily_report}
              onChange={(e) => setForm({ ...form, daily_report: e.target.checked })}
            />
            Email me a daily report
          </label>
          <p className="mt-0.5 pl-6 text-[11px] text-slate-400">
            Sent every morning at 6 AM — but only when the ledger changed the day before. Needs a verified email.
          </p>
          {form.daily_report && (
            <div className="mt-2 pl-6">
              <Label>Report format</Label>
              <Select
                className="w-40"
                value={form.report_format}
                onChange={(e) => setForm({ ...form, report_format: e.target.value as 'excel' | 'pdf' })}
              >
                <option value="excel">Excel (CSV)</option>
                <option value="pdf">PDF</option>
              </Select>
            </div>
          )}
        </div>
        {project && (
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={form.is_archived} onChange={(e) => setForm({ ...form, is_archived: e.target.checked })} />
            Archived (hidden from daily use, data kept)
          </label>
        )}
        <div className="flex justify-between gap-2">
          {project ? (
            <Button
              type="button"
              variant="danger"
              onClick={() => {
                if (confirm(`Delete “${project.name}” and ALL its entries? This cannot be undone.`)) remove.mutate()
              }}
            >
              <Trash2 className="size-3.5" /> Delete
            </Button>
          ) : <span />}
          <div className="flex gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save'}</Button>
          </div>
        </div>
      </form>
    </Modal>
  )
}

function ProjectLedger({ project, onEdit }: { project: ProjectItem; onEdit: () => void }) {
  const queryClient = useQueryClient()
  const canEdit = project.is_owner || project.permission === 'edit'
  const [showShare, setShowShare] = useState(false)
  const [pw, setPw] = useState<string | undefined>(undefined)
  const locked = !!project.has_password && pw === undefined
  const [page, setPage] = useState(1)
  const [filters, setFilters] = useState({ date_from: '', date_to: '', mode: '', direction: '', q: '' })
  const [search, setSearch] = useState('')
  const [showEntry, setShowEntry] = useState(false)
  const [editEntry, setEditEntry] = useState<ProjectEntryItem | null>(null)

  const params = {
    ...(filters.date_from ? { date_from: filters.date_from } : {}),
    ...(filters.date_to ? { date_to: filters.date_to } : {}),
    ...(filters.mode ? { mode: filters.mode } : {}),
    ...(filters.direction ? { direction: filters.direction } : {}),
    ...(filters.q ? { q: filters.q } : {}),
  }

  useEffect(() => setPage(1), [JSON.stringify(filters)]) // eslint-disable-line react-hooks/exhaustive-deps

  // Shared ledgers stay live: refresh every 10s so everyone sees updates.
  const { data: entries, isLoading } = useQuery({
    queryKey: ['project-entries', project.uuid, params, page],
    queryFn: () => projectsApi.entries(project.uuid, { ...params, page }, pw),
    refetchInterval: 10_000,
    enabled: !locked,
  })
  const { data: summary } = useQuery({
    queryKey: ['project-summary', project.uuid, params],
    queryFn: () => projectsApi.summary(project.uuid, params, pw),
    refetchInterval: 10_000,
    enabled: !locked,
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['project-entries', project.uuid] })
    queryClient.invalidateQueries({ queryKey: ['project-summary', project.uuid] })
    queryClient.invalidateQueries({ queryKey: ['projects'] })
  }

  const removeEntry = useMutation({
    mutationFn: (uuid: string) => projectsApi.removeEntry(project.uuid, uuid, pw),
    onSuccess: invalidate,
    onError: (err) => alert(errorMessage(err)),
  })

  const exportCsv = async () => {
    const token = useAuthStore.getState().token
    const qs = new URLSearchParams(params as Record<string, string>).toString()
    const res = await fetch(projectsApi.exportUrl(project.uuid) + (qs ? `?${qs}` : ''), {
      headers: { Authorization: `Bearer ${token}`, ...(pw ? { 'X-Project-Password': pw } : {}) },
    })
    if (!res.ok) return alert('Export failed.')
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${project.name}-ledger.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  if (locked) {
    return <UnlockProjectCard project={project} onUnlocked={setPw} />
  }

  return (
    <div className="space-y-4">
      {/* Summary cards (respect the active filters) */}
      {!!summary?.length && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {summary.map((row: ProjectSummaryRow) => (
            <Card key={row.currency}>
              <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">{row.currency}</p>
                <p className="text-[11px] text-slate-400">{row.entries} entr{row.entries === 1 ? 'y' : 'ies'}</p>
              </div>
              <div className="mt-2 grid grid-cols-3 gap-2 text-center text-xs">
                <div>
                  <p className="font-semibold text-emerald-600">+{row.credit.toLocaleString()}</p>
                  <p className="text-slate-400">In (credit)</p>
                </div>
                <div>
                  <p className="font-semibold text-red-500">−{row.debit.toLocaleString()}</p>
                  <p className="text-slate-400">Out (debit)</p>
                </div>
                <div>
                  <p className={clsx('font-semibold', row.net >= 0 ? 'text-emerald-600' : 'text-red-500')}>
                    {row.net >= 0 ? '+' : ''}{row.net.toLocaleString()}
                  </p>
                  <p className="text-slate-400">Net</p>
                </div>
              </div>
              <p className="mt-1.5 text-center text-[11px] text-slate-400">
                Cash {row.cash >= 0 ? '+' : ''}{row.cash.toLocaleString()} · Bank {row.bank >= 0 ? '+' : ''}{row.bank.toLocaleString()}
              </p>
            </Card>
          ))}
        </div>
      )}

      {/* Filter bar */}
      <Card className="flex flex-wrap items-end gap-2 p-3">
        <div>
          <Label>From</Label>
          <Input type="date" className="w-36" value={filters.date_from} onChange={(e) => setFilters({ ...filters, date_from: e.target.value })} />
        </div>
        <div>
          <Label>To</Label>
          <Input type="date" className="w-36" value={filters.date_to} onChange={(e) => setFilters({ ...filters, date_to: e.target.value })} />
        </div>
        <div>
          <Label>Mode</Label>
          <Select className="w-28" value={filters.mode} onChange={(e) => setFilters({ ...filters, mode: e.target.value })}>
            <option value="">All</option>
            <option value="cash">Cash</option>
            <option value="bank">Bank</option>
          </Select>
        </div>
        <div>
          <Label>Type</Label>
          <Select className="w-32" value={filters.direction} onChange={(e) => setFilters({ ...filters, direction: e.target.value })}>
            <option value="">All</option>
            <option value="credit">Credit (in)</option>
            <option value="debit">Debit (out)</option>
          </Select>
        </div>
        <div className="flex gap-1">
          <div>
            <Label>Search</Label>
            <Input
              className="w-44"
              placeholder="description, party, account…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && setFilters({ ...filters, q: search })}
            />
          </div>
          <Button variant="secondary" size="sm" className="self-end" onClick={() => setFilters({ ...filters, q: search })}>
            <Search className="size-4" />
          </Button>
        </div>
        <div className="ml-auto flex gap-2 self-end">
          <Button variant="secondary" size="sm" onClick={exportCsv} title="Download the filtered entries as an Excel-compatible CSV">
            <Download className="size-3.5" /> Export
          </Button>
          {project.is_owner && (
            <Button variant="secondary" size="sm" onClick={() => setShowShare(true)} title="Share with connections (view or edit)">
              <Share2 className="size-3.5" /> Share
            </Button>
          )}
          {project.is_owner && (
            <Button variant="secondary" size="sm" onClick={onEdit}>
              <Pencil className="size-3.5" /> Project
            </Button>
          )}
          {canEdit && (
            <Button size="sm" onClick={() => setShowEntry(true)}>
              <Plus className="size-3.5" /> Add entry
            </Button>
          )}
        </div>
      </Card>

      {/* Entries */}
      {isLoading ? (
        <Spinner />
      ) : !entries?.data.length ? (
        <Card>
          <EmptyState title="No entries" hint="Add your first credit or debit — daily expenses, payments received, anything." />
        </Card>
      ) : (
        <Card className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-left text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-xs text-slate-500 dark:border-slate-800">
                  <th className="px-3 py-2 font-medium">Date</th>
                  <th className="px-3 py-2 font-medium">Description</th>
                  <th className="px-3 py-2 font-medium">Party</th>
                  <th className="px-3 py-2 font-medium">Mode</th>
                  <th className="px-3 py-2 text-right font-medium">Amount</th>
                  <th className="px-3 py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {entries.data.map((e) => (
                  <tr key={e.uuid} className="border-b border-slate-100 last:border-0 dark:border-slate-800/60">
                    <td className="whitespace-nowrap px-3 py-2 text-xs text-slate-500">{format(new Date(e.entry_date), 'd MMM yyyy')}</td>
                    <td className="px-3 py-2">
                      {e.description}
                      {e.reminder_at && <Bell className="ml-1 inline size-3 text-amber-500" />}
                      {(e.created_by || e.updated_by) && (
                        <span className="block text-[10px] text-slate-400">
                          {e.created_by && `by ${e.created_by}`}
                          {e.updated_by && `${e.created_by ? ' · ' : ''}edited by ${e.updated_by}`}
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2 text-slate-500">{e.counterparty ?? '—'}</td>
                    <td className="px-3 py-2 text-xs capitalize text-slate-500">
                      {e.mode}
                      {e.bank_account && <span className="block text-[10px] text-slate-400">{e.bank_account}</span>}
                    </td>
                    <td className={clsx('whitespace-nowrap px-3 py-2 text-right font-semibold', e.direction === 'credit' ? 'text-emerald-600' : 'text-red-500')}>
                      {e.direction === 'credit'
                        ? <ArrowDownCircle className="mr-1 inline size-3.5" />
                        : <ArrowUpCircle className="mr-1 inline size-3.5" />}
                      {e.direction === 'credit' ? '+' : '−'}{Number(e.amount).toLocaleString()} {e.currency}
                    </td>
                    <td className="px-3 py-2">
                      <div className="flex justify-end gap-1">
                        {canEdit && (
                          <button className="rounded p-1 text-slate-400 hover:text-brand-600" title="Edit" onClick={() => setEditEntry(e)}>
                            <Pencil className="size-3.5" />
                          </button>
                        )}
                        {project.is_owner && (
                          <button
                            className="rounded p-1 text-slate-400 hover:text-red-600"
                            title="Delete (creator only)"
                            onClick={() => confirm('Delete this entry?') && removeEntry.mutate(e.uuid)}
                          >
                            <Trash2 className="size-3.5" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="px-3 pb-3">
            <Pager resp={entries} onPage={setPage} />
          </div>
        </Card>
      )}

      {showShare && (
        <ShareProjectModal
          project={project}
          onClose={() => setShowShare(false)}
          onChanged={() => queryClient.invalidateQueries({ queryKey: ['projects'] })}
        />
      )}

      {(showEntry || editEntry) && (
        <EntryFormModal
          project={project}
          pw={pw}
          entry={editEntry}
          onClose={() => {
            setShowEntry(false)
            setEditEntry(null)
          }}
          onSaved={invalidate}
        />
      )}
    </div>
  )
}

function EntryFormModal({
  project,
  pw,
  entry,
  onClose,
  onSaved,
}: {
  project: ProjectItem
  pw?: string
  entry: ProjectEntryItem | null
  onClose: () => void
  onSaved: () => void
}) {
  const [form, setForm] = useState(
    entry
      ? {
          entry_date: entry.entry_date,
          description: entry.description,
          direction: entry.direction,
          amount: entry.amount,
          currency: entry.currency,
          mode: entry.mode,
          bank_account: entry.bank_account ?? '',
          counterparty: entry.counterparty ?? '',
          reminder_at: entry.reminder_at ? entry.reminder_at.slice(0, 16) : '',
        }
      : { ...emptyEntryForm, currency: project.base_currency },
  )
  const [error, setError] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        ...form,
        amount: Number(form.amount),
        bank_account: form.mode === 'bank' ? form.bank_account || null : null,
        counterparty: form.counterparty || null,
        reminder_at: form.reminder_at || null,
      }
      return entry
        ? projectsApi.updateEntry(project.uuid, entry.uuid, payload, pw)
        : projectsApi.createEntry(project.uuid, payload, pw)
    },
    onSuccess: () => {
      onSaved()
      onClose()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal title={entry ? 'Edit entry' : `Add entry — ${project.name}`} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          setError(null)
          save.mutate()
        }}
      >
        <ErrorNote message={error} />
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>Date</Label>
            <Input type="date" value={form.entry_date} onChange={(e) => setForm({ ...form, entry_date: e.target.value })} required />
          </div>
          <div>
            <Label>Type</Label>
            <Select value={form.direction} onChange={(e) => setForm({ ...form, direction: e.target.value })}>
              <option value="debit">Debit — given / spent</option>
              <option value="credit">Credit — taken / received</option>
            </Select>
          </div>
        </div>
        <div>
          <Label>Description</Label>
          <Input
            value={form.description}
            onChange={(e) => setForm({ ...form, description: e.target.value })}
            placeholder="Cement 50 bags, labour payment, advance from client…"
            required
            autoFocus={!entry}
          />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>Amount</Label>
            <Input type="number" step="0.01" min="0.01" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} required />
          </div>
          <div>
            <Label>Currency</Label>
            <Select value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })}>
              {CURRENCIES.map((c) => <option key={c}>{c}</option>)}
            </Select>
          </div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>Mode</Label>
            <Select value={form.mode} onChange={(e) => setForm({ ...form, mode: e.target.value })}>
              <option value="cash">Cash</option>
              <option value="bank">Bank</option>
            </Select>
          </div>
          <div>
            <Label>Bank account</Label>
            <Input
              value={form.bank_account}
              onChange={(e) => setForm({ ...form, bank_account: e.target.value })}
              placeholder="HDFC ****1234"
              disabled={form.mode !== 'bank'}
            />
          </div>
        </div>
        <div>
          <Label>{form.direction === 'debit' ? 'Given to (party)' : 'Taken / received from (party)'}</Label>
          <Input value={form.counterparty} onChange={(e) => setForm({ ...form, counterparty: e.target.value })} placeholder="Contractor Ramesh, ABC Suppliers…" />
        </div>
        <div>
          <Label>Reminder (optional alarm)</Label>
          <Input type="datetime-local" value={form.reminder_at} onChange={(e) => setForm({ ...form, reminder_at: e.target.value })} />
          <p className="mt-1 text-[11px] text-slate-400">Rings an in-app / push / email alert at this time — e.g. to collect a pending payment.</p>
        </div>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={save.isPending}>{save.isPending ? 'Saving…' : entry ? 'Save changes' : 'Add entry'}</Button>
        </div>
      </form>
    </Modal>
  )
}

function ShareProjectModal({
  project,
  onClose,
  onChanged,
}: {
  project: ProjectItem
  onClose: () => void
  onChanged: () => void
}) {
  const [identifier, setIdentifier] = useState('')
  const [permission, setPermission] = useState<'view' | 'edit'>('view')
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const share = useMutation({
    mutationFn: () => projectsApi.share(project.uuid, identifier.trim(), permission),
    onSuccess: (res) => {
      setMessage(res.message)
      setIdentifier('')
      onChanged()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const unshare = useMutation({
    mutationFn: (userUuid: string) => projectsApi.unshare(project.uuid, userUuid),
    onSuccess: (res) => {
      setMessage(res.message)
      onChanged()
    },
    onError: (err) => alert(errorMessage(err)),
  })

  return (
    <Modal title={`Share “${project.name}”`} onClose={onClose}>
      <div className="space-y-4">
        <ErrorNote message={error} />
        {message && <p className="rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">{message}</p>}

        <div className="flex items-end gap-2">
          <div className="flex-1">
            <Label>Share with (username or email)</Label>
            <UserSuggest
              value={identifier}
              onChange={setIdentifier}
              placeholder="rahul or priya@mypa.local"
              autoFocus
            />
          </div>
          <div>
            <Label>Access</Label>
            <Select value={permission} onChange={(e) => setPermission(e.target.value as 'view' | 'edit')}>
              <option value="view">View only</option>
              <option value="edit">Can add & edit</option>
            </Select>
          </div>
          <Button onClick={() => { setError(null); setMessage(null); share.mutate() }} disabled={!identifier.trim() || share.isPending}>
            Share
          </Button>
        </div>
        <p className="text-[11px] text-slate-400">
          View: they see the live ledger, totals, and can export. Can add & edit: they can also add
          and change entries (every change shows their name) — but only you, the creator, can delete
          entries or the project.
        </p>

        {project.shared_with.length > 0 && (
          <div className="space-y-1.5">
            <Label>Currently shared with</Label>
            {project.shared_with.map((person) => (
              <div key={person.uuid} className="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2 text-sm dark:border-slate-800">
                <div>
                  <span className="font-medium">{person.name}</span>
                  {person.username && <span className="ml-1 text-xs text-slate-400">@{person.username}</span>}
                  <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    {person.permission === 'edit' ? 'can add & edit' : 'view only'}
                  </span>
                </div>
                <button
                  className="text-xs font-semibold text-red-500 hover:underline"
                  onClick={() => confirm(`Remove ${person.name}'s access?`) && unshare.mutate(person.uuid)}
                >
                  Remove
                </button>
              </div>
            ))}
            <p className="text-[11px] text-slate-400">To change someone's access level, share with them again with the new level.</p>
          </div>
        )}

        <div className="flex justify-end">
          <Button variant="secondary" onClick={onClose}>Done</Button>
        </div>
      </div>
    </Modal>
  )
}

function UnlockProjectCard({ project, onUnlocked }: { project: ProjectItem; onUnlocked: (pw: string) => void }) {
  const [value, setValue] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [showReset, setShowReset] = useState(false)
  const [code, setCode] = useState('')
  const [newPw, setNewPw] = useState('')
  const [busy, setBusy] = useState(false)

  const unlock = async (candidate: string) => {
    setBusy(true)
    setError(null)
    try {
      await projectsApi.summary(project.uuid, {}, candidate)
      onUnlocked(candidate)
    } catch {
      setError('Wrong password.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card className="mx-auto max-w-md text-center">
      <p className="text-sm font-semibold">🔒 {project.name} is password protected</p>
      <p className="mt-1 text-xs text-slate-400">Enter the project password to open the ledger.</p>
      <ErrorNote message={error} />
      {message && <p className="mt-2 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">{message}</p>}

      {!showReset ? (
        <>
          <div className="mx-auto mt-3 flex max-w-xs gap-2">
            <Input
              type="password"
              autoComplete="new-password"
              value={value}
              onChange={(e) => setValue(e.target.value)}
              placeholder="Project password"
              autoFocus
              onKeyDown={(e) => e.key === 'Enter' && value && unlock(value)}
            />
            <Button onClick={() => unlock(value)} disabled={!value || busy}>Open</Button>
          </div>
          {project.is_owner && (
            <div className="mt-3 flex justify-center gap-4 text-xs">
              <button
                className="text-brand-600 hover:underline"
                onClick={() => {
                  projectsApi.requestPasswordReset(project.uuid)
                    .then((r) => setMessage(r.message))
                    .catch((err) => setError(errorMessage(err)))
                }}
              >
                Forgot? Ask an admin for a reset code
              </button>
              <button className="text-slate-400 hover:underline" onClick={() => setShowReset(true)}>
                Have a reset code?
              </button>
            </div>
          )}
        </>
      ) : (
        <div className="mx-auto mt-3 max-w-xs space-y-2 text-left">
          <div>
            <Label>Reset code (from the admin email)</Label>
            <Input value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} placeholder="6-digit code" autoFocus />
          </div>
          <div>
            <Label>New project password</Label>
            <Input type="password" autoComplete="new-password" value={newPw} onChange={(e) => setNewPw(e.target.value)} placeholder="min 4 characters" />
          </div>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" size="sm" onClick={() => setShowReset(false)}>Back</Button>
            <Button
              size="sm"
              disabled={!code || newPw.length < 4 || busy}
              onClick={async () => {
                setBusy(true)
                setError(null)
                try {
                  await projectsApi.resetPassword(project.uuid, code, newPw)
                  onUnlocked(newPw)
                } catch (err) {
                  setError(errorMessage(err))
                } finally {
                  setBusy(false)
                }
              }}
            >
              Set new password
            </Button>
          </div>
        </div>
      )}
    </Card>
  )
}
