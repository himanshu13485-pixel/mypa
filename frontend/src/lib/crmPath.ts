/**
 * Where a company lives in the address bar.
 *
 * The CRM used to be at /crm/leads for everybody, and which company you were
 * looking at was a value in localStorage. That works until somebody has two
 * of them — a Super Admin who entered a workspace, an accountant who works
 * for two firms — and then a pasted link means one company to the person who
 * sent it and another to the person who opens it.
 *
 * So the company is a path segment: /crm/bhavya-steel/leads. The browser
 * shows whose records are on screen, a link says what it points at, and the
 * API is asked to answer as that company rather than as whatever the last
 * session happened to choose.
 */

/**
 * The first segments the CRM's own screens answer to.
 *
 * This is what makes /crm/leads readable at all: without it there is no way
 * to tell a company called "leads" from the leads screen. Anything in here is
 * a screen; anything else in that position is a company.
 *
 * Kept honest by crmPath.test.ts, which reads the route table out of App.tsx
 * and fails when a route exists that is not listed here — because the day
 * they drift is the day one screen becomes unreachable.
 */
export const CRM_SECTIONS = new Set([
  'organizations', 'employees', 'clients', 'leads', 'lead-log', 'targets', 'dwr',
  'punch', 'payments', 'complaints', 'complaint-log', 'hr-policy', 'incentives',
  'vendors', 'expenses', 'salary', 'leaves', 'tasks', 'approvals', 'newsletters',
  'cms', 'user-log', 'reports', 'workspace-fields', 'field-requests', 'contests',
  'invoices', 'invoice-log', 'recurring', 'commissions', 'overview', 'settings',
  'connect', 'pl', 'assets', 'churn', 'communication',
])

/**
 * The company segment of a CRM path, or null when there is not one.
 *
 * Null covers two different things and deliberately does not distinguish
 * them: a path outside the CRM, and a CRM path written the old way. Both
 * mean "nobody has said which company", and both are answered the same way.
 */
export function companyIn(pathname: string): string | null {
  const [, root, first] = pathname.split('/')
  if (root !== 'crm' || !first) return null

  return CRM_SECTIONS.has(first) ? null : first
}

/**
 * Put a company on a CRM path.
 *
 * Written to be safe to call on anything: a path that already carries a
 * company comes back with the company swapped rather than doubled, and a
 * path that is not a CRM path comes back untouched. Links are built in a
 * hundred places and none of them should have to know which case they are.
 */
export function withCompany(path: string, company: string | null | undefined): string {
  if (!company || !path.startsWith('/crm')) return path

  const [pathname, query] = splitQuery(path)
  const rest = stripCompany(pathname)

  return `/crm/${company}${rest}${query}`
}

/** A CRM path with its company segment taken off, leading slash kept. */
function stripCompany(pathname: string): string {
  const parts = pathname.split('/').filter(Boolean)  // ['crm', ...]
  const after = parts.slice(1)
  if (after.length > 0 && !CRM_SECTIONS.has(after[0]!)) after.shift()

  return after.length ? '/' + after.join('/') : ''
}

function splitQuery(path: string): [string, string] {
  const cut = path.search(/[?#]/)

  return cut === -1 ? [path, ''] : [path.slice(0, cut), path.slice(cut)]
}

/**
 * The link builder the CRM's pages use, reading the company out of the URL
 * the browser is already showing.
 *
 * A global read rather than a hook on purpose. These calls are inside .map()
 * callbacks, ternaries and event handlers in a hundred places, and a hook
 * would mean threading a value through every one of them. The value is safe
 * to read this way because it cannot change without a navigation, and a
 * navigation re-renders everything that could have read it.
 */
export function crmPath(path: string): string {
  return withCompany(path, companyIn(window.location.pathname))
}
