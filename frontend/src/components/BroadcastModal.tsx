import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Megaphone, X } from 'lucide-react'
import { errorMessage } from '../api/client'
import { broadcasts, connections } from '../api/endpoints'
import { Avatar } from '../lib/avatars'
import { Button, Input, Modal, Textarea } from './ui'
import { useToast } from './Toast'

/** The server's ceiling, repeated so the button can go grey before the send. */
const MAX_RECIPIENTS = 50

/**
 * Write it once, send it to a list, and let it arrive as an ordinary message.
 *
 * The thing this replaces is a group made for an announcement — which is how
 * forty customers end up holding a list of each other's names, and how one
 * "thanks!" reaches all of them. Here nobody is in a room with anybody: each
 * person gets a private message in the thread they already have, and a reply
 * comes back to you alone.
 *
 * The picker searches the whole address book rather than showing the first
 * page of it, and the people already chosen are kept as chips above the
 * results — otherwise typing a second name silently loses the first, which is
 * the classic way a multi-select for a paginated list goes wrong.
 */
export default function BroadcastModal({
  onClose,
  onSent,
}: {
  onClose: () => void
  onSent?: () => void
}) {
  const { toast, toastError } = useToast()
  const [q, setQ] = useState('')
  const [body, setBody] = useState('')
  /** uuid → name, so the chips can be drawn without re-finding the person. */
  const [picked, setPicked] = useState<Map<string, string>>(new Map())

  const { data, isLoading } = useQuery({
    queryKey: ['connections', 'accepted', q],
    queryFn: () => connections.list('accepted', q || undefined),
    staleTime: 30_000,
  })

  const toggle = (uuid: string, name: string) => {
    const next = new Map(picked)
    if (!next.delete(uuid)) {
      if (next.size >= MAX_RECIPIENTS) {
        toastError(`A broadcast can go to at most ${MAX_RECIPIENTS} people at a time.`)

        return
      }
      next.set(uuid, name)
    }
    setPicked(next)
  }

  const send = useMutation({
    mutationFn: () => broadcasts.send([...picked.keys()], body.trim()),
    onSuccess: (result) => {
      /*
       * Refusals are reported, not swallowed.
       *
       * Somebody who has not connected with you, or who takes messages from
       * connections only, is skipped — and a "Sent to 12 people" that quietly
       * meant 12 of the 15 you picked is how an announcement is believed to
       * have gone out when it did not.
       */
      const missed = result.data.refused.length
      toast(
        missed === 0
          ? result.message
          : `${result.message} ${missed} could not be reached: ${result.data.refused.map((r) => r.name).join(', ')}.`,
      )
      onSent?.()
      onClose()
    },
    onError: (err) => toastError(errorMessage(err)),
  })

  const rows = (data?.data ?? []).filter((c) => c.user)

  return (
    <Modal title="New broadcast" onClose={onClose}>
      <div className="space-y-3">
        <p className="flex items-start gap-2 rounded-lg bg-slate-100 p-2 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">
          <Megaphone className="mt-0.5 size-3.5 shrink-0" />
          <span>
            Everyone you pick gets this as a normal private message. They will not see each other,
            and their replies come back to you alone.
          </span>
        </p>

        {picked.size > 0 && (
          <div className="flex flex-wrap gap-1.5">
            {[...picked].map(([uuid, name]) => (
              <button
                key={uuid}
                type="button"
                onClick={() => toggle(uuid, name)}
                className="flex items-center gap-1 rounded-full bg-brand-50 py-1 pl-2.5 pr-1.5 text-xs text-brand-700 hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-300"
              >
                {name}
                <X className="size-3" />
              </button>
            ))}
          </div>
        )}

        <Input
          placeholder="Search your connections…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />

        <div className="max-h-52 space-y-0.5 overflow-y-auto">
          {isLoading ? (
            <p className="py-6 text-center text-sm text-slate-400">Loading…</p>
          ) : rows.length === 0 ? (
            <p className="py-6 text-center text-sm text-slate-400">
              {q ? 'Nobody by that name.' : 'You have no connections yet.'}
            </p>
          ) : (
            rows.map((c) => (
              <label
                key={c.uuid}
                className="flex cursor-pointer items-center gap-2 rounded-lg px-1 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800"
              >
                <input
                  type="checkbox"
                  className="size-4 accent-brand-600"
                  checked={picked.has(c.user!.uuid)}
                  onChange={() => toggle(c.user!.uuid, c.user!.name)}
                />
                <Avatar name={c.user!.name} photoPath={c.user!.photo_path} avatar={c.user!.avatar} size={26} />
                <span className="truncate">{c.user!.name}</span>
              </label>
            ))
          )}
        </div>

        <Textarea
          rows={4}
          placeholder="What do you want to tell them?"
          value={body}
          onChange={(e) => setBody(e.target.value)}
        />

        <div className="flex items-center justify-between gap-2">
          <span className="text-xs text-slate-400">
            {picked.size === 0
              ? 'Nobody picked yet'
              : `${picked.size} of ${MAX_RECIPIENTS}`}
          </span>
          <div className="flex gap-2">
            <Button variant="secondary" onClick={onClose}>Cancel</Button>
            <Button
              disabled={picked.size === 0 || body.trim() === '' || send.isPending}
              onClick={() => send.mutate()}
            >
              {send.isPending ? 'Sending…' : `Send${picked.size ? ` to ${picked.size}` : ''}`}
            </Button>
          </div>
        </div>
      </div>
    </Modal>
  )
}
