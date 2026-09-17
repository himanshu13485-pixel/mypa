import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { AlarmClock, BellOff, CheckSquare, Receipt, Volume2, VolumeX } from 'lucide-react'
import { clsx } from 'clsx'
import { dueAlerts, type DueAlert } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { playChime } from '../lib/alerts'
import { getDueAlertPrefs, setDueAlertPrefs, type DueAlertPrefs } from '../lib/dueAlertPrefs'
import { useToast } from './Toast'
import { Button, Modal } from './ui'

/** What "remind me again" offers. Minutes, because that is how people say it. */
const SNOOZE = [15, 30, 45, 60]

/**
 * The alarm for a task or a bill whose time has come.
 *
 * Both modules knew their due dates and said so only where somebody happened
 * to be looking — on the Tasks page, on the Bills page — so a morning spent
 * in the company CRM passed in silence. This lives above both shells, which
 * is the whole point: the thing is due whether or not you are on its screen.
 *
 * It rings, it says what is due, and it takes "not now" for an answer —
 * fifteen minutes to an hour, or quiet until tomorrow. Never "done": the
 * thing is still due and still wants doing, and an alarm that could mark it
 * finished would be the wrong button under a tired thumb.
 *
 * The sound is per device and per module, because whether a phone should
 * make a noise is a fact about the phone, not about the account.
 */
export default function DueAlerts() {
  const token = useAuthStore((s) => s.token)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [prefs, setPrefs] = useState<DueAlertPrefs>(() => getDueAlertPrefs())
  const [open, setOpen] = useState(false)
  /** What has already rung, so a bill does not chime every minute it stays due. */
  const rung = useRef<Set<string>>(new Set())

  const { data } = useQuery({
    queryKey: ['due-alerts'],
    queryFn: dueAlerts.list,
    enabled: !!token,
    // A minute is soon enough for a due date and quiet enough for a laptop.
    refetchInterval: 60_000,
    refetchOnWindowFocus: true,
  })

  const items = (data ?? []).filter((a) => prefs[a.kind].enabled)

  useEffect(() => {
    if (items.length === 0) return

    const fresh = items.filter((a) => !rung.current.has(`${a.kind}:${a.uuid}`))
    if (fresh.length === 0) return

    for (const a of fresh) rung.current.add(`${a.kind}:${a.uuid}`)
    setOpen(true)
    // Sound needs the page to have been touched at some point; the box is
    // what carries the message when the browser will not make a noise.
    if (fresh.some((a) => prefs[a.kind].sound)) playChime()
  }, [items, prefs])

  const snooze = useMutation({
    mutationFn: ({ item, minutes }: { item: DueAlert; minutes?: number }) =>
      dueAlerts.snooze({ kind: item.kind, uuid: item.uuid, minutes }),
    onSuccess: (res, { item }) => {
      rung.current.delete(`${item.kind}:${item.uuid}`)
      queryClient.invalidateQueries({ queryKey: ['due-alerts'] })
      toast(res.message, 'success')
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const setModule = (kind: 'task' | 'bill', patch: Partial<DueAlertPrefs['task']>) => {
    const next = { ...prefs, [kind]: { ...prefs[kind], ...patch } }
    setPrefs(next)
    setDueAlertPrefs(next)
  }

  const goTo = (item: DueAlert) => {
    setOpen(false)
    navigate(item.kind === 'task' ? '/tasks' : '/bills')
  }

  if (!token || !open || items.length === 0) return null

  return (
    <Modal title={items.length === 1 ? 'Due now' : `${items.length} things are due`} onClose={() => setOpen(false)}>
      <div className="space-y-3">
        <ul className="space-y-2">
          {items.map((a) => (
            <li key={`${a.kind}:${a.uuid}`} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
              <div className="flex items-start gap-2">
                {a.kind === 'task'
                  ? <CheckSquare className="mt-0.5 size-4 shrink-0 text-brand-500" />
                  : <Receipt className="mt-0.5 size-4 shrink-0 text-amber-500" />}
                <button onClick={() => goTo(a)} className="min-w-0 flex-1 text-left">
                  <p className="truncate font-medium text-slate-800 dark:text-slate-100">{a.title}</p>
                  <p className="text-[11px] text-slate-400">
                    Due {a.due_at}{a.note ? ` · ${a.note}` : ''}
                  </p>
                </button>
              </div>

              <div className="mt-2 flex flex-wrap items-center gap-1">
                <span className="mr-1 text-[11px] text-slate-400">Remind again in</span>
                {SNOOZE.map((m) => (
                  <button
                    key={m}
                    disabled={snooze.isPending}
                    onClick={() => snooze.mutate({ item: a, minutes: m })}
                    className="rounded-lg bg-white px-2 py-0.5 text-xs ring-1 ring-inset ring-slate-200 hover:bg-slate-100 dark:bg-slate-900 dark:ring-slate-700 dark:hover:bg-slate-800"
                  >
                    {m}m
                  </button>
                ))}
                <button
                  disabled={snooze.isPending}
                  onClick={() => snooze.mutate({ item: a })}
                  className="rounded-lg px-2 py-0.5 text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                  title="It stays due — this only stops the alarm until tomorrow morning"
                >
                  Not today
                </button>
              </div>
            </li>
          ))}
        </ul>

        {/* Whether this device should make a noise, per module — some people
            want the bill alarm and not the task one. */}
        <div className="flex flex-wrap gap-3 rounded-xl border border-dashed border-slate-200 p-2 text-xs dark:border-slate-700">
          {(['task', 'bill'] as const).map((kind) => (
            <div key={kind} className="flex items-center gap-2">
              <span className="text-slate-500">{kind === 'task' ? 'My Tasks' : 'Bills'}</span>
              <button
                onClick={() => setModule(kind, { sound: !prefs[kind].sound })}
                className={clsx('flex items-center gap-1 rounded-lg px-2 py-0.5',
                  prefs[kind].sound ? 'text-emerald-600' : 'text-slate-400')}
                title={prefs[kind].sound ? 'Ring on this device' : 'Silent on this device'}
              >
                {prefs[kind].sound ? <Volume2 className="size-3.5" /> : <VolumeX className="size-3.5" />}
                {prefs[kind].sound ? 'Ring' : 'Silent'}
              </button>
              <button
                onClick={() => setModule(kind, { enabled: !prefs[kind].enabled })}
                className={clsx('flex items-center gap-1 rounded-lg px-2 py-0.5',
                  prefs[kind].enabled ? 'text-slate-500' : 'text-slate-400')}
                title={prefs[kind].enabled ? 'Alerts on' : 'No alerts from this module'}
              >
                {prefs[kind].enabled ? <AlarmClock className="size-3.5" /> : <BellOff className="size-3.5" />}
                {prefs[kind].enabled ? 'Alerts on' : 'Off'}
              </button>
            </div>
          ))}
        </div>

        <div className="flex justify-end">
          <Button variant="secondary" onClick={() => setOpen(false)}>Close</Button>
        </div>
      </div>
    </Modal>
  )
}
