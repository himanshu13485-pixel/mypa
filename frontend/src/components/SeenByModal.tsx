import { useQuery } from '@tanstack/react-query'
import { Check, CheckCheck } from 'lucide-react'
import { chat } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { Modal, Spinner } from './ui'

/**
 * Who has read a group message, and who has not.
 *
 * The tick on a bubble is one bit for the whole room, and it only goes double
 * once the LAST person has read — so a message seen by nine of ten looks
 * exactly like one nobody opened. That is fine as a glance and useless as an
 * answer, because the question people actually ask is "has Priyanshu seen
 * it".
 *
 * Asked when it is opened rather than carried on every bubble: a thread of
 * thirty messages in a group of nine would otherwise haul two hundred and
 * seventy read rows about for a question asked about one of them.
 *
 * "Seen" means they have read the conversation at least as far as this
 * message — the same fact the tick is drawn from, so the two can never say
 * different things.
 */
export default function SeenByModal({ conversationUuid, messageUuid, preview, onClose }: {
  conversationUuid: string
  messageUuid: string
  /** A line of the message, so the panel says which one it is about. */
  preview?: string | null
  onClose: () => void
}) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['seen-by', conversationUuid, messageUuid],
    queryFn: () => chat.seenBy(conversationUuid, messageUuid),
  })

  return (
    <Modal title="Seen by" onClose={onClose}>
      <div className="space-y-3">
        <p className="rounded-xl bg-slate-50 p-2 text-xs text-slate-500 dark:bg-slate-800/60">
          {preview ? preview.slice(0, 140) : 'This message'}
        </p>

        {isLoading ? (
          <div className="flex justify-center py-6"><Spinner /></div>
        ) : error ? (
          <p className="text-sm text-red-500">{errorMessage(error)}</p>
        ) : (
          <>
            <section>
              <h4 className="mb-1 flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <CheckCheck className="size-3.5 text-brand-600" /> Seen · {data?.seen.length ?? 0}
              </h4>
              {data?.seen.length ? (
                <ul className="space-y-1 text-sm">
                  {data.seen.map((p) => (
                    <li key={p.uuid} className="flex items-baseline justify-between gap-2">
                      <span className="truncate">{p.name}</span>
                      {/* When they last read the thread, which for a message
                          they have seen is the nearest honest answer to
                          "when" — the row remembers the conversation, not the
                          bubble. */}
                      {p.at && <span className="shrink-0 text-[11px] text-slate-400">{p.at.slice(0, 16).replace('T', ' ')}</span>}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-slate-400">Nobody yet.</p>
              )}
            </section>

            {!!data?.pending.length && (
              <section>
                <h4 className="mb-1 flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <Check className="size-3.5" /> Not yet · {data.pending.length}
                </h4>
                <ul className="space-y-1 text-sm text-slate-500">
                  {data.pending.map((p) => <li key={p.uuid} className="truncate">{p.name}</li>)}
                </ul>
              </section>
            )}
          </>
        )}
      </div>
    </Modal>
  )
}
