import { useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Cake, Heart } from 'lucide-react'
import { crm, type CrmMe } from '../../api/crm'
import { Card, EmptyState, Pager, Select, Spinner } from '../../components/ui'

/**
 * The Birthdays menu: every wish sent and every thank-you, with the date and
 * time of each.
 *
 * An Admin or Subadmin reads the whole company's; anybody else reads the
 * wishes they sent and the ones they received.
 */
export default function CrmBirthdaysPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  const manages = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'

  const [year, setYear] = useState('')
  const [direction, setDirection] = useState('')
  const [page, setPage] = useState(1)

  const { data: today } = useQuery({ queryKey: ['crm', 'birthdays-today'], queryFn: crm.birthdays.today })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'birthday-wishes', year, direction, page],
    queryFn: () => crm.birthdays.history({
      year: year || undefined,
      direction: direction || undefined,
      page,
    }),
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

      <Card>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <h2 className="mr-auto text-sm font-semibold text-slate-800 dark:text-slate-100">
            {manages ? "The company's wishes" : 'Your wishes'}
          </h2>
          <Select value={direction} onChange={(e) => { setDirection(e.target.value); setPage(1) }}>
            <option value="">{manages ? 'Everyone' : 'Sent and received'}</option>
            <option value="sent">Sent by me</option>
            <option value="received">Received by me</option>
          </Select>
          <Select value={year} onChange={(e) => { setYear(e.target.value); setPage(1) }}>
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
            {data.data.map((w) => (
              <li key={w.uuid} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                <div className="flex flex-wrap items-baseline justify-between gap-2 text-xs">
                  <span>
                    <span className="font-semibold text-slate-800 dark:text-slate-100">{w.from?.name}</span>
                    <span className="text-slate-500"> wished </span>
                    <span className="font-semibold text-slate-800 dark:text-slate-100">{w.to?.name}</span>
                  </span>
                  <span className="text-slate-400">{w.sent_at?.slice(0, 16)}</span>
                </div>
                <p className="mt-1 whitespace-pre-wrap text-sm text-slate-700 dark:text-slate-200">{w.message}</p>

                {w.reply ? (
                  <div className="mt-2 rounded-lg bg-white p-2 dark:bg-slate-900">
                    <div className="flex flex-wrap items-baseline justify-between gap-2 text-xs">
                      <span className="flex items-center gap-1 font-semibold text-emerald-600 dark:text-emerald-400">
                        <Heart className="size-3 fill-current" /> {w.to?.name} replied
                      </span>
                      <span className="text-slate-400">{w.replied_at?.slice(0, 16)}</span>
                    </div>
                    <p className="mt-0.5 whitespace-pre-wrap text-sm text-slate-600 dark:text-slate-300">{w.reply}</p>
                  </div>
                ) : (
                  <p className="mt-1 text-[11px] text-slate-400">Not answered yet.</p>
                )}
              </li>
            ))}
          </ul>
        )}
        <Pager resp={data} onPage={setPage} />
      </Card>
    </div>
  )
}
