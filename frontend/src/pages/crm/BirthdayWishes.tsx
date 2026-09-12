import { useEffect, useMemo, useState } from 'react'
import type { CSSProperties } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Cake, Gift, Heart, Send, Sparkles, X } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmBirthdayWish, type CrmMe } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Textarea } from '../../components/ui'
import { Avatar } from '../../lib/avatars'

const LATER_KEY = 'crm-birthday-wish-later'

/** Somebody's name in a template that says {name}. */
const fill = (template: string, name: string | null | undefined) => template.replaceAll('{name}', name ?? 'you')

const prefersReducedMotion = () =>
  typeof window !== 'undefined' && !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

/**
 * Wishing, and being wished, without leaving the CRM.
 *
 * Two screens from one feed. Everybody else is shown whose birthday it is,
 * with a message already written - the company's default, editable - and a
 * "Wish them now" button. The birthday person watches the wishes float up
 * their screen as they arrive, each with the sender's name, and can tap any
 * of them to say thank you. Everything said is kept in the Birthdays menu.
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

  /*
   * The birthday person's panel starts folded away: the floating wishes are
   * the show, and tapping one opens it. With reduced motion there is no show,
   * so the panel starts open instead of hiding behind a pill.
   */
  const [panelOpen, setPanelOpen] = useState(prefersReducedMotion)
  const [focus, setFocus] = useState<string | null>(null)
  const [round, setRound] = useState(0)

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
        /*
         * A bottom sheet on a phone - the thumb reaches the buttons, and the
         * keyboard opening for the message does not push them off the top -
         * and a centred card from sm up.
         */
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 sm:items-center sm:p-4">
          <div className="pb-safe max-h-[92dvh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white shadow-xl dark:bg-slate-900 sm:rounded-2xl">
            <div className="relative bg-gradient-to-r from-pink-500 via-fuchsia-500 to-amber-400 px-5 py-5 text-center text-white sm:py-6">
              <button
                onClick={() => putOff(current.uuid)}
                aria-label="Later"
                className="absolute right-3 top-3 rounded-full p-1.5 hover:bg-white/20"
              >
                <X className="size-4" />
              </button>
              <div className="mx-auto mb-2 w-fit rounded-full ring-4 ring-white/40">
                <Avatar name={current.name ?? ''} photoPath={current.photo_path} avatar={current.avatar} size={56} />
              </div>
              <p className="px-6 text-base font-semibold sm:text-lg">It's {current.name}'s birthday today! 🎂</p>
              <p className="text-xs text-white/85">Send a wish - it lands straight on their screen, from you.</p>
            </div>

            <div className="space-y-3 p-4 sm:p-5">
              <Textarea
                rows={3}
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                className="w-full"
                aria-label="Your birthday message"
              />
              <div className="flex flex-wrap gap-1">
                {['🎂', '🎉', '🎈', '🎁', '🥳', '✨', '❤️'].map((emoji) => (
                  <button
                    key={emoji}
                    onClick={() => setDraft((d) => `${d}${d.endsWith(' ') || !d ? '' : ' '}${emoji}`)}
                    className="tap rounded-lg px-2 py-1 text-xl hover:bg-slate-100 dark:hover:bg-slate-800"
                    aria-label={`Add ${emoji}`}
                  >
                    {emoji}
                  </button>
                ))}
              </div>
              {/* Stacked on a phone, where "Wish Priyanshu now" beside "Later"
                  and a count would not fit on one line of 375 pixels. */}
              <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                {unwished.length > 1 && (
                  <span className="text-center text-xs text-slate-400 sm:text-left">
                    {unwished.length - 1} more celebrating today
                  </span>
                )}
                <div className="flex gap-2 sm:ml-auto">
                  <Button variant="secondary" className="flex-1 sm:flex-none" onClick={() => putOff(current.uuid)}>
                    Later
                  </Button>
                  <Button
                    className="min-w-0 flex-[2] sm:flex-none"
                    disabled={wish.isPending || !draft.trim()}
                    onClick={() => wish.mutate({ uuid: current.uuid, message: draft.trim() })}
                  >
                    <Send className="size-4 shrink-0" />
                    <span className="truncate">Wish {current.name?.split(' ')[0]} now</span>
                  </Button>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {data?.is_my_birthday && data.received.length > 0 && (
        <>
          <FloatingWishes
            wishes={data.received}
            round={round}
            onOpen={(uuid) => { setFocus(uuid); setPanelOpen(true) }}
          />
          <ReceivedWishes
            wishes={data.received}
            defaultReply={data.default_reply}
            open={panelOpen}
            focus={focus}
            onOpenChange={setPanelOpen}
            onReplay={() => setRound((r) => r + 1)}
            onChanged={refresh}
          />
        </>
      )}
    </>
  )
}

/** A stable small number from a uuid, so a card keeps its lane and timing. */
const seedOf = (uuid: string) => [...uuid].reduce((sum, ch) => sum + ch.charCodeAt(0), 0)

/** Where a card may start, as a share of the free width - never off the edge. */
const LANES = [6, 58, 28, 84, 16, 46, 72, 36]

/**
 * The wishes, floating up the birthday person's screen.
 *
 * Each card is a sender's name and their words, rising from the bottom like a
 * balloon on its own lane and its own delay - taken from the wish's id, so a
 * card does not jump lanes when a new wish arrives and the list reorders.
 * Eight at most at once: thirty wishes floating together is not a
 * celebration, it is a screen that cannot be used.
 *
 * The layer itself lets every tap through; only the cards catch one, and a
 * card opens the panel at that wish. On a phone the layer starts below the
 * header and on a desktop beside the sidebar, so neither is floated over.
 */
export function FloatingWishes({ wishes, round, onOpen }: {
  wishes: CrmBirthdayWish[]
  round: number
  onOpen: (uuid: string) => void
}) {
  const shown = wishes.slice(0, 8)

  return (
    <div
      aria-hidden
      className="wish-float-layer top-below-header pointer-events-none fixed inset-x-0 bottom-0 z-[35] overflow-hidden md:left-60"
    >
      {shown.map((w) => {
        const seed = seedOf(w.uuid)
        const lane = LANES[seed % LANES.length]
        const style = {
          // Inside the edges whatever the screen: at 0% the card's left is the
          // layer's left, at 100% its right is the layer's right.
          left: `calc(${lane}% - ${lane / 100} * min(15rem, 72vw))`,
          animationDelay: `${(seed % 6) * 1.8}s`,
          '--wish-sway': seed % 2 === 0 ? 1 : -1,
        } as CSSProperties

        return (
          <button
            key={`${w.uuid}-${round}`}
            type="button"
            tabIndex={-1}
            onClick={() => onOpen(w.uuid)}
            style={style}
            className="wish-float pointer-events-auto absolute bottom-0 w-[min(15rem,72vw)] rounded-2xl bg-white/95 p-3 text-left shadow-lg ring-1 ring-pink-200 backdrop-blur dark:bg-slate-900/95 dark:ring-pink-500/30"
          >
            <span className="flex items-center gap-1.5 text-xs font-semibold text-pink-600 dark:text-pink-400">
              <span className="text-base leading-none">🎈</span>
              <span className="truncate">{w.from?.name}</span>
            </span>
            <span className="mt-1 line-clamp-3 block text-sm text-slate-700 dark:text-slate-200">{w.message}</span>
            <span className="mt-1.5 block text-[11px] font-medium text-slate-400">
              {w.reply ? '💝 You thanked them' : 'Tap to say thanks'}
            </span>
          </button>
        )
      })}
    </div>
  )
}

/**
 * The wishes that reached the birthday person, and a thank-you for each.
 *
 * A folded pill until opened - by the pill, or by tapping a floating wish,
 * which also scrolls the panel to it. A bottom sheet across the width of a
 * phone, a corner card on a desktop, and clear of the home indicator on both.
 */
function ReceivedWishes({ wishes, defaultReply, open, focus, onOpenChange, onReplay, onChanged }: {
  wishes: CrmBirthdayWish[]
  defaultReply: string
  open: boolean
  focus: string | null
  onOpenChange: (open: boolean) => void
  onReplay: () => void
  onChanged: () => void
}) {
  const { toast, toastError } = useToast()
  const [answering, setAnswering] = useState<string | null>(null)
  const [reply, setReply] = useState('')

  // Opened by a floating card: bring that wish into view.
  useEffect(() => {
    if (!open || !focus) return
    document.getElementById(`birthday-wish-${focus}`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
  }, [open, focus])

  const thank = useMutation({
    mutationFn: ({ uuid, message }: { uuid: string; message: string }) => crm.birthdays.reply(uuid, message),
    onSuccess: (res) => { toast(res.message, 'success'); setAnswering(null); onChanged() },
    onError: (err) => toastError(errorMessage(err)),
  })

  const waiting = wishes.filter((w) => !w.reply).length

  if (!open) {
    return (
      <button
        onClick={() => onOpenChange(true)}
        className="bottom-float-safe fixed right-2 z-40 flex items-center gap-2 rounded-full bg-gradient-to-r from-pink-500 to-amber-400 px-4 py-2.5 text-sm font-medium text-white shadow-lg sm:right-4"
      >
        <Gift className="size-4" />
        {wishes.length} wish{wishes.length === 1 ? '' : 'es'}
        {waiting > 0 && <span className="rounded-full bg-white/25 px-1.5 text-[11px]">{waiting} to thank</span>}
      </button>
    )
  }

  return (
    <div className="bottom-float-safe fixed inset-x-2 z-40 overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700 sm:inset-x-auto sm:right-4 sm:w-[22rem]">
      <div className="flex items-center justify-between gap-2 bg-gradient-to-r from-pink-500 via-fuchsia-500 to-amber-400 px-4 py-2.5 text-white">
        <span className="flex min-w-0 items-center gap-2 text-sm font-semibold">
          <Cake className="size-4 shrink-0" />
          <span className="truncate">{wishes.length} wish{wishes.length === 1 ? '' : 'es'} for you</span>
          {waiting > 0 && <span className="shrink-0 rounded-full bg-white/25 px-1.5 text-[11px]">{waiting} to thank</span>}
        </span>
        <span className="flex shrink-0 items-center gap-1">
          <button onClick={onReplay} title="Float them again" aria-label="Float the wishes again" className="rounded-full p-1.5 hover:bg-white/20">
            <Sparkles className="size-4" />
          </button>
          <button onClick={() => onOpenChange(false)} aria-label="Fold away" className="rounded-full p-1.5 hover:bg-white/20">
            <X className="size-4" />
          </button>
        </span>
      </div>

      <ul className="max-h-[45dvh] divide-y divide-slate-100 overflow-y-auto overscroll-contain dark:divide-slate-800 sm:max-h-80">
        {wishes.map((w) => (
          <li
            key={w.uuid}
            id={`birthday-wish-${w.uuid}`}
            className={clsx('px-4 py-3', focus === w.uuid && 'bg-pink-50/70 dark:bg-pink-500/10')}
          >
            <div className="flex items-baseline justify-between gap-2">
              <span className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{w.from?.name}</span>
              <span className="shrink-0 text-[11px] text-slate-400">{w.sent_at?.slice(11, 16)}</span>
            </div>
            <p className="mt-0.5 whitespace-pre-wrap break-words text-sm text-slate-600 dark:text-slate-300">{w.message}</p>

            {w.reply ? (
              <p className="mt-1 flex items-start gap-1 break-words text-xs text-emerald-600 dark:text-emerald-400">
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
              <div className="mt-1.5 flex flex-wrap gap-2">
                {/* One tap for the default; your own words for anything else. */}
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
