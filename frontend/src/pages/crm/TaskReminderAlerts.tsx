import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlarmClock, ExternalLink, FileText, X } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmMe } from '../../api/crm'
import { Button } from '../../components/ui'
import { crmPath } from '../../lib/crmPath'

const SNOOZE_KEY = 'crm-task-reminder-snooze-until'

/**
 * The pendency that comes to you.
 *
 * A task list is somewhere you go; the whole point of "this is waiting on
 * you" is that it arrives. So whatever is asking - work issued to you, or an
 * answer come back to work you issued - is put on the screen, and stays
 * there until it is opened, answered, or deliberately put off.
 *
 * Two ways of putting it off, and they mean different things. "Later" is
 * this browser, this session, an hour: I am in the middle of something.
 * "Tomorrow" is the task itself, on the server, for everybody's screen -
 * or the rhythm the task was given, so a monthly job asks monthly.
 */
export function TaskReminderAlerts({ me }: { me: CrmMe | undefined }) {
  const enabled = !!me?.enabled && !!me?.member
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)

  const { data } = useQuery({
    queryKey: ['crm', 'task-reminders'],
    queryFn: crm.tasks.reminders,
    enabled,
    refetchInterval: 60_000,
    refetchIntervalInBackground: true,
  })

  /*
   * Memoised so its identity is stable between renders - an effect that
   * depends on a fresh array fires on every render rather than when the
   * list actually changes.
   */
  const due = useMemo(() => data ?? [], [data])

  const snoozeMutation = useMutation({
    mutationFn: (uuid: string) => crm.tasks.snooze(uuid),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['crm', 'task-reminders'] }),
  })

  useEffect(() => {
    if (due.length === 0) {
      setOpen(false)
      return
    }

    let until = 0
    try {
      until = Number(sessionStorage.getItem(SNOOZE_KEY) ?? 0)
    } catch { /* storage may be unavailable; ask rather than stay silent */ }

    if (Date.now() >= until) {
      setOpen(true)
    } else {
      // Wake exactly when the hour is up, not a poll later.
      const timer = setTimeout(() => setOpen(true), until - Date.now())
      return () => clearTimeout(timer)
    }
  }, [due.length])

  if (!open || due.length === 0) return null

  const later = () => {
    try {
      sessionStorage.setItem(SNOOZE_KEY, String(Date.now() + 60 * 60_000))
    } catch { /* without storage the next poll reopens it - safe direction */ }
    setOpen(false)
  }

  const openTask = (uuid: string) => {
    window.open(crmPath(`/crm/tasks?task=${uuid}`), '_blank', 'noopener')
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-white shadow-xl dark:bg-slate-900">
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3.5 dark:border-slate-800">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
            <AlarmClock className="size-4 text-amber-500" />
            {due.length === 1 ? '1 thing is waiting' : `${due.length} things are waiting`}
          </h2>
          <button onClick={later} aria-label="Remind me later" className="rounded p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
            <X className="size-4" />
          </button>
        </div>

        <ul className="max-h-80 divide-y divide-slate-50 overflow-y-auto px-5 dark:divide-slate-800/60">
          {due.map((task) => (
            <li key={task.uuid} className="flex items-center gap-3 py-3">
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline gap-x-2">
                  <span className="font-medium text-slate-800 dark:text-slate-100">{task.title}</span>
                  {task.kind === 'pendency' && (
                    <span className="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">
                      Pendency
                    </span>
                  )}
                  {task.overdue && (
                    <span className="rounded-full bg-red-500 px-2 py-0.5 text-[11px] font-semibold text-white">
                      OVERDUE
                    </span>
                  )}
                </div>
                <div className="truncate text-xs text-slate-500">
                  {/* The two things that are actually different: work you
                      owe, and an answer you asked for. */}
                  {task.my_turn === 'do'
                    ? `waiting on you${task.other_party ? ` — raised by ${task.other_party}` : ''}`
                    : `${task.other_party ?? 'They'} have replied`}
                  {task.due_at && ` · due ${task.due_at.slice(0, 16)}`}
                </div>
                {task.invoice && (
                  <div className={clsx('flex items-center gap-1 text-xs', 'text-slate-400')}>
                    <FileText className="size-3" /> {task.invoice.number}
                  </div>
                )}
              </div>
              <div className="flex shrink-0 flex-col gap-1">
                <Button size="sm" variant="secondary" onClick={() => openTask(task.uuid)}>
                  <ExternalLink className="size-3.5" /> Open
                </Button>
                <button
                  onClick={() => snoozeMutation.mutate(task.uuid)}
                  disabled={snoozeMutation.isPending}
                  className="text-[11px] text-slate-400 hover:text-slate-600 disabled:opacity-50 dark:hover:text-slate-200"
                >
                  Not today
                </button>
              </div>
            </li>
          ))}
        </ul>

        <div className="flex items-center justify-between gap-2 border-t border-slate-100 px-5 py-3 dark:border-slate-800">
          <span className="text-xs text-slate-400">
            Opening one, or replying on it, stops it asking. “Not today” puts one off for a day.
          </span>
          <Button size="sm" variant="secondary" onClick={later}>Later</Button>
        </div>
      </div>
    </div>
  )
}
