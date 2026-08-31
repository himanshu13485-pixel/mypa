import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Check, CheckCircle2, Clock, Medal, Send, X } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmContestQuestion } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, Input, Spinner } from '../../components/ui'
import { phaseBadge } from './CrmContestsPage'

function useCountdown(target: string | undefined) {
  const [, tick] = useState(0)
  useEffect(() => {
    const id = setInterval(() => tick((t) => t + 1), 1000)
    return () => clearInterval(id)
  }, [])
  if (!target) return null
  const ms = new Date(target.replace(' ', 'T')).getTime() - Date.now()
  if (ms <= 0) return '0:00'
  const totalSeconds = Math.floor(ms / 1000)
  const h = Math.floor(totalSeconds / 3600)
  const m = Math.floor((totalSeconds % 3600) / 60)
  const s = totalSeconds % 60
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`
}

export default function CrmContestPlayPage() {
  const { uuid } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toastError } = useToast()
  const [textDrafts, setTextDrafts] = useState<Record<number, string>>({})

  const { data: contest, isLoading } = useQuery({
    queryKey: ['crm', 'contest', uuid],
    queryFn: () => crm.contests.get(uuid!),
    refetchInterval: 30_000, // the phase flips itself when the clock runs out
  })
  // The Admin (or a Super Admin in oversight) runs the quiz, never plays it.
  const { data: me } = useQuery({ queryKey: ['crm', 'me'], queryFn: crm.me })
  const spectator = me?.member?.crm_role === 'admin' || !!me?.member?.is_oversight

  const ended = contest?.phase === 'ended'
  const { data: results } = useQuery({
    queryKey: ['crm', 'contest-results', uuid, ended],
    queryFn: () => crm.contests.results(uuid!),
    enabled: !!contest && (ended || contest.manages),
  })

  const countdown = useCountdown(contest?.phase === 'live' ? contest.ends_at : undefined)

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'contest', uuid] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'contest-results', uuid] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'contests'] })
  }

  const answerMutation = useMutation({
    mutationFn: ({ questionId, option, text }: { questionId: number; option?: number; text?: string }) =>
      crm.contests.answer(uuid!, {
        question_id: questionId,
        answer_option: option ?? null,
        answer_text: text ?? null,
      }),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  const gradeMutation = useMutation({
    mutationFn: ({ answerId, isCorrect }: { answerId: number; isCorrect: boolean }) =>
      crm.contests.grade(uuid!, answerId, isCorrect),
    onSuccess: refresh,
    onError: (err) => toastError(errorMessage(err)),
  })

  if (isLoading || !contest) {
    return <div className="flex justify-center py-20"><Spinner /></div>
  }

  const live = contest.phase === 'live'
  const answered = contest.questions.filter((q) => q.my_answer).length

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <button onClick={() => navigate('/crm/contests')} aria-label="Back" className="rounded p-1.5 text-slate-400 hover:bg-slate-200/60 dark:hover:bg-slate-800">
            <ArrowLeft className="size-4" />
          </button>
          <div>
            <h1 className="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-white">
              {contest.title}
              <span className={phaseBadge(contest.phase)}>{contest.phase}</span>
            </h1>
            <p className="text-sm text-slate-500">
              {contest.starts_at.slice(0, 16)} → {contest.ends_at.slice(0, 16)}
              {live && <> · {answered}/{contest.questions.length} answered</>}
            </p>
          </div>
        </div>
        {live && countdown && (
          <div className="flex items-center gap-2 rounded-xl bg-emerald-50 px-4 py-2 font-mono text-lg font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
            <Clock className="size-5" /> {countdown}
          </div>
        )}
      </div>

      {contest.description && <p className="text-sm text-slate-500">{contest.description}</p>}
      {contest.phase === 'upcoming' && (
        <Card className="text-center text-sm text-slate-500">The contest hasn't started yet — questions unlock at {contest.starts_at.slice(0, 16)}.</Card>
      )}

      {live && spectator && (
        <Card className="text-center text-sm text-slate-500">
          You run this contest — employees play it. The leaderboard fills as they answer.
        </Card>
      )}
      {(live || ended || contest.manages) && contest.questions.map((q, idx) => (
        <QuestionCard
          key={q.id}
          index={idx}
          q={q}
          live={live && !spectator}
          textDraft={textDrafts[q.id] ?? ''}
          onTextDraft={(v) => setTextDrafts((d) => ({ ...d, [q.id]: v }))}
          onAnswer={(option, text) => answerMutation.mutate({ questionId: q.id, option, text })}
          pending={answerMutation.isPending}
        />
      ))}

      {results && (
        <Card>
          <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
            <Medal className="size-4 text-amber-400" /> Leaderboard
            <span className="ml-auto text-xs font-normal text-slate-400">{results.max_points} pts possible</span>
          </h2>
          {results.board.length === 0 ? (
            <p className="text-sm text-slate-400">Nobody has played yet.</p>
          ) : (
            <table className="w-full text-sm">
              <tbody>
                {results.board.map((row) => (
                  <tr key={row.member_uuid ?? row.rank} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="w-10 py-2 pr-2">
                      <span className={clsx(
                        'flex size-6 items-center justify-center rounded-full text-xs font-semibold',
                        row.rank === 1 ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400'
                          : row.rank === 2 ? 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300'
                            : row.rank === 3 ? 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400'
                              : 'text-slate-400',
                      )}>
                        {row.rank}
                      </span>
                    </td>
                    <td className="py-2 pr-2 font-medium text-slate-800 dark:text-slate-100">{row.name}</td>
                    <td className="py-2 pr-2 text-xs text-slate-400">{row.correct}/{row.answered} correct{row.pending > 0 && ` · ${row.pending} pending`}</td>
                    <td className="py-2 text-right font-semibold tabular-nums">{row.points} pts</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {contest.manages && results.pending.length > 0 && (
            <div className="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
              <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Answers awaiting grading</h3>
              {results.pending.map((p) => (
                <div key={p.id} className="mb-2 flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60">
                  <div className="min-w-0 flex-1">
                    <span className="font-medium">{p.name}</span>
                    <span className="text-slate-400"> · {p.question}</span>
                    <div className="text-slate-600 dark:text-slate-300">"{p.answer_text}"</div>
                  </div>
                  <Button size="sm" onClick={() => gradeMutation.mutate({ answerId: p.id, isCorrect: true })}>
                    <Check className="size-3.5" /> Correct
                  </Button>
                  <Button size="sm" variant="secondary" onClick={() => gradeMutation.mutate({ answerId: p.id, isCorrect: false })}>
                    <X className="size-3.5" /> Wrong
                  </Button>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}
    </div>
  )
}

function QuestionCard({ index, q, live, textDraft, onTextDraft, onAnswer, pending }: {
  index: number
  q: CrmContestQuestion
  live: boolean
  textDraft: string
  onTextDraft: (v: string) => void
  onAnswer: (option?: number, text?: string) => void
  pending: boolean
}) {
  const mine = q.my_answer
  const revealed = q.correct_option !== null || q.correct_text !== null

  return (
    <Card>
      <div className="mb-2 flex items-start justify-between gap-3">
        <p className="font-medium text-slate-800 dark:text-slate-100">
          <span className="mr-1.5 text-slate-400">{index + 1}.</span>{q.question}
        </p>
        <span className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">
          {q.points} pts
        </span>
      </div>

      {q.type === 'option' && q.options && (
        <div className="grid gap-1.5 sm:grid-cols-2">
          {q.options.map((opt, oi) => {
            const chosen = mine?.answer_option === oi
            const isRight = revealed && q.correct_option === oi
            return (
              <button
                key={oi}
                disabled={!live || !!mine || pending}
                onClick={() => onAnswer(oi)}
                className={clsx(
                  'flex items-center gap-2 rounded-xl px-3 py-2 text-left text-sm ring-1 ring-inset transition-colors',
                  isRight && 'bg-emerald-50 ring-emerald-300 dark:bg-emerald-500/10 dark:ring-emerald-500/40',
                  chosen && !isRight && revealed && 'bg-red-50 ring-red-300 dark:bg-red-500/10 dark:ring-red-500/40',
                  chosen && !revealed && 'bg-sky-50 ring-sky-300 dark:bg-sky-500/10 dark:ring-sky-500/40',
                  !chosen && !isRight && 'bg-white ring-slate-200 dark:bg-slate-800 dark:ring-slate-700',
                  live && !mine && 'cursor-pointer hover:bg-emerald-50 hover:ring-emerald-300 dark:hover:bg-emerald-500/10',
                )}
              >
                <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-500 dark:bg-slate-700 dark:text-slate-300">
                  {String.fromCharCode(65 + oi)}
                </span>
                <span className="flex-1">{opt}</span>
                {chosen && <CheckCircle2 className="size-4 shrink-0 text-sky-500" />}
                {isRight && <Check className="size-4 shrink-0 text-emerald-500" />}
              </button>
            )
          })}
        </div>
      )}

      {q.type === 'text' && (
        mine ? (
          <div className="rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60">
            <span className="text-slate-400">Your answer: </span>
            <span className="font-medium">{mine.answer_text}</span>
            {revealed && q.correct_text && <span className="ml-2 text-emerald-600">(answer: {q.correct_text})</span>}
          </div>
        ) : live ? (
          <div className="flex gap-2">
            <Input value={textDraft} onChange={(e) => onTextDraft(e.target.value)} placeholder="Type your answer…" className="flex-1" />
            <Button disabled={!textDraft.trim() || pending} onClick={() => onAnswer(undefined, textDraft)}>
              <Send className="size-4" /> Lock in
            </Button>
          </div>
        ) : (
          <p className="text-sm text-slate-400">Not answered.</p>
        )
      )}

      {mine && revealed && mine.is_correct !== null && (
        <p className={clsx('mt-2 text-xs font-medium', mine.is_correct ? 'text-emerald-600' : 'text-red-500')}>
          {mine.is_correct ? `Correct — ${mine.points_awarded} pts` : 'Wrong — 0 pts'}
        </p>
      )}
      {mine && !revealed && <p className="mt-2 text-xs text-slate-400">Locked in — results open when the contest ends.</p>}
    </Card>
  )
}
