import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { AlarmClock, Bell, Check, CheckCheck, Trash2 } from 'lucide-react'
import { formatDistanceToNow } from 'date-fns'
import { clsx } from 'clsx'
import { notifications as notificationsApi, reminders as remindersApi, tasks as tasksApi } from '../api/endpoints'
import { Button, EmptyState, Spinner } from './ui'
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

  const openTask = (n: AppNotification) => {
    if (!n.read_at) markRead.mutate(n.id)
    if (n.data.task_uuid) {
      setOpen(false)
      navigate(`/tasks?open=${n.data.task_uuid}`)
      return
    }
    // CRM (and any future) notifications carry their destination with them.
    if (n.data.action_path) {
      setOpen(false)
      navigate(n.data.action_path)
    }
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
          <div className="absolute right-0 z-50 mt-2 w-96 max-w-[calc(100vw-2rem)] rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900">
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
                <Spinner />
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
                    <button className="block w-full text-left" onClick={() => openTask(n)}>
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
