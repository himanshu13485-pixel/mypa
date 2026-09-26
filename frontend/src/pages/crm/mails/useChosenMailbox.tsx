import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { mails, type MailAccountInfo } from '../../../api/mails'
import { Label, Select } from '../../../components/ui'
import { useMailView } from './composeStore'

/**
 * Which mailbox a settings screen is about.
 *
 * Signatures, auto-replies, forwarding and archives all belong to one
 * mailbox, and stacking a card for every mailbox on one screen made two
 * mailboxes read as one long form - somebody changing a signature had to
 * count cards to be sure which mailbox they were editing. Each screen now
 * works on one mailbox, chosen here and remembered as the rail's choice, so
 * the whole module agrees on which mailbox is in front of you.
 */
export function useChosenMailbox(): {
  boxes: MailAccountInfo[]
  chosen: MailAccountInfo | null
  loading: boolean
  picker: ReactNode
} {
  const { account, setAccount } = useMailView()
  const { data, isLoading } = useQuery({ queryKey: ['mails', 'accounts'], queryFn: mails.accounts })
  // The rail's choice when it names one; a local choice otherwise, so
  // reading All mailboxes still leaves one mailbox to configure.
  const [local, setLocal] = useState('')

  const boxes = data?.data ?? []
  const wanted = local || (account !== 'all' ? account : '') || boxes[0]?.uuid || ''
  const chosen = boxes.find((b) => b.uuid === wanted) ?? boxes[0] ?? null

  const picker = boxes.length > 1 ? (
    <div className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800 sm:p-5">
      <Label>Mailbox</Label>
      <Select
        value={chosen?.uuid ?? ''}
        onChange={(e) => {
          setLocal(e.target.value)
          // Choosing here chooses everywhere: the rail, the folder counts and
          // the labels all follow, rather than quietly disagreeing.
          if (account !== 'all') setAccount(e.target.value)
        }}
        className="w-full sm:max-w-sm"
      >
        {boxes.map((b) => <option key={b.uuid} value={b.uuid}>{b.label ? `${b.label} — ${b.email}` : b.email}</option>)}
      </Select>
      <p className="mt-1 text-xs text-slate-400">
        Each mailbox keeps its own settings. This screen is about the one chosen here.
      </p>
    </div>
  ) : null

  return { boxes, chosen, loading: isLoading, picker }
}
