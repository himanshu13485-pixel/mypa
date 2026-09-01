import { useLocation } from 'react-router-dom'

/**
 * Where the Connect suite is being shown from.
 *
 * The same pages serve two shells: the personal app at `/messages`, and the
 * CRM at `/crm/connect/messages`. A link written for one of them takes the
 * reader out of the other — clicking Message inside the CRM used to drop the
 * company workspace and reload the personal app. Anything linking between
 * Connect pages asks here instead, so a journey that starts in the CRM stays
 * in the CRM.
 *
 * Only the pages that exist in both shells are addressed this way. A meeting
 * room and a screen session are full-screen routes of their own at the top
 * level, and the CRM's own Tasks are a different thing from personal tasks.
 */
export function useConnectBase(): string {
  const { pathname } = useLocation()

  return pathname.startsWith('/crm/connect') ? '/crm/connect' : ''
}
