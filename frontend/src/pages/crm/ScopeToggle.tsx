import { clsx } from 'clsx'
import { useQuery } from '@tanstack/react-query'
import { crmMeQuery } from '../../api/crm'

/**
 * The two ledgers of a Team Head: their own sales, and the team's. Screens
 * showing money open on "My sales" and switch to the combined view on
 * purpose, so the two never mix by accident.
 *
 * Rendered only for someone who actually holds two ledgers — a Team Head
 * with reportees. Managers already see everything; plain employees have
 * nothing to combine; neither gets a switch.
 */
export function useTeamHead(): boolean {
  const { data: me } = useQuery(crmMeQuery())
  const manager = me?.member?.crm_role === 'admin' || me?.member?.crm_role === 'subadmin'

  return !manager && !!me?.has_team
}

export function ScopeToggle({ scope, onChange, show }: {
  scope: 'mine' | 'team'
  onChange: (scope: 'mine' | 'team') => void
  show: boolean
}) {
  if (!show) return null

  return (
    <div className="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm dark:bg-slate-800/60">
      {([['mine', 'My sales'], ['team', 'Team total (incl. reportees)']] as const).map(([key, label]) => (
        <button
          key={key}
          onClick={() => onChange(key)}
          className={clsx(
            'flex-1 rounded-lg px-3 py-1.5 font-medium transition',
            scope === key
              ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white'
              : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300',
          )}
        >
          {label}
        </button>
      ))}
    </div>
  )
}
