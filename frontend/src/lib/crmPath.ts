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
  'vendors', 'expenses', 'salary', 'leaves', 'leave-log', 'tasks', 'approvals', 'newsletters',
  'cms', 'user-log', 'reports', 'workspace-fields', 'field-requests', 'contests',
  'invoices', 'invoice-log', 'tds-certificates', 'spam-reports', 'recurring', 'commissions', 'overview', 'settings',
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

/**
 * Does this sidebar entry point at the page currently open?
 *
 * Proforma, Proforma log, Invoices and Invoice log share two paths between
 * the four of them and are told apart only by ?kind, so a comparison that
 * strips the query matches whichever is listed first. That decides the
 * highlight — and, more consequentially, which section's badge is marked
 * seen when somebody opens a screen. Getting it wrong there leaves a badge
 * that can never be cleared.
 *
 * A document page carrying no kind at all reads as a tax invoice, because
 * that is what the invoices screen defaults to.
 *
 * @param to        the entry's target, as written in the sidebar (no company)
 * @param pathname  the address bar's path (which does carry one)
 * @param search    the address bar's query string
 */
export function navMatches(to: string, pathname: string, search: string): boolean {
  /*
   * The company comes from the address being compared, not from the browser.
   * Sidebar entries are written without one; the address bar carries it. And
   * reading window here would make this untestable — which is how its only
   * other copy went wrong unnoticed.
   */
  const company = companyIn(pathname)
  const [path, query] = withCompany(to, company).split('?')

  // The dashboard is the one entry that must not match its own children.
  if (path === withCompany('/crm', company)) return pathname === path

  if (pathname !== path && !pathname.startsWith(path + '/')) return false
  if (!query) return true

  const want = new URLSearchParams(query)
  const have = new URLSearchParams(search)

  return [...want.entries()].every(([key, value]) =>
    (have.get(key) ?? (key === 'kind' ? 'invoice' : null)) === value)
}
