import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { AlarmClock, Bell, Check, CheckCheck, Trash2 } from 'lucide-react'
import { formatDistanceToNow } from 'date-fns'
import { clsx } from 'clsx'
import { notifications as notificationsApi, reminders as remindersApi, tasks as tasksApi } from '../api/endpoints'
import { Button, EmptyState, SkeletonList } from './ui'
import type { AppNotification } from '../types'
import { playChime } from '../lib/alerts'

export default function NotificationBell() {
  const [open, setOpen] = useState(false)
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  const { data: count = 0 } = useQuery({
    queryKey: ['notifications-count'],
    queryFn: notificationsApi.unreadCount,
    refetchInterval: 30_000,
  })

  // Chime when new notifications arrive (not on the very first load).
  const prevCount = useRef<number | null>(null)
  useEffect(() => {
    if (prevCount.current !== null && count > prevCount.current) playChime()
    prevCount.current = count
  }, [count])

  const { data: list, isLoading } = useQuery({
    queryKey: ['notifications'],
    queryFn: () => notificationsApi.list(),
    enabled: open,
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['notifications'] })
    queryClient.invalidateQueries({ queryKey: ['notifications-count'] })
  }

  const markRead = useMutation({
    mutationFn: (id: string) => notificationsApi.markRead(id),
    onSuccess: invalidate,
  })
  const markAllRead = useMutation({
    mutationFn: notificationsApi.markAllRead,
    onSuccess: invalidate,
  })
  const remove = useMutation({
    mutationFn: (id: string) => notificationsApi.remove(id),
    onSuccess: invalidate,
  })
  const snooze = useMutation({
    mutationFn: ({ reminderId }: { reminderId: number }) => remindersApi.snooze(reminderId, 30),
    onSuccess: invalidate,
  })
  const completeTask = useMutation({
    mutationFn: (uuid: string) => tasksApi.setStatus(uuid, 'completed'),
    onSuccess: () => {
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['tasks'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
  })

  /**
   * Take the reader to whatever this notification is about.
   *
   * This used to understand exactly one destination — a task — because for
   * a long time a task reminder was very nearly the only thing in here. Now
   * that every part of the app notifies, most rows in this list were about
   * something else entirely: an expense added to a shared ledger, a missed
   * call, a payment, a group role. Every one of those looked clickable, said
   * nothing when clicked, and quietly marked itself read.
   *
   * The server has always sent where to go — action_path is written into
   * every notification's payload — so this only ever had to read it. The
   * task branch stays as the fallback for the older rows already sitting in
   * people's lists, which predate action_path.
   */
  const openNotification = (n: AppNotification) => {
    if (!n.read_at) markRead.mutate(n.id)

    const to = n.data.action_path ?? (n.data.task_uuid ? `/tasks?open=${n.data.task_uuid}` : null)
    if (!to) return

    setOpen(false)
    navigate(to)
  }

  return (
    <div className="relative">
      <button
        onClick={() => setOpen(!open)}
        className="tap relative flex items-center justify-center rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
        title="Notifications"
        aria-label="Notifications"
      >
        <Bell className="size-5 sm:size-4" />
        {count > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex size-4 items-center justify-center rounded-full bg-red-500 text-[10px] font-semibold text-white">
            {count > 9 ? '9+' : count}
          </span>
        )}
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
          {/* On a phone this is a viewport strip, not a button-anchored panel:
              the bell sits left of the theme and sign-out buttons, so a 24rem
              panel hung from its right edge ran clean off the left of the
              screen, headers truncated to "…ions". Anchoring is a desktop
              luxury; a phone gets the width the screen actually has. */}
          <div className="fixed inset-x-2 top-16 z-50 rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900 sm:absolute sm:inset-x-auto sm:right-0 sm:top-auto sm:mt-2 sm:w-96 sm:max-w-[calc(100vw-2rem)]">
            <div className="flex items-center justify-between border-b border-slate-200 px-4 py-2.5 dark:border-slate-800">
              <h3 className="text-sm font-semibold">Notifications</h3>
              {count > 0 && (
                <Button variant="ghost" size="sm" onClick={() => markAllRead.mutate()}>
                  <CheckCheck className="size-3.5" /> Mark all read
                </Button>
              )}
            </div>
            <div className="max-h-96 overflow-y-auto">
              {isLoading ? (
                <SkeletonList rows={4} avatar={false} />
              ) : !list?.data.length ? (
                <EmptyState title="No notifications" hint="Task reminders will appear here." />
              ) : (
                list.data.map((n) => (
                  <div
                    key={n.id}
                    className={clsx(
                      'border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-800/60',
                      !n.read_at && 'bg-brand-50/50 dark:bg-brand-950/30',
                    )}
                  >
                    <button className="block w-full text-left" onClick={() => openNotification(n)}>
                      <p className="text-sm">{n.data.message}</p>
                      <p className="mt-0.5 text-xs text-slate-400">
                        {formatDistanceToNow(new Date(n.created_at), { addSuffix: true })}
                      </p>
                    </button>
                    <div className="mt-1.5 flex gap-1">
                      {n.data.task_uuid && n.data.kind === 'task_reminder' && (
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => {
                            completeTask.mutate(n.data.task_uuid!)
                            if (!n.read_at) markRead.mutate(n.id)
                          }}
                        >
                          <Check className="size-3" /> Complete
                        </Button>
                      )}
                      {n.data.reminder_id && (
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => {
                            snooze.mutate({ reminderId: n.data.reminder_id! })
                            if (!n.read_at) markRead.mutate(n.id)
                          }}
                        >
                          <AlarmClock className="size-3" /> Snooze 30m
                        </Button>
                      )}
                      <Button size="sm" variant="ghost" onClick={() => remove.mutate(n.id)}>
                        <Trash2 className="size-3" />
                      </Button>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        </>
      )}
    </div>
  )
}
