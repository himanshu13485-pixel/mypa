import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronUp, Target } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmMe, type CrmMyTargetMonth } from '../api/crm'
import { Card } from './ui'

const FOLD_KEY = 'crm-target-strip-folded'

const inr = (v: number) => '₹' + Math.round(v).toLocaleString('en-IN')

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`

/** How many days of this month are still to come, today included as a day to work. */
function daysLeftInMonth(): number {
  const now = new Date()
  const last = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate()
  return last - now.getDate()
}

/**
 * The one line under the figures.
 *
 * It has one job: to tell somebody where they stand and leave them wanting to
 * carry on. So it names what is left and how much month is left to do it in,
 * and it never implies the person is behind, slow or failing. A desk with no
 * number set this month is told its work still counts rather than being told
 * off for a gap somebody else left.
 */
function encouragement(kind: 'sales' | 'clients', row: CrmMyTargetMonth, daysLeft: number): string {
  if (kind === 'clients') {
    if (!row.client_target) return 'No client target set for this month yet. Every client you build still counts.'
    const left = Math.max(0, row.client_target - row.clients_built)
    const over = row.clients_built - row.client_target
    if (left === 0) {
      return over > 0
        ? `Ahead of target by ${plural(over, 'client', 'clients')}. Lovely work.`
        : 'Client target met for the month. Everything from here is ahead.'
    }
    if ((row.client_percent ?? 0) >= 80) return `Nearly there: ${plural(left, 'client', 'clients')} to go.`
    if (daysLeft >= 10) return `Plenty of month left: ${daysLeft} days to build ${plural(left, 'client', 'clients')}.`
    if (daysLeft === 0) return `Final day, with ${plural(left, 'client', 'clients')} still to build.`
    return `${plural(daysLeft, 'day', 'days')} left and ${plural(left, 'client', 'clients')} to go. Every one counts.`
  }

  if (!row.target) return 'No target set for this month yet. Everything you bill still counts.'
  const left = row.pending_target
  const over = row.achieved - row.target
  if (left <= 0) {
    return over > 0
      ? `Ahead of target by ${inr(over)}. Lovely work.`
      : 'Target met for the month. Everything from here is ahead.'
  }
  if ((row.percent ?? 0) >= 80) return `Nearly there: ${inr(left)} to go.`
  if (daysLeft >= 10) return `Plenty of month left: ${daysLeft} days to bring in ${inr(left)}.`
  if (daysLeft === 0) return `Final day, with ${inr(left)} still on the table.`
  return `${plural(daysLeft, 'day', 'days')} left and ${inr(left)} to go. Every invoice counts.`
}

/** A figure and its name, wrapping rather than squeezing when the screen is narrow. */
function Figure({ label, value, quiet }: { label: string; value: string; quiet?: boolean }) {
  return (
    <div className="min-w-0">
      <p className="text-[11px] uppercase tracking-[0.06em] text-slate-400">{label}</p>
      <p
        className={clsx(
          'truncate tabular-nums',
          quiet
            ? 'text-sm text-slate-500 dark:text-slate-400'
            : 'text-base font-semibold text-slate-800 dark:text-slate-100',
        )}
      >
        {value}
      </p>
    </div>
  )
}

/**
 * A salesperson's own standing, across the top of every CRM screen.
 *
 * Targets used to live on one screen nobody opened until the last week of the
 * month, by which time the month was decided. This puts the number where the
 * work happens, so it is a companion rather than a summons: it praises when it
 * can, counts what is left when it must, and folds away to a single percent for
 * anybody who would rather not be watched by it.
 *
 * It draws nothing at all while the answer is in the air, if the request fails,
 * or when nothing was asked of this desk in four months. A strip about somebody
 * else's targets is worse than no strip, and a broken one must never take a
 * page down with it.
 */
export default function CrmTargetStrip({ me }: { me: CrmMe | undefined }) {
  // Remembered per browser, because folding it is a standing preference about
  // this person's own screen and not something to ask on every page.
  const [folded, setFolded] = useState(() => {
    try {
      return localStorage.getItem(FOLD_KEY) === '1'
    } catch {
      return false
    }
  })

  const fold = (on: boolean) => {
    setFolded(on)
    try {
      localStorage.setItem(FOLD_KEY, on ? '1' : '0')
    } catch { /* a private window simply opens it again next time */ }
  }

  const { data } = useQuery({
    queryKey: ['crm', 'my-target'],
    queryFn: crm.targets.mine,
    enabled: !!me?.enabled,
    // Invoices and clients land through the day, not by the second, and this
    // rides every screen: a fresh read on every route change would be noise.
    staleTime: 5 * 60_000,
    retry: false,
  })

  if (!data?.has_target) return null

  const { kind, current, months, best } = data
  const percent = kind === 'clients' ? current.client_percent : current.percent
  const pct = percent ?? 0
  const bestElsewhere = best && best !== current.label ? best : null

  // The four bars are read against the best of the four, so a good month is
  // visibly the tall one rather than everything sitting at the same height.
  const valueOf = (m: CrmMyTargetMonth) => (kind === 'clients' ? m.clients_built : m.achieved)
  const peak = Math.max(...months.map(valueOf), 0)

  return (
    <Card className="mb-4 p-3 sm:p-4">
      {/*
        * The whole top line is the control, so a thumb has the width of the
        * card to aim at rather than a chevron in a corner.
        */}
      <button
        type="button"
        onClick={() => fold(!folded)}
        aria-expanded={!folded}
        className="tap flex w-full items-center gap-2 text-left"
      >
        <Target className="size-4 shrink-0 text-emerald-500" />
        <span className="min-w-0 flex-1 truncate text-sm font-semibold text-slate-800 dark:text-slate-100">
          My target
          <span className="font-normal text-slate-400"> · {current.label}</span>
        </span>
        <span
          className={clsx(
            'shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums',
            pct >= 100
              ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
              : pct >= 60
                ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400'
                : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
          )}
        >
          {percent !== null ? `${percent}%` : '—'}
        </span>
        {folded
          ? <ChevronDown className="size-4 shrink-0 text-slate-400" />
          : <ChevronUp className="size-4 shrink-0 text-slate-400" />}
      </button>

      {!folded && (
        // One column on a phone and two from the small breakpoint up, so the
        // figures and the four month bars never fight over the same width.
        <div className="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:gap-5">
          <div className="min-w-0">
            <div className="grid grid-cols-2 gap-x-4 gap-y-2 sm:flex sm:flex-wrap sm:gap-x-6">
              {kind === 'clients' ? (
                <>
                  <Figure label="Client target" value={current.client_target ? String(current.client_target) : '—'} />
                  <Figure label="Built" value={String(current.clients_built)} />
                  <Figure
                    label="Left to build"
                    value={String(Math.max(0, current.client_target - current.clients_built))}
                  />
                  <Figure label="New clients" value={String(current.clients_built_new)} quiet />
                  {current.payment_due > 0 && (
                    <Figure label="Payment due" value={inr(current.payment_due)} quiet />
                  )}
                </>
              ) : (
                <>
                  <Figure label="Target" value={current.target ? inr(current.target) : '—'} />
                  <Figure label="Achieved" value={inr(current.achieved)} />
                  <Figure label="Pending target" value={inr(current.pending_target)} />
                  <Figure label="Payment due" value={inr(current.payment_due)} quiet />
                </>
              )}
            </div>

            <div className="mt-2.5 flex items-center gap-2">
              <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div
                  className={clsx(
                    'h-full rounded-full transition-all',
                    pct >= 100 ? 'bg-emerald-500' : pct >= 60 ? 'bg-amber-400' : 'bg-brand-500',
                  )}
                  style={{ width: `${Math.min(100, pct)}%` }}
                />
              </div>
            </div>

            <p className="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
              {encouragement(kind, current, daysLeftInMonth())}
            </p>
          </div>

          {/* The four months as a growth map. A grid rather than a row of fixed
              widths, so on a 360px screen the bars shrink instead of running
              off the side. */}
          <div className="sm:w-48">
            <div className="grid grid-cols-4 gap-2">
              {months.map((m) => {
                const value = valueOf(m)
                const height = peak > 0 ? Math.max(6, Math.round((value / peak) * 100)) : 6
                return (
                  <div
                    key={m.month}
                    title={`${m.label} · ${kind === 'clients' ? plural(value, 'client', 'clients') : inr(value)}`}
                    className="flex min-w-0 flex-col items-center gap-1"
                  >
                    <div className="flex h-10 w-full items-end justify-center">
                      <div
                        className={clsx(
                          'w-3/5 rounded-t-md transition-all',
                          m.is_current ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-slate-700',
                        )}
                        style={{ height: `${height}%` }}
                      />
                    </div>
                    <span
                      className={clsx(
                        'w-full truncate text-center text-[10px]',
                        m.is_current
                          ? 'font-semibold text-emerald-600 dark:text-emerald-400'
                          : 'text-slate-400',
                      )}
                    >
                      {m.label}
                    </span>
                  </div>
                )
              })}
            </div>
            {bestElsewhere && (
              <p className="mt-1 text-center text-[11px] text-slate-400">Best month so far: {bestElsewhere}</p>
            )}
          </div>
        </div>
      )}
    </Card>
  )
}
