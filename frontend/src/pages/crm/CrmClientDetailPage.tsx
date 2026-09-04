import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ReceiptText } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, CRM_CLIENT_CATEGORY_LABELS, CRM_PAYMENT_STATUS_LABELS } from '../../api/crm'
import { Button, Card, Spinner } from '../../components/ui'
import { EmailLink, PhoneLink } from '../../components/ContactLink'

const inr = (v: number | string) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  if (value === null || value === undefined || value === '') return null
  return (
    <div className="flex justify-between gap-4 py-1.5 text-sm">
      <span className="shrink-0 text-slate-400">{label}</span>
      <span className="text-right font-medium text-slate-700 dark:text-slate-200">{value}</span>
    </div>
  )
}

export default function CrmClientDetailPage() {
  const { uuid } = useParams()
  const navigate = useNavigate()
  const { data: c, isLoading } = useQuery({
    queryKey: ['crm', 'client', uuid],
    queryFn: () => crm.clients.get(uuid!),
  })

  if (isLoading || !c) {
    return <div className="flex justify-center py-20"><Spinner /></div>
  }

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <button onClick={() => navigate('/crm/clients')} aria-label="Back" className="rounded p-1.5 text-slate-400 hover:bg-slate-200/60 dark:hover:bg-slate-800">
            <ArrowLeft className="size-4" />
          </button>
          <div>
            <h1 className="text-xl font-semibold text-slate-900 dark:text-white">{c.company_name}</h1>
            <p className="text-sm text-slate-500">
              {[c.contact_person, c.city, c.category ? CRM_CLIENT_CATEGORY_LABELS[c.category] : null].filter(Boolean).join(' · ')}
            </p>
          </div>
        </div>
        <Button onClick={() => navigate(`/crm/invoices/new?kind=proforma&client=${c.uuid}`)}>
          <ReceiptText className="size-4" /> New proforma
        </Button>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Contact</h2>
          <Row label="Person" value={[c.title, c.contact_person].filter(Boolean).join(' ')} />
          <Row label="Designation" value={c.designation} />
          <Row label="Mobile" value={<PhoneLink value={c.mobile} label={c.company_name} subject={{ type: 'client', uuid: c.uuid }} />} />
          <Row label="Telephone" value={<PhoneLink value={c.telephone} label={c.company_name} subject={{ type: 'client', uuid: c.uuid }} />} />
          <Row label="Email" value={<EmailLink value={c.email} />} />
          <Row label="Alternate" value={<EmailLink value={c.alternate_email} />} />
          <Row label="Website" value={c.website} />
        </Card>
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Address & tax</h2>
          <Row label="Address" value={c.address} />
          <Row label="City" value={c.city} />
          <Row label="State" value={c.state} />
          <Row label="PIN" value={c.pincode} />
          <Row label="Country" value={c.country} />
          <Row label="GST no." value={c.gst_no} />
          <Row label="PAN" value={c.pan_no} />
        </Card>
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Account</h2>
          <Row label="Status" value={c.status === 'active' ? 'Active' : 'Inactive'} />
          <Row label="Assigned to" value={c.assigned_member?.name} />
          <Row label="Shared with" value={(c.shared_with ?? []).map((m) => m.name).filter(Boolean).join(', ') || undefined} />
          <Row label="Added" value={c.created_at?.slice(0, 10)} />
          {c.notes && <p className="mt-2 rounded-lg bg-slate-50 p-2.5 text-xs text-slate-500 dark:bg-slate-800/60">{c.notes}</p>}
        </Card>
      </div>

      {(c.transfers?.length ?? 0) > 0 && (
        <Card>
          <h2 className="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">Ownership trail</h2>
          <ol className="space-y-2 text-sm">
            {c.transfers!.map((t, i) => (
              <li key={i} className="flex flex-wrap items-baseline gap-x-2 border-l-2 border-slate-200 pl-3 dark:border-slate-700">
                <span className="font-medium text-slate-700 dark:text-slate-200">
                  {t.action === 'client.transferred'
                    ? `${t.from ?? '—'} → ${t.to ?? '—'}`
                    : `Shared with ${t.to ?? '—'}`}
                </span>
                <span className="text-xs text-slate-400">
                  {[t.by ? `by ${t.by}` : null, t.at].filter(Boolean).join(' · ')}
                </span>
                {t.invoices_kept ? (
                  <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                    {t.invoices_kept} invoice{t.invoices_kept === 1 ? '' : 's'} stayed with {t.from}
                  </span>
                ) : null}
                {t.note && <span className="w-full text-xs italic text-slate-400">“{t.note}”</span>}
              </li>
            ))}
          </ol>
        </Card>
      )}

      <Card>
        <h2 className="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">Billing history</h2>
        {!c.invoices || c.invoices.length === 0 ? (
          <p className="text-sm text-slate-400">No invoices raised for this client yet.</p>
        ) : (
          <div className="-mx-4 overflow-x-auto px-4">
            <table className="w-full min-w-[560px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-3 font-medium">Number</th>
                  <th className="py-2 pr-3 font-medium">Kind</th>
                  <th className="py-2 pr-3 font-medium">Date</th>
                  <th className="py-2 pr-3 text-right font-medium">Total</th>
                  <th className="py-2 font-medium">Payment</th>
                </tr>
              </thead>
              <tbody>
                {c.invoices.map((i) => (
                  <tr key={i.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/50">
                    <td className="py-2 pr-3">
                      <Link to={`/crm/invoices/${i.uuid}`} className="font-medium text-emerald-600 hover:underline">{i.number}</Link>
                    </td>
                    <td className="py-2 pr-3 capitalize">{i.kind}</td>
                    <td className="whitespace-nowrap py-2 pr-3 text-slate-500">{i.invoice_date}</td>
                    <td className="whitespace-nowrap py-2 pr-3 text-right font-medium">{inr(i.total)}</td>
                    <td className="py-2">
                      <span className={clsx(
                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                        i.payment_status === 'paid'
                          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                          : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
                      )}>
                        {CRM_PAYMENT_STATUS_LABELS[i.payment_status] ?? i.payment_status}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
