import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban, Plus, Search, Trash2, UserPlus } from 'lucide-react'
import { clsx } from 'clsx'
import { mails, type MailContact } from '../../../api/mails'
import { errorMessage } from '../../../api/client'
import { useToast } from '../../../components/Toast'
import { Button, Input, Label, Select, Spinner } from '../../../components/ui'
import { useComposer, useMailView } from './composeStore'
import { mailDate } from './mailUtils'

const card = 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800 sm:p-5'

/**
 * The addresses this person writes to.
 *
 * Gathered as mail goes out and comes in rather than typed up: the name
 * beside an address in a mail header is the name that address answers to,
 * and throwing it away is why people re-type addresses they have used a
 * hundred times.
 *
 * Personal to whoever is reading. A colleague's correspondents are not the
 * company's to browse, so this shows only what this person has exchanged
 * mail with.
 *
 * And kept per mailbox: the people Company Admin writes to are not the
 * people ZMA writes to. The rail's choice decides which book is open, and
 * All mailboxes shows both with each entry marked by where it came from.
 */
export default function CrmMailContactsPage() {
  const queryClient = useQueryClient()
  const { toast, toastError } = useToast()
  const openComposer = useComposer((s) => s.open)
  const [term, setTerm] = useState('')
  const [adding, setAdding] = useState(false)
  const [draft, setDraft] = useState({ email: '', name: '', note: '' })

  const account = useMailView((s) => s.account)
  const { data: accounts } = useQuery({ queryKey: ['mails', 'accounts'], queryFn: mails.accounts })
  const boxes = accounts?.data ?? []
  /*
   * Which book is open, and which one a new address goes into.
   *
   * Reading All mailboxes shows every address; adding one still has to name
   * a mailbox, so it falls back to the first rather than guessing silently.
   */
  const [into, setInto] = useState('')
  const addTo = into || (account !== 'all' ? account : boxes[0]?.uuid) || ''

  const { data, isLoading } = useQuery({
    queryKey: ['mails', 'contacts', term, account],
    queryFn: () => mails.contacts({ q: term || undefined, account }),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['mails', 'contacts'] })

  const run = async (fn: () => Promise<{ message: string }>) => {
    try {
      const res = await fn()
      toast(res.message, 'success')
      refresh()
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  const rows = data?.data ?? []

  return (
    <div className="scroll-pane h-full overflow-y-auto p-4 sm:p-6">
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-white">Addresses</h1>
          <p className="text-sm text-slate-500">
            {data?.total ?? 0} {(data?.total ?? 0) === 1 ? 'address' : 'addresses'}
            {account === 'all' ? ' across every mailbox' : ' in this mailbox'}, kept as you write and receive.
            They are yours alone - nobody else in the company sees them.
          </p>
        </div>
        <Button className="ml-auto" onClick={() => setAdding((a) => !a)}>
          <UserPlus className="size-4" /> Add an address
        </Button>
      </div>

      {adding && (
        <form
          className={clsx(card, 'mb-4 grid gap-3 sm:grid-cols-4')}
          onSubmit={(e) => {
            e.preventDefault()
            if (!draft.email.trim()) return
            void run(async () => {
              const res = await mails.addContact({
                account: addTo,
                email: draft.email.trim(),
                name: draft.name.trim() || undefined,
                note: draft.note.trim() || undefined,
              })
              setDraft({ email: '', name: '', note: '' })
              setAdding(false)

              return res
            })
          }}
        >
          {boxes.length > 1 && (
            <div className="sm:col-span-4">
              <Label>Add it to</Label>
              <Select value={addTo} onChange={(e) => setInto(e.target.value)} className="w-full">
                {boxes.map((b) => <option key={b.uuid} value={b.uuid}>{b.label || b.email}</option>)}
              </Select>
            </div>
          )}
          <div><Label>Email</Label><Input type="email" required value={draft.email} onChange={(e) => setDraft({ ...draft, email: e.target.value })} placeholder="someone@example.com" /></div>
          <div><Label>Name</Label><Input value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} placeholder="Kunal Chaudhari" /></div>
          <div className="sm:col-span-2"><Label>Note (optional)</Label><Input value={draft.note} onChange={(e) => setDraft({ ...draft, note: e.target.value })} placeholder="e.g. accounts contact at BCG" /></div>
          <div className="sm:col-span-4">
            <Button type="submit"><Plus className="size-4" /> Add</Button>
          </div>
        </form>
      )}

      <div className={clsx(card, 'space-y-3')}>
        <div className="flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 dark:bg-slate-800">
          <Search className="size-4 shrink-0 text-slate-400" />
          <input
            value={term}
            onChange={(e) => setTerm(e.target.value)}
            placeholder="Search a name or an address"
            className="min-w-0 flex-1 bg-transparent text-sm outline-none"
          />
        </div>

        {isLoading && <Spinner />}
        {!isLoading && rows.length === 0 && (
          <p className="py-6 text-center text-sm text-slate-400">
            {term ? `Nothing matches "${term}".` : 'Nobody yet. Addresses appear here as you send and receive mail.'}
          </p>
        )}

        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {rows.map((contact) => <ContactRow key={contact.uuid} contact={contact} onRun={run} onWrite={() => openComposer({ mode: 'new', to: [contact.label] })} />)}
        </ul>
      </div>
    </div>
  )
}

/** One correspondent: their name as this person calls them, and what to do next. */
function ContactRow({ contact, onRun, onWrite }: {
  contact: MailContact
  onRun: (fn: () => Promise<{ message: string }>) => Promise<void>
  onWrite: () => void
}) {
  const [name, setName] = useState(contact.name ?? '')
  const [note, setNote] = useState(contact.note ?? '')

  const save = (patch: { name?: string | null; note?: string | null; is_blocked?: boolean }) =>
    onRun(() => mails.saveContact(contact.uuid, patch))

  return (
    <li className={clsx('flex flex-wrap items-center gap-2 py-2', contact.is_blocked && 'opacity-60')}>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-baseline gap-2">
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            onBlur={() => { if (name !== (contact.name ?? '')) void save({ name: name || null }) }}
            placeholder="Add a name"
            className="min-w-0 rounded-lg bg-transparent px-1 py-0.5 text-sm font-medium text-slate-800 outline-none focus:ring-1 focus:ring-slate-300 dark:text-slate-100"
          />
          <span className="truncate text-sm text-slate-500">{contact.email}</span>
        </div>
        <div className="flex flex-wrap items-center gap-2 text-xs text-slate-400">
          <span>{contact.sent_count} sent · {contact.received_count} received</span>
          {contact.last_used_at && <span>· last {mailDate(contact.last_used_at)}</span>}
          <input
            value={note}
            onChange={(e) => setNote(e.target.value)}
            onBlur={() => { if (note !== (contact.note ?? '')) void save({ note: note || null }) }}
            placeholder="Add a note"
            className="min-w-0 flex-1 rounded bg-transparent px-1 py-0.5 outline-none focus:ring-1 focus:ring-slate-300"
          />
        </div>
      </div>

      <div className="flex shrink-0 items-center gap-1">
        <Button size="sm" variant="secondary" onClick={onWrite}>Write</Button>
        <button
          type="button"
          title={contact.is_blocked ? 'Suggest this address again' : 'Keep out of suggestions'}
          aria-label={contact.is_blocked ? 'Suggest again' : 'Keep out of suggestions'}
          onClick={() => void save({ is_blocked: !contact.is_blocked })}
          className={clsx('rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-slate-800', contact.is_blocked ? 'text-amber-600' : 'text-slate-400')}
        >
          <Ban className="size-4" />
        </button>
        <button
          type="button"
          title="Remove"
          aria-label={`Remove ${contact.email}`}
          onClick={() => {
            if (window.confirm(`Remove ${contact.email} from your addresses? Mail from them is untouched.`)) {
              void onRun(() => mails.removeContact(contact.uuid))
            }
          }}
          className="rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10"
        >
          <Trash2 className="size-4" />
        </button>
      </div>
    </li>
  )
}
