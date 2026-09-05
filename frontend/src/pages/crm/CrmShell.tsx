import { useEffect } from 'react'
import { Navigate, useLocation, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { crmMeQuery, setCrmOrg } from '../../api/crm'
import { withCompany } from '../../lib/crmPath'
import { Spinner } from '../../components/ui'
import CrmLayout from './CrmLayout'

/**
 * The gate in front of the CRM: whose company is this, and does the address
 * bar say so?
 *
 * Every CRM screen lives under /crm/:company. That segment is not decoration —
 * it is what the API is told to answer as, so a link a colleague pastes opens
 * their company's records and not whichever one the reader last looked at.
 * Which means the URL and the answer have to agree, and when they do not, the
 * URL is the thing that gets corrected.
 *
 * Three ways in, one destination:
 *
 *   /crm                      somebody opened the app → their company
 *   /crm/leads                a bookmark from before slugs → the same screen,
 *                             under the company it was always showing
 *   /crm/bhavya-steel/leads   already right → straight through
 *
 * The platform's own screens are the exception and stay unslugged. Standing
 * on the Organizations list means acting as the Super Admin rather than as
 * any one company, and a company in the URL would contradict that.
 */
const PLATFORM_PATHS = ['/crm/organizations', '/crm/field-requests']

export default function CrmShell() {
  const { company } = useParams()
  const location = useLocation()
  const { data: me, isLoading } = useQuery(crmMeQuery())

  const slug = me?.organization?.slug ?? null
  const platform = PLATFORM_PATHS.includes(location.pathname)

  /*
   * A company was named and nothing came back for it — a renamed slug, a
   * typo, or a workspace this account has been taken out of. The stored hat
   * is no better than the URL was, so it goes too; otherwise bouncing to
   * /crm just asks the same wrong question with the old value instead.
   */
  const unusable = !!company && !platform && !me?.enabled && !isLoading

  useEffect(() => {
    if (unusable) setCrmOrg(null)
  }, [unusable])

  /*
   * And when the address is right, it becomes the remembered one. The screens
   * that sit outside a company still read this, and so does the personal side.
   */
  useEffect(() => {
    if (company && slug === company) setCrmOrg(company)
  }, [company, slug])

  if (isLoading) {
    return (
      <div className="flex min-h-dvh items-center justify-center bg-slate-100 dark:bg-slate-950">
        <Spinner />
      </div>
    )
  }

  // The Super Admin's own screens belong to nobody's company.
  if (platform) return <CrmLayout />

  const here = location.pathname + location.search

  if (company && (!me?.enabled || (slug && slug !== company))) {
    return <Navigate replace to={slug ? withCompany(here, slug) : '/crm'} />
  }

  // No company on the address yet, and one to put there.
  if (!company && slug) return <Navigate replace to={withCompany(here, slug)} />

  return <CrmLayout />
}
