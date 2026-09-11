import { useMemo, useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, FileCheck2, History, Mail, Search, Send, Undo2 } from 'lucide-react'
import { clsx } from 'clsx'
import { crm, type CrmMe, type CrmTdsRow } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import {
  Button, Card, EmptyState, Input, Label, Modal, Select, Spinner, Textarea,
} from '../../components/ui'

const inr = (value: string | number, currency = 'INR') =>
  currency + ' ' + Number(value).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/**
 * The certificate for tax the client already deducted.
 *
 * The money left our invoice and went to the government on our behalf; this
 * piece of paper is the only proof of it, and without it the deduction is
 * ours to absorb. So the screen is a chase list, not a report: who owes one,
 * how long we have been asking, and one button to ask them all at once.
 */
export default function CrmTdsCertificatesPage() {
  const { me } = useOutletContext<{ me: CrmMe | undefined }>()
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()

  const [state, setState] = useState<'pending' | 'received' | 'all'>('pending')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [member, setMember] = useState('')
  const [picked, setPicked] = useState<Set<string>>(new Set())
  const [composing, setComposing] = useState(false)
  const [channel, setChannel] = useState<'email' | 'note'>('email')
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [nextFollowUp, setNextFollowUp] = useState('')
  const [historyFor, setHistoryFor] = useState<CrmTdsRow | null>(null)

  const { data: masters } = useQuery({ queryKey: ['crm', 'masters'], queryFn: crm.masters })
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'tds', state, query, member],
    queryFn: () => crm.tds.list({
      state,
      search: query || undefined,
      member: member || undefined,
    }),
  })

  const rows = useMemo(() => data?.data ?? [], [data])
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crm', 'tds'] })

  /* What was ticked, and still on screen - a filter change should not send
     letters about rows nobody can see any more. */
  const chosen = useMemo(
    () => rows.filter((r) => picked.has(r.uuid)),
    [rows, picked],
  )
  const clientCount = new Set(chosen.map((r) => r.client?.uuid ?? r.uuid)).size

  const remindMutation = useMutation({
    mutationFn: () => crm.tds.remind(chosen.map((r) => r.uuid), {
      channel,
      subject: subject || undefined,
      body: body || undefined,
      next_follow_up: nextFollowUp || undefined,
    }),
    onSuccess: (res) => {
      toast(res.message, res.data.refused.length ? 'info' : 'success')
      res.data.refused.forEach((line) => toastError(line))
      setComposing(false)
      setPicked(new Set())
      setSubject('')
      setBody('')
      refresh()
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const receivedMutation = useMutation({
    mutationFn: (uuid: string) => crm.tds.received(uuid),
    onSuccess: (res) => { toast(res.message, 'success'); refresh() },
    onError: (err) => toastError(errorMessage(err)),
  })

  /** Open the letter with the wording the server would have used. */
  const compose = async () => {
    setComposing(true)
    setSubject('')
    setBody('')
    try {
      const drafts = await crm.tds.draft(chosen.map((r) => r.uuid))
      // One client: show their letter. Several: leave it blank so the
      // server writes each client their own rather than one letter naming
      // somebody else's invoices to all of them.
      if (drafts.length === 1) {
        setSubject(drafts[0].subject)
        setBody(drafts[0].body)
      }
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  const toggle = (uuid: string) => setPicked((prev) => {
    const next = new Set(prev)
    if (next.has(uuid)) next.delete(uuid)
    else next.add(uuid)
    return next
  })

  const allPicked = rows.length > 0 && rows.every((r) => picked.has(r.uuid))

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">TDS certificates</h1>
          <p className="text-sm text-slate-500">
            {data
              ? <>{data.totals.pending} awaited · {inr(data.totals.tds)} deducted on {data.totals.count} invoice(s)</>
              : 'Tax deducted by clients, and the certificates for it.'}
          </p>
        </div>
        <Button disabled={chosen.length === 0} onClick={compose}>
          <Send className="size-4" />
          Ask {clientCount > 0 ? `${clientCount} client${clientCount === 1 ? '' : 's'}` : ''}
        </Button>
      </div>

      <Card>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <Select value={state} onChange={(e) => { setState(e.target.value as typeof state); setPicked(new Set()) }}>
            <option value="pending">Certificate awaited</option>
            <option value="received">Certificate received</option>
            <option value="all">All with TDS</option>
          </Select>
          <Select value={member} onChange={(e) => setMember(e.target.value)}>
            <option value="">Every salesperson</option>
            {masters?.members.map((m) => <option key={m.uuid} value={m.uuid}>{m.name}</option>)}
          </Select>
          <div className="flex min-w-0 flex-1 items-center gap-2">
            <Input
              className="min-w-0 flex-1"
              placeholder="Invoice number, company or contact…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && setQuery(search)}
            />
            <Button variant="secondary" onClick={() => setQuery(search)}>
              <Search className="size-4" />
            </Button>
          </div>
        </div>

        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner /></div>
        ) : rows.length === 0 ? (
          <EmptyState
            title={state === 'received' ? 'No certificates recorded yet' : 'Nothing awaited'}
            hint="Invoices where a client deducted tax at source appear here."
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[52rem] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-400 dark:border-slate-800">
                  <th className="py-2 pr-2">
                    <input
                      type="checkbox"
                      aria-label="Choose every row"
                      checked={allPicked}
                      onChange={() => setPicked(allPicked ? new Set() : new Set(rows.map((r) => r.uuid)))}
                    />
                  </th>
                  <th className="py-2 pr-3">Invoice</th>
                  <th className="py-2 pr-3">Client</th>
                  <th className="py-2 pr-3 text-right">Invoice value</th>
                  <th className="py-2 pr-3 text-right">TDS</th>
                  <th className="py-2 pr-3">Chased</th>
                  <th className="py-2 pr-3">Certificate</th>
                  <th className="py-2" />
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.uuid} className="border-b border-slate-50 last:border-0 dark:border-slate-800/60">
                    <td className="py-2 pr-2">
                      <input
                        type="checkbox"
                        aria-label={`Choose ${row.number}`}
                        checked={picked.has(row.uuid)}
                        onChange={() => toggle(row.uuid)}
                      />
                    </td>
                    <td className="py-2 pr-3">
                      <div className="font-medium text-slate-800 dark:text-slate-100">{row.number}</div>
                      <div className="text-[11px] text-slate-400">{row.invoice_date}</div>
                    </td>
                    <td className="py-2 pr-3">
                      <div className="truncate text-slate-700 dark:text-slate-200">{row.client?.company_name ?? '—'}</div>
                      <div className="truncate text-[11px] text-slate-400">
                        {row.client?.email ?? 'no e-mail on file'}
                      </div>
                    </td>
                    <td className="py-2 pr-3 text-right tabular-nums">{inr(row.total, row.currency)}</td>
                    <td className="py-2 pr-3 text-right font-medium tabular-nums text-amber-600">
                      {inr(row.tds, row.currency)}
                    </td>
                    <td className="py-2 pr-3 text-[11px] text-slate-500">
                      {row.chased === 0 ? 'never' : (
                        <>
                          {row.chased}× · {row.last_chased_at?.slice(0, 10)}
                          <div className="text-slate-400">by {row.last_chased_by ?? '—'}</div>
                        </>
                      )}
                    </td>
                    <td className="py-2 pr-3">
                      {row.certificate_at ? (
                        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400">
                          received {row.certificate_at.slice(0, 10)}
                        </span>
                      ) : (
                        <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">
                          awaited
                        </span>
                      )}
                    </td>
                    <td className="py-2">
                      <div className="flex justify-end gap-1">
                        <button
                          title="Chase history"
                          aria-label={`Chase history for ${row.number}`}
                          onClick={() => setHistoryFor(row)}
                          className="rounded p-1.5 text-slate-400 hover:text-brand-600"
                        >
                          <History className="size-4" />
                        </button>
                        <button
                          title={row.certificate_at ? 'Mark as still awaited' : 'Certificate received'}
                          aria-label={row.certificate_at ? 'Mark as still awaited' : 'Certificate received'}
                          onClick={() => receivedMutation.mutate(row.uuid)}
                          className={clsx(
                            'rounded p-1.5',
                            row.certificate_at ? 'text-slate-400 hover:text-amber-600' : 'text-slate-400 hover:text-emerald-600',
                          )}
                        >
                          {row.certificate_at ? <Undo2 className="size-4" /> : <Check className="size-4" />}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {/* The letter, before it goes. */}
      {composing && (
        <Modal
          title={`Ask ${clientCount} client${clientCount === 1 ? '' : 's'} for ${chosen.length} certificate${chosen.length === 1 ? '' : 's'}`}
          onClose={() => setComposing(false)}
          wide
        >
          <div className="space-y-3">
            <p className="rounded-lg bg-slate-100 p-2 text-xs text-slate-500 dark:bg-slate-800">
              {clientCount === 1
                ? 'One letter, listing every invoice ticked for this client.'
                : 'One letter each — every client is told only about their own invoices. Leave the wording blank to let each letter name its own.'}
            </p>

            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <Label>How</Label>
                <Select value={channel} onChange={(e) => setChannel(e.target.value as 'email' | 'note')} className="w-full">
                  <option value="email">Send an e-mail</option>
                  <option value="note">Record that somebody rang</option>
                </Select>
              </div>
              <div>
                <Label>Look again on</Label>
                <Input type="date" value={nextFollowUp} onChange={(e) => setNextFollowUp(e.target.value)} className="w-full" />
              </div>
            </div>

            {channel === 'email' && (
              <div>
                <Label>Subject</Label>
                <Input
                  value={subject}
                  onChange={(e) => setSubject(e.target.value)}
                  placeholder={clientCount === 1 ? '' : 'Left blank — each letter writes its own'}
                  className="w-full"
                />
              </div>
            )}

            <div>
              <Label>{channel === 'email' ? 'Letter' : 'What was said'}</Label>
              <Textarea
                rows={12}
                value={body}
                onChange={(e) => setBody(e.target.value)}
                placeholder={clientCount === 1 ? '' : 'Left blank — each letter writes its own'}
                className="w-full font-mono text-xs"
              />
            </div>

            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="secondary" onClick={() => setComposing(false)}>Cancel</Button>
              <Button disabled={remindMutation.isPending} onClick={() => remindMutation.mutate()}>
                <Mail className="size-4" />
                {remindMutation.isPending ? 'Sending…' : channel === 'email' ? 'Send' : 'Record'}
              </Button>
            </div>
          </div>
        </Modal>
      )}

      {historyFor && (
        <ChaseHistory row={historyFor} onClose={() => setHistoryFor(null)} canSee={!!me} />
      )}
    </div>
  )
}

/** Everything ever said to this client about this certificate. */
function ChaseHistory({ row, onClose, canSee }: { row: CrmTdsRow; onClose: () => void; canSee: boolean }) {
  const { data, isLoading } = useQuery({
    queryKey: ['crm', 'tds-history', row.uuid],
    queryFn: () => crm.tds.history(row.uuid),
    enabled: canSee,
  })

  return (
    <Modal title={`Chase history — ${row.number}`} onClose={onClose} wide>
      {isLoading ? (
        <div className="flex justify-center py-10"><Spinner /></div>
      ) : (data?.data.length ?? 0) === 0 ? (
        <EmptyState title="Never chased" hint="Nobody has asked this client for the certificate yet." />
      ) : (
        <ul className="space-y-3">
          {data!.data.map((entry) => (
            <li key={entry.uuid} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
              <div className="flex flex-wrap items-baseline gap-x-2 text-xs">
                <span className="font-semibold text-slate-700 dark:text-slate-200">
                  {entry.channel === 'note' ? 'Noted' : 'E-mail'}
                  {entry.to_email ? ` to ${entry.to_email}` : ''}
                </span>
                <span className={clsx(
                  'rounded-full px-2 py-0.5 text-[11px] font-medium',
                  entry.status === 'sent' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
                  entry.status === 'failed' && 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400',
                  entry.status === 'logged' && 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
                )}>
                  {entry.status}
                </span>
                <span className="text-slate-400">{entry.by} · {entry.at}</span>
              </div>
              {entry.error && <p className="mt-1 text-xs text-red-500">{entry.error}</p>}
              {entry.body && (
                <p className="mt-1 whitespace-pre-wrap text-xs text-slate-500 dark:text-slate-400">{entry.body}</p>
              )}
            </li>
          ))}
        </ul>
      )}

      {data?.certificate_at && (
        <p className="mt-3 flex items-center gap-1.5 text-xs text-emerald-600">
          <FileCheck2 className="size-3.5" /> Certificate recorded on {data.certificate_at.slice(0, 10)}.
        </p>
      )}
    </Modal>
  )
}
