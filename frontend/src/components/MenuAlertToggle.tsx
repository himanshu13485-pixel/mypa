import { useState } from 'react'
import { useLocation } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, BellOff } from 'lucide-react'
import { clsx } from 'clsx'
import { profile as profileApi } from '../api/endpoints'
import { crm } from '../api/crm'

/**
 * Which menu a screen belongs to, for the switch in its header.
 *
 * The keys match the server's NotificationTopics; the paths are the CRM's
 * own sidebar. Anything not listed has no switch in its header rather than
 * the wrong one - a header that silences somebody else's menu is worse than
 * a header with no button on it.
 */
const TOPIC_OF_PATH: Record<string, string> = {
  leads: 'leads',
  'lead-log': 'leads',
  clients: 'clients',
  invoices: 'invoices',
  'invoice-log': 'invoices',
  recurring: 'invoices',
  'tds-certificates': 'tds',
  payments: 'payments',
  vendors: 'vendors',
  expenses: 'expenses',
  salary: 'salary',
  incentives: 'salary',
  commissions: 'salary',
  tasks: 'tasks',
  approvals: 'approvals',
  leaves: 'leaves',
  'leave-log': 'leaves',
  punch: 'leaves',
  complaints: 'complaints',
  'complaint-log': 'complaints',
  contests: 'contests',
  cms: 'notice',
  // The personal side, where the same component sits in the page header.
  messages: 'chat',
  calls: 'chat',
  connections: 'connections',
  groups: 'chat',
  calendar: 'calendar',
  meetings: 'calendar',
  notes: 'files',
  files: 'files',
}

/** What either source answers with: the menus, and each one's two switches. */
type TopicSwitches = {
  topics: { key: string; label: string; hint: string; group: string }[]
  values: Record<string, { email: boolean; app: boolean }>
}

/**
 * The menus a company decides, as opposed to the ones a person does.
 *
 * Mirrors the server's NotificationTopics groups. Kept beside the path map
 * because the two answer one question together: which switch, and whose.
 */
const COMPANY_TOPICS = new Set([
  'leads', 'clients', 'invoices', 'payments', 'tds', 'vendors', 'expenses', 'salary',
  'tasks', 'approvals', 'leaves', 'complaints', 'contests', 'notice',
])

/** The menu this path belongs to, or null when it has no switch of its own. */
function topicForPath(pathname: string): string | null {
  const parts = pathname.split('/').filter(Boolean)

  // /crm/<company>/<section> or /crm/<section> - and /<section> off the
  // personal side. The last recognised segment wins.
  for (let i = parts.length - 1; i >= 0; i--) {
    const topic = TOPIC_OF_PATH[parts[i]]
    if (topic) return topic
  }

  return null
}

/**
 * Stop this menu writing to you, from the menu itself.
 *
 * The whole list lives in Settings, but nobody goes to Settings while being
 * annoyed by an e-mail - they are looking at the screen that sent it. So
 * the screen carries its own pair of switches, saving the same preference
 * the settings page reads.
 */
export function MenuAlertToggle({ className, scope = 'personal' }: {
  className?: string
  /*
   * Whose switch this is.
   *
   * 'company' is the CRM's menus, decided by the company Admin for everybody
   * - the CRM shell only renders it for the Admin. 'personal' is a person's
   * own account: chat, calendar, connections. Each shows only its own menus,
   * so an Admin in the CRM cannot silence somebody's personal chat and an
   * employee cannot silence the company's payments.
   */
  scope?: 'personal' | 'company'
}) {
  const { pathname } = useLocation()
  const found = topicForPath(pathname)
  const topic = found && (scope === 'company') === COMPANY_TOPICS.has(found) ? found : null
  const [open, setOpen] = useState(false)
  const queryClient = useQueryClient()
  const key = scope === 'company' ? ['crm', 'notification-policy'] : ['notification-topics']

  const { data } = useQuery({
    queryKey: key,
    // Two sources, one shape: the menus, and each one's two switches.
    queryFn: (): Promise<TopicSwitches> => (scope === 'company'
      ? crm.notificationPolicy.get()
      : profileApi.notificationTopics()),
    enabled: !!topic,
    staleTime: 5 * 60_000,
  })

  const mutation = useMutation({
    mutationFn: (change: { email?: boolean; app?: boolean }) =>
      scope === 'company'
        ? crm.notificationPolicy.set({ [topic!]: change })
        : profileApi.setNotificationTopics({ [topic!]: change }),
    onSuccess: (res) => {
      queryClient.setQueryData(key, (prev: typeof data) =>
        prev ? { ...prev, values: res.data.values } : prev)
    },
  })

  if (!topic) return null

  const value = data?.values[topic]
  const label = data?.topics.find((t) => t.key === topic)?.label ?? 'this menu'
  // Silent only when both are off: one channel still reaching you is not
  // silence, and the icon should not claim it is.
  const silenced = value ? !value.email && !value.app : false

  return (
    <div className={clsx('relative', className)}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        title={`Alerts for ${label}`}
        aria-label={`Alerts for ${label}`}
        className={clsx(
          'rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-slate-800',
          silenced ? 'text-slate-300 dark:text-slate-600' : 'text-slate-500',
        )}
      >
        {silenced ? <BellOff className="size-4" /> : <Bell className="size-4" />}
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-20" onClick={() => setOpen(false)} />
          <div className="absolute right-0 top-11 z-30 w-64 rounded-xl border border-slate-200 bg-white p-3 shadow-lift dark:border-slate-700 dark:bg-slate-800">
            <p className="mb-2 text-xs font-semibold text-slate-700 dark:text-slate-100">
              Alerts for {label}
            </p>
            {scope === 'company' && (
              <p className="mb-2 text-[11px] text-amber-600 dark:text-amber-400">
                For everyone in the company.
              </p>
            )}
            {(['email', 'app'] as const).map((channel) => (
              <label key={channel} className="flex items-center gap-2 py-1 text-xs text-slate-600 dark:text-slate-300">
                <input
                  type="checkbox"
                  className="size-4 accent-emerald-600"
                  checked={value?.[channel] !== false}
                  disabled={mutation.isPending}
                  onChange={(e) => mutation.mutate({ [channel]: e.target.checked })}
                />
                {channel === 'email' ? 'Send me e-mail' : 'Alert me on the app'}
              </label>
            ))}
            <p className="mt-2 border-t border-slate-100 pt-2 text-[11px] text-slate-400 dark:border-slate-700">
              Switched off, these still appear in the bell — the record is kept either way.
              Every menu is listed in Settings.
            </p>
          </div>
        </>
      )}
    </div>
  )
}
