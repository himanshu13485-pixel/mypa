import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { LogOut, UserCog } from 'lucide-react'
import { borrowedSeat, leaveWorkspace } from '../lib/impersonation'

/**
 * A bar across the top saying whose seat this is.
 *
 * Not decoration and not a courtesy. Somebody working inside another person's
 * account and forgetting it is how a message gets sent from the wrong name, a
 * record gets changed by the wrong hand, and a support call becomes an
 * incident. The one thing this must never be is subtle — so it is amber, it
 * is above everything, and it does not go away.
 *
 * It says the level too, because "you are Amardeep" and "you are Amardeep and
 * cannot change anything" are different states to be in and the difference
 * explains every refusal the session is about to give.
 */
const LEVEL_NOTE: Record<string, string> = {
  crm_read: 'their CRM, to look at only',
  crm: 'their CRM workspace',
  account: 'their whole Netvork account',
}

export default function ImpersonationBanner() {
  const navigate = useNavigate()
  const seat = borrowedSeat()
  const [leaving, setLeaving] = useState(false)

  if (!seat) return null

  const back = async () => {
    setLeaving(true)
    await leaveWorkspace()
    /*
     * A full reload rather than a route change.
     *
     * Every cache in the page — React Query, the presence store, the CRM
     * workspace header — is holding the employee's answers, and walking back
     * through the router would leave the admin looking at them under their
     * own name. Reloading is the one way to be sure nothing survives the
     * change of identity.
     */
    navigate('/crm/employees', { replace: true })
    window.location.reload()
  }

  return (
    <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-amber-500 px-4 py-1.5 text-center text-xs font-medium text-amber-950">
      <span className="inline-flex items-center gap-1.5">
        <UserCog className="size-3.5 shrink-0" />
        You are in <strong>{seat.name}</strong>
        {seat.employeeCode ? ` (${seat.employeeCode})` : ''}&rsquo;s seat —{' '}
        {LEVEL_NOTE[seat.level] ?? 'their workspace'}.
      </span>
      <button
        type="button"
        onClick={back}
        disabled={leaving}
        className="tap inline-flex items-center gap-1 rounded-full bg-amber-950/15 px-2.5 py-0.5 font-semibold hover:bg-amber-950/25 disabled:opacity-60"
      >
        <LogOut className="size-3" />
        {leaving ? 'Leaving…' : 'Back to my account'}
      </button>
    </div>
  )
}
