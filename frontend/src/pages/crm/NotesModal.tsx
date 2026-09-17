import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { X } from 'lucide-react'
import type { CrmNote } from '../../api/crm'
import { errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Button, Label, Modal, Textarea } from '../../components/ui'

/**
 * Reading and writing the remarks kept beside a figure.
 *
 * The same panel for the P&L and for the payroll, because it is the same
 * act: a number on a screen that somebody will ask about next month, and
 * the room to answer once.
 *
 * Adding never overwrites. An explanation is not a field owned by whoever
 * typed last - two people may each have something to say about the same
 * month, and both are worth keeping.
 *
 * The list is passed in rather than kept here, so a note written or removed
 * appears the moment the page's own query comes back.
 */
export default function NotesModal({ title, subtitle, notes, placeholder, hint, onAdd, onRemove, onClose }: {
  title: string
  subtitle?: string
  notes: CrmNote[]
  placeholder?: string
  /** Who will and will not read these, when that is not obvious. */
  hint?: string
  onAdd: (body: string) => Promise<unknown>
  onRemove: (id: number) => Promise<unknown>
  onClose: () => void
}) {
  const { toastError } = useToast()
  const [body, setBody] = useState('')

  const save = useMutation({
    mutationFn: () => onAdd(body.trim()),
    onSuccess: () => setBody(''),
    onError: (err) => toastError(errorMessage(err)),
  })

  const remove = useMutation({
    mutationFn: (id: number) => onRemove(id),
    onError: (err) => toastError(errorMessage(err)),
  })

  return (
    <Modal title={`Notes — ${title}`} onClose={onClose}>
      <div className="space-y-3">
        {subtitle && <p className="text-xs text-slate-500">{subtitle}</p>}

        {notes.length === 0 ? (
          <p className="text-sm text-slate-400">Nothing written here yet.</p>
        ) : (
          <ul className="space-y-2">
            {notes.map((n) => (
              <li key={n.id} className="flex items-start justify-between gap-2 rounded-xl bg-slate-50 p-2.5 text-sm dark:bg-slate-800/60">
                <div className="min-w-0">
                  <p className="whitespace-pre-wrap break-words text-slate-700 dark:text-slate-200">{n.body}</p>
                  <p className="mt-0.5 text-[11px] text-slate-400">{n.author} · {n.at.slice(0, 16).replace('T', ' ')}</p>
                </div>
                <button
                  onClick={() => { if (confirm('Remove this note?')) remove.mutate(n.id) }}
                  aria-label="Remove note"
                  className="shrink-0 rounded p-1 text-slate-300 hover:text-red-500"
                >
                  <X className="size-3.5" />
                </button>
              </li>
            ))}
          </ul>
        )}

        <div>
          <Label>Add a note</Label>
          <Textarea
            rows={3}
            autoFocus
            value={body}
            maxLength={2000}
            placeholder={placeholder ?? 'Anything worth remembering…'}
            onChange={(e) => setBody(e.target.value)}
          />
          {hint && <p className="mt-1 text-[11px] text-slate-400">{hint}</p>}
        </div>

        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>Close</Button>
          <Button onClick={() => save.mutate()} disabled={!body.trim() || save.isPending}>
            {save.isPending ? 'Saving…' : 'Save note'}
          </Button>
        </div>
      </div>
    </Modal>
  )
}
