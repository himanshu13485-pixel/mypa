import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Cake, Gift, Heart, Send, X } from 'lucide-react'
import { crm, type CrmMe } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Textarea } from '../../components/ui'
import { Avatar } from '../../lib/avatars'

const LATER_KEY = 'crm-birthday-wish-later'

/** Somebody's name in a template that says {name}. */
const fill = (template: string, name: string | null | undefined) => template.replaceAll('{name}', name ?? 'you')

/**
 * Wishing, and being wished, without leaving the CRM.
 *
 * Two screens from one feed. Everybody else is shown whose birthday it is,
 * with a message already written - the company's default, editable - and a
 * "Wish them now" button; the wish lands on the birthday person's screen with
 * the sender's name on it. The birthday person, meanwhile, sees every wish as
 * it arrives and can thank each person back, again with a default they can
 * change. Everything said is kept in the Birthdays menu.
 */
export function BirthdayWishes({ me }: { me: CrmMe | undefined }) {
  const enabled = !!me?.enabled && !!me?.member
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const { data } = useQuery({
    queryKey: ['crm', 'birthdays-today'],
    queryFn: crm.birthdays.today,
    enabled,
    refetchInterval: 60_000,
  })

  // "Later" is for this browser and this day - a birthday does not come back
  // tomorrow, and a dismissed popup should not either.
  const today = new Date().toISOString().slice(0, 10)
  const [later, setLater] = useState<string[]>(() => {
    try {
      const saved = JSON.parse(sessionStorage.getItem(LATER_KEY) ?? '{}') as { day?: string; uuids?: string[] }
      return saved.day === today ? saved.uuids ?? [] : []
    } catch { return [] }
  })

  const unwished = useMemo(
    () => (data?.celebrating ?? []).filter((p) => !p.my_wish && !later.includes(p.uuid)),
    [data, later],
  )
  const current = unwished[0]

  const [draft, setDraft] = useState('')
  // A fresh message for each person, in their name.
  useEffect(() => {
    if (current && data) setDraft(fill(data.default_wish, current.name))
  }, [current?.uuid, data?.default_wish]) // eslint-disable-line react-hooks/exhaustive-deps

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['crm', 'birthdays-today'] })
    queryClient.invalidateQueries({ queryKey: ['crm', 'birthday-wishes'] })
  }

  const wish = useMutation({
    mutationFn: ({ uuid, message }: { uuid: string; message: string }) => crm.birthdays.wish(uuid, message),
    onSuccess: (res) => { toast(res.message, 'success'); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const putOff = (uuid: string) => {
    const next = [...later, uuid]
    setLater(next)
    try { sessionStorage.setItem(LATER_KEY, JSON.stringify({ day: today, uuids: next })) } catch { /* fine */ }
  }

  return (
    <>
      {current && data && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
          <div className="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-slate-900">
            <div className="relative bg-gradient-to-r from-pink-500 via-fuchsia-500 to-amber-400 px-5 py-6 text-center text-white">
              <button
                onClick={() => putOff(current.uuid)}
                aria-label="Later"
                className="absolute right-3 top-3 rounded-full p-1.5 hover:bg-white/20"
              >
                <X className="size-4" />
              </button>
              <div className="mx-auto mb-2 w-fit rounded-full ring-4 ring-white/40">
                <Avatar name={current.name ?? ''} photoPath={current.photo_path} avatar={current.avatar} size={64} />
              </div>
              <p className="text-lg font-semibold">It's {current.name}'s birthday today! 🎂</p>
              <p className="text-xs text-white/85">Send a wish - it lands straight on their screen, from you.</p>
            </div>

            <div className="space-y-3 p-5">
              <Textarea
                rows={3}
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                className="w-full"
                aria-label="Your birthday message"
              />
              <div className="flex flex-wrap gap-1.5">
                {['🎂', '🎉', '🎈', '🎁', '🥳', '✨', '❤️'].map((emoji) => (
                  <button
                    key={emoji}
                    onClick={() => setDraft((d) => `${d}${d.endsWith(' ') || !d ? '' : ' '}${emoji}`)}
                    className="rounded-lg px-1.5 py-0.5 text-lg hover:bg-slate-100 dark:hover:bg-slate-800"
                  >
                    {emoji}
                  </button>
                ))}
              </div>
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs text-slate-400">
                  {unwished.length > 1 ? `${unwished.length - 1} more celebrating today` : ''}
                </span>
                <div className="flex gap-2">
                  <Button variant="secondary" onClick={() => putOff(current.uuid)}>Later</Button>
                  <Button
                    disabled={wish.isPending || !draft.trim()}
                    onClick={() => wish.mutate({ uuid: current.uuid, message: draft.trim() })}
                  >
                    <Send className="size-4" /> Wish {current.name?.split(' ')[0]} now
                  </Button>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {data?.is_my_birthday && data.received.length > 0 && (
        <ReceivedWishes wishes={data.received} defaultReply={data.default_reply} onChanged={refresh} />
      )}
    </>
  )
}

/**
 * The wishes that reached the birthday person, and a thank-you for each.
 *
 * A corner card rather than a popup: they may get thirty of these, and a
 * dialog per wish would be the day's worst present.
 */
function ReceivedWishes({ wishes, defaultReply, onChanged }: {
  wishes: import('../../api/crm').CrmBirthdayWish[]
  defaultReply: string
  onChanged: () => void
}) {
  const { toast, toastError } = useToast()
  const [open, setOpen] = useState(true)
  const [answering, setAnswering] = useState<string | null>(null)
  const [reply, setReply] = useState('')

  const thank = useMutation({
    mutationFn: ({ uuid, message }: { uuid: string; message: string }) => crm.birthdays.reply(uuid, message),
    onSuccess: (res) => { toast(res.message, 'success'); setAnswering(null); onChanged() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const waiting = wishes.filter((w) => !w.reply).length

  if (!open) {
    return (
      <button
        onClick={() => setOpen(true)}
        className="fixed bottom-4 right-4 z-40 flex items-center gap-2 rounded-full bg-gradient-to-r from-pink-500 to-amber-400 px-4 py-2 text-sm font-medium text-white shadow-lg"
      >
        <Gift className="size-4" /> {wishes.length} birthday wish{wishes.length === 1 ? '' : 'es'}
      </button>
    )
  }

  return (
    <div className="fixed bottom-4 right-4 z-40 w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
      <div className="flex items-center justify-between bg-gradient-to-r from-pink-500 via-fuchsia-500 to-amber-400 px-4 py-2.5 text-white">
        <span className="flex items-center gap-2 text-sm font-semibold">
          <Cake className="size-4" /> {wishes.length} wish{wishes.length === 1 ? '' : 'es'} for you
          {waiting > 0 && <span className="rounded-full bg-white/25 px-1.5 text-[11px]">{waiting} to thank</span>}
        </span>
        <button onClick={() => setOpen(false)} aria-label="Minimise" className="rounded-full p-1 hover:bg-white/20">
          <X className="size-4" />
        </button>
      </div>

      <ul className="max-h-80 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
        {wishes.map((w) => (
          <li key={w.uuid} className="px-4 py-3">
            <div className="flex items-baseline justify-between gap-2">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">{w.from?.name}</span>
              <span className="text-[11px] text-slate-400">{w.sent_at?.slice(11, 16)}</span>
            </div>
            <p className="mt-0.5 whitespace-pre-wrap text-sm text-slate-600 dark:text-slate-300">{w.message}</p>

            {w.reply ? (
              <p className="mt-1 flex items-start gap-1 text-xs text-emerald-600 dark:text-emerald-400">
                <Heart className="mt-0.5 size-3 shrink-0 fill-current" /> {w.reply}
              </p>
            ) : answering === w.uuid ? (
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
              <div className="mt-1.5 flex gap-2">
                {/* One tap for the default; the pencil for your own words. */}
                <Button size="sm" onClick={() => thank.mutate({ uuid: w.uuid, message: fill(defaultReply, w.from?.name) })} disabled={thank.isPending}>
                  <Heart className="size-3.5" /> Say thanks
                </Button>
                <Button size="sm" variant="secondary" onClick={() => { setAnswering(w.uuid); setReply(fill(defaultReply, w.from?.name)) }}>
                  Write my own
                </Button>
              </div>
            )}
          </li>
        ))}
      </ul>
    </div>
  )
}
