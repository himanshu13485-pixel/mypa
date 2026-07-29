import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Plus, Receipt, Trash2 } from 'lucide-react'
import { format } from 'date-fns'
import { clsx } from 'clsx'
import { bills as billsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { Badge, Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Select, Spinner } from '../components/ui'
import { BILL_FREQUENCIES } from '../types'

export default function BillsPage() {
  const queryClient = useQueryClient()
  const [filter, setFilter] = useState<'unpaid' | 'paid' | ''>('unpaid')
  const { data, isLoading } = useQuery({
    queryKey: ['bills', filter],
    queryFn: () => billsApi.list(filter || undefined),
  })
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({
    name: '', category: '', amount: '', due_on: '', repeat_frequency: '',
    payment_account: '', remind_days_before: 3, notes: '',
  })
  const [error, setError] = useState<string | null>(null)

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['bills'] })

  const createMutation = useMutation({
    mutationFn: () =>
      billsApi.create({
        ...form,
        amount: form.amount ? Number(form.amount) : null,
        repeat_frequency: form.repeat_frequency || null,
      }),
    onSuccess: () => {
      invalidate()
      setShowForm(false)
      setForm({ name: '', category: '', amount: '', due_on: '', repeat_frequency: '', payment_account: '', remind_days_before: 3, notes: '' })
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const payMutation = useMutation({
    mutationFn: (uuid: string) => billsApi.pay(uuid),
    onSuccess: (res: { message?: string }) => {
      invalidate()
      if (res.message?.includes('Next occurrence')) {
        // Surface the auto-created next bill.
        alert(res.message)
      }
    },
    onError: (err) => alert(errorMessage(err)),
  })

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="flex items-center gap-2 text-lg font-semibold">
          <Receipt className="size-5 text-brand-600" /> Bills
        </h1>
        <div className="flex gap-2">
          {(['unpaid', 'paid', ''] as const).map((option) => (
            <Button
              key={option || 'all'}
              size="sm"
              variant={filter === option ? 'primary' : 'secondary'}
              onClick={() => setFilter(option)}
            >
              {option === '' ? 'All' : option === 'unpaid' ? 'Unpaid' : 'Paid'}
            </Button>
          ))}
          <Button size="sm" onClick={() => { setError(null); setShowForm(true) }}>
            <Plus className="size-4" /> Add bill
          </Button>
        </div>
      </div>

      {isLoading ? (
        <Spinner />
      ) : !data?.data.length ? (
        <Card>
          <EmptyState title="No bills here" hint="Add recurring bills and get reminded before they're due." />
        </Card>
      ) : (
        <div className="space-y-1.5">
          {data.data.map((bill) => (
            <Card key={bill.uuid} className={clsx('flex items-center gap-3 p-3', bill.is_overdue && 'ring-1 ring-red-300')}>
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 text-sm font-medium">
                  {bill.name}
                  {bill.is_overdue && <Badge value="overdue" />}
                  {bill.status === 'paid' && <Badge value="completed" />}
                  {bill.repeat_frequency && (
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-slate-800">
                      {bill.repeat_frequency.replace('_', '-')}
                    </span>
                  )}
                </p>
                <p className="text-xs text-slate-400">
                  Due {format(new Date(bill.due_on), 'd MMM yyyy')}
                  {bill.amount ? ` · ${bill.currency} ${bill.amount}` : ''}
                  {bill.category ? ` · ${bill.category}` : ''}
                  {bill.group ? ` · ${bill.group.name}` : ''}
                </p>
              </div>
              {bill.status === 'unpaid' && bill.is_own && (
                <Button size="sm" onClick={() => payMutation.mutate(bill.uuid)} disabled={payMutation.isPending}>
                  <CheckCircle2 className="size-3.5" /> Mark paid
                </Button>
              )}
              {bill.is_own && (
                <button
                  className="rounded p-1.5 text-slate-400 hover:text-red-600"
                  onClick={() => {
                    if (confirm(`Delete bill "${bill.name}"?`)) billsApi.remove(bill.uuid).then(invalidate)
                  }}
                >
                  <Trash2 className="size-4" />
                </button>
              )}
            </Card>
          ))}
        </div>
      )}

      {showForm && (
        <Modal title="Add bill" onClose={() => setShowForm(false)}>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              createMutation.mutate()
            }}
            className="space-y-4"
          >
            <ErrorNote message={error} />
            <div>
              <Label>Bill name</Label>
              <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Electricity, Rent, Internet…" required autoFocus />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Amount</Label>
                <Input type="number" step="0.01" min="0" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
              </div>
              <div>
                <Label>Category</Label>
                <Input value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} placeholder="Utilities" />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Due date</Label>
                <Input type="date" value={form.due_on} onChange={(e) => setForm({ ...form, due_on: e.target.value })} required />
              </div>
              <div>
                <Label>Repeats</Label>
                <Select value={form.repeat_frequency} onChange={(e) => setForm({ ...form, repeat_frequency: e.target.value })}>
                  <option value="">One-time</option>
                  {BILL_FREQUENCIES.map((f) => (
                    <option key={f} value={f}>{f.replace('_', '-')}</option>
                  ))}
                </Select>
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Remind me (days before)</Label>
                <Input
                  type="number"
                  min={0}
                  max={60}
                  value={form.remind_days_before}
                  onChange={(e) => setForm({ ...form, remind_days_before: Number(e.target.value) })}
                />
              </div>
              <div>
                <Label>Payment account</Label>
                <Input value={form.payment_account} onChange={(e) => setForm({ ...form, payment_account: e.target.value })} placeholder="HDFC ****1234" />
              </div>
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
              <Button type="submit" disabled={createMutation.isPending}>Add bill</Button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  )
}
