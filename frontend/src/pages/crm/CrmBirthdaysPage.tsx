import { useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Cake, Clock, Heart, Send } from 'lucide-react'
import { crm, type CrmMe } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Modal, Pager, Select, Spinner, Textarea } from '../../components/ui'
import { Avatar } from '../../lib/avatars'
import { BIRTHDAY_REPLY_BUILT_IN } from '../../lib/backgrounds'

/** Somebody's name in a template that says {name}. */
const fill = (template: string, name: string | null | undefined) => template.replaceAll('{name}', name ?? 'you')

const EMOJI = ['🎂', '🎉', '🎈', '🎁', '🥳', '✨', '❤️']

type Missed = Awaited<ReturnType<typeof crm.birthdays.recent>>['missed'][number]

/**
 * The Birthdays menu: every wish sent and every thank-you, with the date and
 * time of each - and a second chance for a birthday somebody missed.
 *
 * An Admin or Subadmin reads the whole company's; anybody else reads the
 * wishes they sent and the ones they received.
 */
export default function CrmBirthdaysPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  const manages = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [year, setYear] = useState('')
  const [direction, setDirection] = useState('')
  const [page, setPage] = useState(1)
  const [answering, setAnswering] = useState<string | null>(null)
  const [reply, setReply] = useState('')

  const { data: today } = useQuery({ queryKey: ['crm', 'birthdays-today'], queryFn: crm.birthdays.today })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'birthday-wishes', year, direction, page],
    queryFn: () => crm.birthdays.history({
      year: year || undefined,
      direction: direction || undefined,
      page,
    }),
  })

  const defaultReply = today?.default_reply ?? BIRTHDAY_REPLY_BUILT_IN

  // A wish that arrived after the day never floated across anybody's screen,
  // so the thank-you for it happens here.
  const thank = useMutation({
    mutationFn: ({ uuid, message }: { uuid: string; message: string }) => crm.birthdays.reply(uuid, message),
    onSuccess: (res) => {
      toast(res.message, 'success')
      setAnswering(null)
      queryClient.invalidateQueries({ queryKey: ['crm', 'birthday-wishes'] })
      queryClient.invalidateQueries({ queryKey: ['crm', 'birthdays-today'] })
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div>
        <h1 className="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-white">
          <Cake className="size-5 text-pink-500" /> Birthdays
        </h1>
        <p className="text-sm text-slate-500">
          {today?.celebrating.length
            ? `Celebrating today: ${today.celebrating.map((p) => p.name).join(', ')}`
            : 'Every wish and every thank-you, kept.'}
        </p>
      </div>

      <MissedBirthdays />

      <Card>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <h2 className="w-full text-sm font-semibold text-slate-800 dark:text-slate-100 sm:mr-auto sm:w-auto">
            {manages ? "The company's wishes" : 'Your wishes'}
          </h2>
          <Select value={direction} onChange={(e) => { setDirection(e.target.value); setPage(1) }} className="min-w-0 flex-1 sm:flex-none">
            <option value="">{manages ? 'Everyone' : 'Sent and received'}</option>
            <option value="sent">Sent by me</option>
            <option value="received">Received by me</option>
          </Select>
          <Select value={year} onChange={(e) => { setYear(e.target.value); setPage(1) }} className="min-w-0 flex-1 sm:flex-none">
            <option value="">Every year</option>
            {data?.years.map((y) => <option key={y} value={y}>{y}</option>)}
          </Select>
        </div>

        {isLoading ? (
          <div className="flex justify-center py-12"><Spinner /></div>
        ) : !data?.data.length ? (
          <EmptyState title="No wishes yet" hint="When somebody wishes a colleague a happy birthday, it is kept here." />
        ) : (
          <ul className="space-y-2">
            {data.data.map((w) => {
              const mineToAnswer = !w.reply && !!w.to?.uuid && w.to.uuid === me?.member?.uuid

              return (
                <li key={w.uuid} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                  <div className="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-0.5 text-xs">
                    <span className="min-w-0 break-words">
                      <span className="font-semibold text-slate-800 dark:text-slate-100">{w.from?.name}</span>
                      <span className="text-slate-500"> wished </span>
                      <span className="font-semibold text-slate-800 dark:text-slate-100">{w.to?.name}</span>
                      {w.belated && (
                        <span className="ml-1.5 inline-flex items-center gap-0.5 rounded-full bg-amber-100 px-1.5 py-px text-[10px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                          <Clock className="size-2.5" /> Belated
                        </span>
                      )}
                    </span>
                    <span className="text-slate-400">{w.sent_at?.slice(0, 16)}</span>
                  </div>
                  <p className="mt-1 whitespace-pre-wrap break-words text-sm text-slate-700 dark:text-slate-200">{w.message}</p>

                  {w.reply ? (
                    <div className="mt-2 rounded-lg bg-white p-2 dark:bg-slate-900">
                      <div className="flex flex-wrap items-baseline justify-between gap-2 text-xs">
                        <span className="flex items-center gap-1 font-semibold text-emerald-600 dark:text-emerald-400">
                          <Heart className="size-3 fill-current" /> {w.to?.name} replied
                        </span>
                        <span className="text-slate-400">{w.replied_at?.slice(0, 16)}</span>
                      </div>
                      <p className="mt-0.5 whitespace-pre-wrap break-words text-sm text-slate-600 dark:text-slate-300">{w.reply}</p>
                    </div>
                  ) : mineToAnswer ? (
                    answering === w.uuid ? (
                      <div className="mt-2 space-y-2">
                        <Textarea rows={2} value={reply} onChange={(e) => setReply(e.target.value)} className="w-full text-sm" />
                        <div className="flex justify-end gap-2">
                          <Button size="sm" variant="secondary" onClick={() => setAnswering(null)}>Cancel</Button>
                          <Button size="sm" disabled={thank.isPending || !reply.trim()} onClick={() => thank.mutate({ uuid: w.uuid, message: reply.trim() })}>
                            <Send className="size-3.5" /> Send
                          </Button>
                        </div>
                      </div>
                    ) : (
                      <div className="mt-2 flex flex-wrap gap-2">
                        <Button size="sm" disabled={thank.isPending} onClick={() => thank.mutate({ uuid: w.uuid, message: fill(defaultReply, w.from?.name) })}>
                          <Heart className="size-3.5" /> Say thanks
                        </Button>
                        <Button size="sm" variant="secondary" onClick={() => { setAnswering(w.uuid); setReply(fill(defaultReply, w.from?.name)) }}>
                          Write my own
                        </Button>
                      </div>
                    )
                  ) : (
                    <p className="mt-1 text-[11px] text-slate-400">Not answered yet.</p>
                  )}
                </li>
              )
            })}
          </ul>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>
    </div>
  )
}

/**
 * Missed a birthday? The last week's, with a belated wish ready to send.
 *
 * Nothing at all when nobody had a birthday this past week - an empty card
 * saying so would be one more thing on the page to read.
 */
function MissedBirthdays() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const { data } = useQuery({ queryKey: ['crm', 'birthdays-recent'], queryFn: crm.birthdays.recent })
  const [writing, setWriting] = useState<Missed | null>(null)
  const [draft, setDraft] = useState('')

  const wish = useMutation({
    mutationFn: ({ uuid, message }: { uuid: string; message: string }) => crm.birthdays.wish(uuid, message),
    onSuccess: (res) => {
      toast(res.message, 'success')
      setWriting(null)
      queryClient.invalidateQueries({ queryKey: ['crm', 'birthdays-recent'] })
      queryClient.invalidateQueries({ queryKey: ['crm', 'birthday-wishes'] })
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  if (!data?.missed.length) return null

  const open = (person: Missed) => {
    setWriting(person)
    setDraft(fill(data.default_belated, person.name))
  }

  const when = (person: Missed) => {
    const date = new Date(`${person.birthday_on}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })

    return `${date} · ${person.days_ago === 1 ? 'yesterday' : `${person.days_ago} days ago`}`
  }

  return (
    <Card>
      <h2 className="flex items-center gap-1.5 text-sm font-semibold text-slate-800 dark:text-slate-100">
        <Clock className="size-4 text-amber-500" /> Missed a birthday?
      </h2>
      <p className="mt-0.5 text-xs text-slate-400">
        Birthdays from the last {data.window_days} days. It is not too late to wish them.
      </p>

      <ul className="mt-2 divide-y divide-slate-100 dark:divide-slate-800">
        {data.missed.map((person) => (
          <li key={person.uuid} className="flex items-center gap-3 py-2.5">
            <Avatar name={person.name ?? ''} photoPath={person.photo_path} avatar={person.avatar} size={36} />
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{person.name}</p>
              <p className="truncate text-xs text-slate-400">{when(person)}</p>
            </div>
            {person.my_wish ? (
              <span className="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                ✓ Wished
              </span>
            ) : (
              <Button size="sm" className="shrink-0" onClick={() => open(person)}>
                <Cake className="size-3.5" /> Wish<span className="hidden min-[400px]:inline">&nbsp;belated</span>
              </Button>
            )}
          </li>
        ))}
      </ul>

      {writing && (
        <Modal title={`Belated wish for ${writing.name ?? 'them'}`} onClose={() => setWriting(null)}>
          <div className="space-y-3">
            <p className="text-xs text-slate-500">Their birthday was {when(writing)}. The wish is marked belated.</p>
            <Textarea rows={4} value={draft} onChange={(e) => setDraft(e.target.value)} className="w-full" aria-label="Your belated wish" />
            <div className="flex flex-wrap gap-1">
              {EMOJI.map((emoji) => (
                <button
                  key={emoji}
                  type="button"
                  onClick={() => setDraft((d) => `${d}${d.endsWith(' ') || !d ? '' : ' '}${emoji}`)}
                  className="tap rounded-lg px-2 py-1 text-xl hover:bg-slate-100 dark:hover:bg-slate-800"
                  aria-label={`Add ${emoji}`}
                >
                  {emoji}
                </button>
              ))}
            </div>
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button variant="secondary" onClick={() => setWriting(null)}>Cancel</Button>
              <Button disabled={wish.isPending || !draft.trim()} onClick={() => wish.mutate({ uuid: writing.uuid, message: draft.trim() })}>
                <Send className="size-4" /> Send belated wish
              </Button>
            </div>
          </div>
        </Modal>
      )}
    </Card>
  )
}
