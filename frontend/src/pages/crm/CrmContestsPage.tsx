import { useState } from 'react'
import { Link, useOutletContext } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, Trophy } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmContestFull, type CrmContestRow, type CrmMe } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Pager, Select, Spinner, Textarea } from '../../components/ui'

export function phaseBadge(phase: string) {
  return clsx(
    'rounded-full px-2 py-0.5 text-[11px] font-medium',
    phase === 'live' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
    phase === 'upcoming' && 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
    phase === 'ended' && 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    phase === 'draft' && 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
  )
}

interface QuestionDraft {
  type: 'option' | 'text'
  question: string
  options: string[]
  correct_option: number
  correct_text: string
  points: string
}

const EMPTY_QUESTION: QuestionDraft = {
  type: 'option', question: '', options: ['', '', '', ''], correct_option: 0, correct_text: '', points: '10',
}

export default function CrmContestsPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  // Contests are set by the Admin/Subadmin alone; everyone plays.
  const canManage = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'contests', page],
    queryFn: () => crm.contests.list(page),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'contests'] })

  const replicateMutation = useMutation({
    mutationFn: (uuid: string) => crm.contests.replicate(uuid),
    onSuccess: (res) => { toast(res.message, 'success'); refresh(); setEditing(res.data.uuid); setShowForm(true) },
    onError: (err) => toastError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => crm.contests.remove(uuid),
    onSuccess: () => { refresh(); toast('Contest deleted.', 'success') },
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Contests</h1>
          <p className="text-sm text-slate-500">Timed quizzes — answers lock in once, results open when time is up.</p>
        </div>
        {canManage && (
          <Button onClick={() => { setEditing(null); setShowForm(true) }}>
            <Plus className="size-4" /> New contest
          </Button>
        )}
      </div>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="No contests yet" hint={canManage ? 'Create the first quiz for your team.' : 'Contests appear here when published.'} />
        ) : (
          <div className="space-y-2">
            {data.data.map((c: CrmContestRow) => (
              <div key={c.uuid} className="flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
                <Trophy className={clsx('size-5 shrink-0', c.phase === 'live' ? 'text-emerald-500' : 'text-slate-400')} />
                <div className="min-w-0 flex-1">
                  <Link to={`/crm/contests/${c.uuid}`} className="font-medium text-slate-800 hover:text-emerald-600 dark:text-slate-100">
                    {c.title}
                  </Link>
                  <div className="text-xs text-slate-400">
                    {c.starts_at.slice(0, 16)} → {c.ends_at.slice(0, 16)} · {c.questions} questions
                    {c.audience && <> · {c.audience}</>}
                    {c.phase === 'ended' && c.my_points !== null && <> · you scored {c.my_points} pts</>}
                    {c.phase === 'live' && <> · {c.my_answers}/{c.questions} answered</>}
                  </div>
                </div>
                <span className={phaseBadge(c.phase)}>{c.phase}</span>
                {c.phase === 'live' && c.my_answers < c.questions && (
                  <Link to={`/crm/contests/${c.uuid}`} className="rounded-xl bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">
                    Play now
                  </Link>
                )}
                {canManage && (
                  <>
                    {c.phase === 'draft' && (
                      <Button size="sm" variant="secondary" onClick={() => { setEditing(c.uuid); setShowForm(true) }}>Edit</Button>
                    )}
                    {/* A finished quiz reborn for the next batch: same
                        questions, fresh draft, dates re-chosen. */}
                    {c.phase === 'ended' && (
                      <Button size="sm" variant="secondary" onClick={() => replicateMutation.mutate(c.uuid)} disabled={replicateMutation.isPending}>
                        Replicate
                      </Button>
                    )}
                    <button
                      onClick={() => { if (confirm(`Delete "${c.title}" and all its answers?`)) deleteMutation.mutate(c.uuid) }}
                      aria-label="Delete"
                      className="rounded p-1.5 text-slate-400 hover:text-red-500"
                    >
                      <Trash2 className="size-4" />
                    </button>
                  </>
                )}
              </div>
            ))}
          </div>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>

      {showForm && (
        <ContestFormModal
          editingUuid={editing}
          onClose={() => setShowForm(false)}
          onDone={() => { setShowForm(false); refresh() }}
        />
      )}
    </div>
  )
}

function ContestFormModal({ editingUuid, onClose, onDone }: { editingUuid: string | null; onClose: () => void; onDone: () => void }) {
  const [error, setError] = useState<string | null>(null)
  const [head, setHead] = useState({ title: '', description: '', starts_at: '', ends_at: '', status: 'draft' })
  // Contest for: everyone by default, one department, or one person.
  const [audienceKind, setAudienceKind] = useState<'all' | 'department' | 'member'>('all')
  const [audienceDept, setAudienceDept] = useState('')
  const [audienceMember, setAudienceMember] = useState('')
  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const [questions, setQuestions] = useState<QuestionDraft[]>([{ ...EMPTY_QUESTION, options: ['', '', '', ''] }])
  const [loaded, setLoaded] = useState(!editingUuid)

  useQuery({
    queryKey: ['crm', 'contest-edit', editingUuid],
    queryFn: async () => {
      const c: CrmContestFull = await crm.contests.get(editingUuid!)
      setHead({
        title: c.title,
        description: c.description ?? '',
        starts_at: c.starts_at.slice(0, 16).replace(' ', 'T'),
        ends_at: c.ends_at.slice(0, 16).replace(' ', 'T'),
        status: c.status,
      })
      if (c.audience_member_uuid) {
        setAudienceKind('member'); setAudienceMember(c.audience_member_uuid)
      } else if (c.audience_department) {
        setAudienceKind('department'); setAudienceDept(c.audience_department)
      }
      setQuestions(c.questions.map((q) => ({
        type: q.type,
        question: q.question,
        // A saved question keeps its own option count (two at minimum).
        options: [...(q.options ?? []), '', ''].slice(0, Math.max(2, q.options?.length ?? 0)),
        correct_option: q.correct_option ?? 0,
        correct_text: q.correct_text ?? '',
        points: String(q.points),
      })))
      setLoaded(true)
      return c
    },
    enabled: !!editingUuid,
  })

  const setQ = (idx: number, patch: Partial<QuestionDraft>) =>
    setQuestions((qs) => qs.map((q, i) => (i === idx ? { ...q, ...patch } : q)))

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = {
        title: head.title,
        description: head.description || null,
        starts_at: head.starts_at.replace('T', ' '),
        ends_at: head.ends_at.replace('T', ' '),
        status: head.status,
        audience_department: audienceKind === 'department' ? audienceDept || null : null,
        audience_member_uuid: audienceKind === 'member' ? audienceMember || null : null,
        questions: questions
          .filter((q) => q.question.trim() !== '')
          .map((q) => ({
            type: q.type,
            question: q.question,
            options: q.type === 'option' ? q.options.filter((o) => o.trim() !== '') : null,
            correct_option: q.type === 'option' ? q.correct_option : null,
            correct_text: q.type === 'text' ? q.correct_text || null : null,
            points: Number(q.points) || 10,
          })),
      }
      return editingUuid ? crm.contests.update(editingUuid, payload) : crm.contests.create(payload)
    },
    onSuccess: onDone,
    onError: (err) => setError(errorMessage(err)),
  })

  if (!loaded) {
    return (
      <Modal title="Edit contest" onClose={onClose} wide>
        <div className="flex justify-center py-10"><Spinner /></div>
      </Modal>
    )
  }

  return (
    <Modal title={editingUuid ? 'Edit contest' : 'New contest'} onClose={onClose} wide>
      <div className="space-y-4">
        <ErrorNote message={error} />
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Label>Title</Label>
            <Input value={head.title} onChange={(e) => setHead((h) => ({ ...h, title: e.target.value }))} className="w-full" />
          </div>
          <div>
            <Label>Starts</Label>
            <Input type="datetime-local" value={head.starts_at} onChange={(e) => setHead((h) => ({ ...h, starts_at: e.target.value }))} className="w-full" />
          </div>
          <div>
            <Label>Ends</Label>
            <Input type="datetime-local" value={head.ends_at} onChange={(e) => setHead((h) => ({ ...h, ends_at: e.target.value }))} className="w-full" />
          </div>
          <div>
            <Label>Status</Label>
            <Select value={head.status} onChange={(e) => setHead((h) => ({ ...h, status: e.target.value }))} className="w-full">
              <option value="draft">Draft — hidden from the team</option>
              <option value="published">Published</option>
              <option value="closed">Closed</option>
            </Select>
          </div>
          <div>
            <Label>Contest for</Label>
            <Select value={audienceKind} onChange={(e) => setAudienceKind(e.target.value as typeof audienceKind)} className="w-full">
              <option value="all">Everyone (default)</option>
              <option value="department">One department</option>
              <option value="member">A particular employee</option>
            </Select>
          </div>
          {audienceKind === 'department' && (
            <div>
              <Label>Department</Label>
              <Select value={audienceDept} onChange={(e) => setAudienceDept(e.target.value)} className="w-full">
                <option value="">Select…</option>
                {(masters?.departments ?? []).map((d) => <option key={d} value={d}>{d}</option>)}
              </Select>
            </div>
          )}
          {audienceKind === 'member' && (
            <div>
              <Label>Employee</Label>
              <Select value={audienceMember} onChange={(e) => setAudienceMember(e.target.value)} className="w-full">
                <option value="">Select…</option>
                {(masters?.members ?? []).filter((m) => (m.crm_role ?? 'employee') !== 'admin')
                  .map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
              </Select>
            </div>
          )}
          <div className="sm:col-span-2">
            <Label>Description</Label>
            <Textarea rows={2} value={head.description} onChange={(e) => setHead((h) => ({ ...h, description: e.target.value }))} className="w-full" />
          </div>
        </div>

        <div className="space-y-3">
          {questions.map((q, idx) => (
            <div key={idx} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
              <div className="mb-2 flex items-center gap-2">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">Question {idx + 1}</span>
                <Select value={q.type} onChange={(e) => setQ(idx, { type: e.target.value as 'option' | 'text' })} className="py-1 text-xs">
                  <option value="option">Multiple choice</option>
                  <option value="text">Free text</option>
                </Select>
                {q.type === 'option' && (
                  <Select
                    value={String(q.options.length)}
                    onChange={(e) => {
                      const count = Number(e.target.value)
                      setQ(idx, {
                        options: [...q.options, '', '', ''].slice(0, count),
                        correct_option: Math.min(q.correct_option, count - 1),
                      })
                    }}
                    className="py-1 text-xs"
                    aria-label="Number of options"
                  >
                    <option value="2">2 options</option>
                    <option value="3">3 options</option>
                    <option value="4">4 options</option>
                  </Select>
                )}
                <Input type="number" min="1" value={q.points} onChange={(e) => setQ(idx, { points: e.target.value })} className="w-20 py-1 text-xs" aria-label="Points" />
                <span className="text-xs text-slate-400">pts</span>
                <button
                  onClick={() => setQuestions((qs) => (qs.length > 1 ? qs.filter((_, i) => i !== idx) : qs))}
                  aria-label="Remove question"
                  className="ml-auto rounded p-1 text-slate-300 hover:text-red-500"
                >
                  <Trash2 className="size-4" />
                </button>
              </div>
              <Textarea rows={2} value={q.question} onChange={(e) => setQ(idx, { question: e.target.value })} placeholder="The question…" className="mb-2 w-full" />
              {q.type === 'option' ? (
                <div className="grid gap-1.5 sm:grid-cols-2">
                  {q.options.map((opt, oi) => (
                    <label key={oi} className="flex items-center gap-2">
                      <input
                        type="radio"
                        name={`correct-${idx}`}
                        checked={q.correct_option === oi}
                        onChange={() => setQ(idx, { correct_option: oi })}
                        className="size-4 shrink-0 accent-emerald-600"
                        title="Mark as the correct answer"
                      />
                      <Input
                        value={opt}
                        onChange={(e) => setQ(idx, { options: q.options.map((o, i) => (i === oi ? e.target.value : o)) })}
                        placeholder={`Option ${oi + 1}`}
                        className="w-full py-1.5 text-sm"
                      />
                    </label>
                  ))}
                </div>
              ) : (
                <div>
                  <Label>Model answer (optional — auto-grades if filled)</Label>
                  <Input value={q.correct_text} onChange={(e) => setQ(idx, { correct_text: e.target.value })} className="w-full" />
                </div>
              )}
            </div>
          ))}
          <Button variant="secondary" size="sm" onClick={() => setQuestions((qs) => [...qs, { ...EMPTY_QUESTION, options: ['', '', '', ''] }])}>
            <Plus className="size-3.5" /> Add question
          </Button>
        </div>

        <Button
          className="w-full"
          disabled={!head.title || !head.starts_at || !head.ends_at || saveMutation.isPending}
          onClick={() => saveMutation.mutate()}
        >
          {saveMutation.isPending ? 'Saving…' : editingUuid ? 'Save contest' : 'Create contest'}
        </Button>
      </div>
    </Modal>
  )
}
